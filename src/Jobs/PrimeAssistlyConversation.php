<?php

declare(strict_types=1);

namespace Assistly\Channel\Jobs;

use Assistly\Channel\AssistlyClient;
use Assistly\Channel\Contracts\ConversationSource;
use Assistly\Channel\Contracts\TenantResolver;
use Assistly\Channel\Exceptions\SubscriptionInactive;
use Assistly\Channel\Events\AssistlyUnavailable;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Teach Assistly what a thread has already said.
 *
 * Run once, when the feature is switched on for a conversation. Without it the
 * first automatic reply answers a customer mid-conversation as though they had
 * just walked in — which reads, correctly, as not having been listened to.
 *
 * On the backfill queue rather than the live one: this is bulk work and must
 * never make a customer wait behind it.
 */
final class PrimeAssistlyConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 120;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $threadId,
    ) {
        $this->onQueue(config('assistly.queues.backfill'));
    }

    public function handle(
        TenantResolver $tenants,
        ConversationSource $source,
        HttpFactory $http,
    ): void {
        $credentials = $tenants->credentials($this->tenantId);
        if ($credentials === null) {
            return;
        }

        $window = (int) config('assistly.prime_window');
        $messages = [];

        foreach ($source->history($this->tenantId, $this->threadId, $window) as $message) {
            $messages[] = [
                'externalMessageId' => $message->externalMessageId,
                'direction' => $message->direction ?? 'inbound',
                'text' => $message->text,
                'sentAt' => $message->sentAt->format(DateTimeImmutable::RFC3339),
            ];
        }

        if ($messages === []) {
            // A thread with nothing in it is not a failure — a customer may
            // have connected before anyone wrote.
            return;
        }

        $client = new AssistlyClient($http, $credentials, (int) config('assistly.http.timeout'));

        try {
            $result = $client->prime((string) config('assistly.channel'), $this->threadId, $messages);
        } catch (SubscriptionInactive $e) {
            event(new AssistlyUnavailable($this->tenantId, $e->errorCode, $e->getMessage()));

            return;
        }

        Log::info('assistly.primed', [
            'tenant' => $this->tenantId,
            'thread' => $this->threadId,
            'accepted' => $result['accepted'] ?? 0,
            // Re-priming is safe and reports what it already had. A non-zero
            // skipped count on a first run means the thread was primed before.
            'skipped' => $result['skipped'] ?? 0,
        ]);
    }
}
