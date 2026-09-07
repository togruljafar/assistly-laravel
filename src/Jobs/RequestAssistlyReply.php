<?php

declare(strict_types=1);

namespace Assistly\Channel\Jobs;

use Assistly\Channel\AssistlyClient;
use Assistly\Channel\Contracts\ReplyTransport;
use Assistly\Channel\Contracts\TenantResolver;
use Assistly\Channel\Data\ChannelReply;
use Assistly\Channel\Data\InboundMessage;
use Assistly\Channel\Exceptions\AssistlyRequestFailed;
use Assistly\Channel\Exceptions\SubscriptionInactive;
use Assistly\Channel\Events\AssistlyUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask Assistly to answer one message, and deliver whatever comes back.
 *
 * Queued rather than inline for one reason above the others: the host's own
 * message pipeline must not slow down or fail because an external service is
 * slow or down. A failed job here is a missing reply, not a missing message.
 */
final class RequestAssistlyReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly InboundMessage $message,
    ) {
        $this->onQueue(config('assistly.queues.live'));
    }

    public function handle(
        TenantResolver $tenants,
        ReplyTransport $transport,
        HttpFactory $http,
    ): void {
        $mode = $tenants->mode($this->tenantId, $this->message->externalId);
        if (! $mode->answers()) {
            // The mode changed between dispatch and execution — an operator
            // took the thread while this sat in the queue. Answering now would
            // talk over them.
            return;
        }

        $credentials = $tenants->credentials($this->tenantId);
        if ($credentials === null) {
            return;
        }

        $client = new AssistlyClient($http, $credentials, (int) config('assistly.http.timeout'));

        try {
            $answer = $client->reply($this->message);
        } catch (SubscriptionInactive $e) {
            /*
             * Not retried, and deliberately not thrown onward.
             *
             * Waiting does not fix a lapsed subscription, and three attempts
             * with backoff would only delay the alert the operator needs. The
             * event lets the host turn the feature off and tell somebody.
             */
            event(new AssistlyUnavailable($this->tenantId, $e->errorCode, $e->getMessage()));
            Log::warning('assistly.subscription_inactive', [
                'tenant' => $this->tenantId,
                'code' => $e->errorCode,
            ]);

            return;
        } catch (AssistlyRequestFailed $e) {
            if ($e->isAddressRejected()) {
                // The server's address changed. Retrying from the same address
                // will be refused every time.
                event(new AssistlyUnavailable($this->tenantId, $e->errorCode, $e->getMessage()));

                return;
            }

            if ($e->isRateLimit() && $e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        // Assistly chose not to answer. Silence is the instruction.
        if ($answer === null) {
            return;
        }

        $text = trim((string) ($answer['answer'] ?? ''));
        if ($text === '') {
            return;
        }

        $transport->deliver(new ChannelReply(
            tenantId: $this->tenantId,
            channel: $this->message->channel,
            externalId: $this->message->externalId,
            conversationId: (string) ($answer['conversationId'] ?? ''),
            text: $text,
            language: (string) ($answer['language'] ?? 'az'),
            author: 'assistant',
            handedOff: (bool) ($answer['handedOff'] ?? false),
        ));
    }

    public function failed(Throwable $e): void
    {
        Log::error('assistly.reply_job_failed', [
            'tenant' => $this->tenantId,
            'thread' => $this->message->externalId,
            'error' => $e->getMessage(),
        ]);
    }
}
