<?php

declare(strict_types=1);

namespace Assistly\Channel\Http;

use Assistly\Channel\Contracts\ReplyTransport;
use Assistly\Channel\Data\ChannelReply;
use Assistly\Channel\Events\ConversationHandedOff;
use Assistly\Channel\Events\ConversationInsight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Assistly's callbacks.
 *
 * Delivery is at-least-once, so the same event can arrive twice — a timeout on
 * Assistly's side looks identical to a failure whether or not the host acted on
 * it. Sending a customer the same message twice is a visible fault, so
 * duplicates are dropped here rather than left to the transport.
 */
final readonly class AssistlyWebhookController
{
    /** Long enough to cover Assistly's retry schedule. */
    private const SEEN_TTL = 3600;

    public function __construct(private ReplyTransport $transport) {}

    public function __invoke(Request $request, string $tenant): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $event = (string) ($payload['event'] ?? '');
        $conversationId = (string) ($payload['conversationId'] ?? '');
        $externalId = (string) ($payload['externalId'] ?? '');
        $occurredAt = (string) ($payload['occurredAt'] ?? '');

        if ($event === '' || $conversationId === '' || $externalId === '') {
            return response()->json(['error' => 'MALFORMED'], 422);
        }

        // The contract's own idempotency key: the same event, for the same
        // conversation, at the same instant.
        $seenKey = 'assistly:seen:'.sha1("{$tenant}|{$conversationId}|{$event}|{$occurredAt}");
        if (! Cache::add($seenKey, true, self::SEEN_TTL)) {
            return response()->json(['status' => 'duplicate']);
        }

        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        match ($event) {
            'message.reply' => $this->deliverReply($tenant, $payload, $data),
            'conversation.handoff' => event(new ConversationHandedOff(
                tenantId: $tenant,
                externalId: $externalId,
                reason: (string) ($data['reason'] ?? 'unknown'),
                operatorOnline: (bool) ($data['operatorOnline'] ?? false),
            )),
            'conversation.resumed' => event(new ConversationHandedOff(
                tenantId: $tenant,
                externalId: $externalId,
                reason: 'resumed',
                operatorOnline: false,
                resumed: true,
            )),
            'conversation.insight' => event(new ConversationInsight(
                tenantId: $tenant,
                externalId: $externalId,
                topic: is_string($data['topic'] ?? null) ? $data['topic'] : null,
                sentiment: is_string($data['sentiment'] ?? null) ? $data['sentiment'] : null,
                intent: is_string($data['intent'] ?? null) ? $data['intent'] : null,
            )),
            default => Log::info('assistly.webhook_ignored', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function deliverReply(string $tenant, array $payload, array $data): void
    {
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return;
        }

        $this->transport->deliver(new ChannelReply(
            tenantId: $tenant,
            channel: (string) ($payload['channel'] ?? config('assistly.channel')),
            externalId: (string) $payload['externalId'],
            conversationId: (string) $payload['conversationId'],
            text: $text,
            language: (string) ($data['language'] ?? 'az'),
            // 'operator' when a person typed it. The host uses this to avoid
            // labelling a human's message as coming from a bot.
            author: (string) ($data['author'] ?? 'assistant'),
        ));
    }
}
