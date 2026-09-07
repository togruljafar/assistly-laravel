<?php

declare(strict_types=1);

namespace Assistly\Channel\Data;

use DateTimeImmutable;

/**
 * One message a customer sent, in the shape the contract expects.
 *
 * `externalId` addresses the thread and `externalMessageId` addresses the
 * message; both are the host's own identifiers, and Assistly never invents its
 * own. Deduplication upstream depends on the message id being stable across
 * retries, so a host must not generate a fresh one when re-sending.
 */
final readonly class InboundMessage
{
    /**
     * @param  list<string>  $attachmentIds  Ids returned by the attachments endpoint.
     * @param  array<string, mixed>  $meta    Opaque to Assistly; echoed back on callbacks.
     */
    public function __construct(
        public string $channel,
        public string $externalId,
        public string $externalMessageId,
        public string $text,
        public DateTimeImmutable $sentAt,
        /** inbound when the customer wrote it, outbound when the business did. */
        public string $direction = 'inbound',
        public ?string $contactName = null,
        public ?string $contactPhone = null,
        public array $attachmentIds = [],
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'channel' => $this->channel,
            'externalId' => $this->externalId,
            'externalMessageId' => $this->externalMessageId,
            'text' => $this->text,
            'sentAt' => $this->sentAt->format(DateTimeImmutable::RFC3339),
        ];

        // Contact details are omitted rather than sent as null: the contract
        // treats an absent field as "unchanged", and a null would overwrite a
        // name Assistly already knows with nothing.
        if ($this->contactName !== null || $this->contactPhone !== null) {
            $payload['contact'] = array_filter([
                'name' => $this->contactName,
                'phone' => $this->contactPhone,
            ], static fn (?string $v): bool => $v !== null);
        }

        if ($this->attachmentIds !== []) {
            $payload['attachments'] = $this->attachmentIds;
        }

        if ($this->meta !== []) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
