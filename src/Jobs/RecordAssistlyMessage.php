<?php

declare(strict_types=1);

namespace Assistly\Channel\Jobs;

use Assistly\Channel\AssistlyClient;
use Assistly\Channel\Contracts\TenantResolver;
use Assistly\Channel\Data\InboundMessage;
use Assistly\Channel\Events\AssistlyUnavailable;
use Assistly\Channel\Exceptions\SubscriptionInactive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Send one message for analysis, without asking for a reply.
 *
 * This is the whole of "analyze" mode: Assistly learns what the conversation is
 * about, the customer is never written to. Cheap enough to leave on for a long
 * time, which is the point — a host can watch the classification for weeks
 * before letting it act.
 */
final class RecordAssistlyMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly InboundMessage $message,
    ) {
        $this->onQueue(config('assistly.queues.live'));
    }

    public function handle(TenantResolver $tenants, HttpFactory $http): void
    {
        $mode = $tenants->mode($this->tenantId, $this->message->externalId);
        if (! $mode->sendsRequests()) {
            return;
        }

        $credentials = $tenants->credentials($this->tenantId);
        if ($credentials === null) {
            return;
        }

        $client = new AssistlyClient($http, $credentials, (int) config('assistly.http.timeout'));

        try {
            $client->record($this->message);
        } catch (SubscriptionInactive $e) {
            event(new AssistlyUnavailable($this->tenantId, $e->errorCode, $e->getMessage()));
        }
    }
}
