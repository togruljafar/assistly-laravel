<?php

declare(strict_types=1);

namespace Assistly\Channel\Data;

/**
 * An answer to deliver to the customer.
 *
 * `author` matters to the host: an AI answer may be worth marking as such in
 * the transport, and an operator answer must never be, because a person wrote
 * it. `handedOff` tells the host to stop asking for automatic replies on this
 * thread until it is told otherwise.
 */
final readonly class ChannelReply
{
    public function __construct(
        public string $tenantId,
        public string $channel,
        public string $externalId,
        public string $conversationId,
        public string $text,
        public string $language,
        public string $author = 'assistant',
        public bool $handedOff = false,
    ) {}

    public function writtenByPerson(): bool
    {
        return $this->author === 'operator';
    }
}
