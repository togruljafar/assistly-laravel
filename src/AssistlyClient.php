<?php

declare(strict_types=1);

namespace Assistly\Channel;

use Assistly\Channel\Data\AssistlyCredentials;
use Assistly\Channel\Data\InboundMessage;
use Assistly\Channel\Exceptions\AssistlyRequestFailed;
use Assistly\Channel\Exceptions\SubscriptionInactive;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The HTTP client for the Assistly Channel API.
 *
 * Everything the contract requires on the wire lives here and nowhere else:
 * the signature, the idempotency key, the timeouts. A caller that builds its
 * own request would eventually build one without a signature, and it would
 * work — right up until someone replayed it.
 *
 * Contract: docs/specs/assistly-channel-v1.md
 */
final readonly class AssistlyClient
{
    public function __construct(
        private HttpFactory $http,
        private AssistlyCredentials $credentials,
        private int $timeoutSeconds = 20,
    ) {}

    /**
     * @param  list<array{externalMessageId: string, direction: string, text: string, sentAt: string}>  $messages
     * @return array{conversationId: string, accepted: int, skipped: int}
     */
    public function prime(
        string $channel,
        string $externalId,
        array $messages,
        string $mode = 'append',
        ?string $contactName = null,
        ?string $contactPhone = null,
    ): array {
        $payload = [
            'channel' => $channel,
            'externalId' => $externalId,
            'mode' => $mode,
            'messages' => $messages,
        ];

        if ($contactName !== null || $contactPhone !== null) {
            $payload['contact'] = array_filter([
                'name' => $contactName,
                'phone' => $contactPhone,
            ], static fn (?string $v): bool => $v !== null);
        }

        /** @var array{conversationId: string, accepted: int, skipped: int} */
        return $this->send('/conversations/prime', $payload);
    }

    /**
     * Record a message for analysis without asking for an answer.
     *
     * @return array{conversationId: string, mode: string}
     */
    public function record(InboundMessage $message): array
    {
        /** @var array{conversationId: string, mode: string} */
        return $this->send('/messages', $message->toPayload());
    }

    /**
     * Ask for an answer.
     *
     * Returns null when Assistly declines to answer — a human has the thread,
     * or a silence rule applies. Null is a normal outcome and the host must
     * send nothing, not fall back to a default reply.
     *
     * @return array{conversationId: string, answer: string, language: string, operatorMode: bool, handedOff: bool}|null
     */
    public function reply(InboundMessage $message): ?array
    {
        $response = $this->request('/messages/reply', $message->toPayload());

        if ($response->status() === 204) {
            return null;
        }

        /** @var array{conversationId: string, answer: string, language: string, operatorMode: bool, handedOff: bool} */
        return $this->decode($response, '/messages/reply');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $path, array $payload): array
    {
        return $this->decode($this->request($path, $payload), $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(string $path, array $payload): Response
    {
        // Encoded once and signed as encoded. Re-serialising for the signature
        // would sign a different byte string than the one sent whenever key
        // order or escaping differed by so much as a slash.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new AssistlyRequestFailed('Request payload could not be encoded as JSON.');
        }

        $timestamp = (string) time();

        $response = $this->http
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->credentials->apiToken,
                'X-Assistly-Timestamp' => $timestamp,
                'X-Assistly-Signature' => 'sha256='.hash_hmac(
                    'sha256',
                    $timestamp.'.'.$body,
                    $this->credentials->signingSecret,
                ),
                // A retry after a timeout must not become a second answer, and
                // must not be billed twice.
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->timeout($this->timeoutSeconds)
            ->withBody($body, 'application/json')
            ->post($this->url($path));

        $this->assertUsable($response, $path);

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim($this->credentials->baseUrl, '/').'/api/channels'.$path;
    }

    /**
     * Turn the states a host must act on into distinct exceptions.
     *
     * A lapsed subscription is not a transient failure and must never be
     * retried: retrying it burns the queue and delays nothing into working.
     */
    private function assertUsable(Response $response, string $path): void
    {
        if ($response->successful() || $response->status() === 204) {
            return;
        }

        $code = (string) ($response->json('error') ?? 'UNKNOWN');

        if ($response->status() === 402) {
            throw new SubscriptionInactive(
                $code,
                (string) ($response->json('message') ?? 'Assistly declined: '.$code),
            );
        }

        throw new AssistlyRequestFailed(
            sprintf('Assistly %s returned %d (%s)', $path, $response->status(), $code),
            $response->status(),
            $code,
            // Present only on 429. A host that ignores it will simply be
            // refused again; a host that honours it stops hammering.
            $this->retryAfter($response),
        );
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $path): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new AssistlyRequestFailed("Assistly {$path} returned a body that is not JSON.");
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
