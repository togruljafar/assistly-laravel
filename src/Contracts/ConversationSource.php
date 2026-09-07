<?php

declare(strict_types=1);

namespace Assistly\Channel\Contracts;

use Assistly\Channel\Data\InboundMessage;
use Psr\Http\Message\StreamInterface;

/**
 * Reads history and attachments out of the host's own store.
 *
 * Used when a thread is first connected and when a message carries a file. The
 * live path does not go through here — a new message arrives as a host event,
 * and asking the host to hand it back would be a round trip for data the caller
 * already has.
 */
interface ConversationSource
{
    /**
     * Recent turns of one thread, oldest first.
     *
     * The caller trims to the window Assistly will actually read, so returning
     * more than a few dozen is wasted work rather than extra context.
     *
     * @return iterable<InboundMessage>
     */
    public function history(string $tenantId, string $threadId, int $limit): iterable;

    /**
     * The bytes of a file attached to one message, or null when the host has
     * not fetched it yet.
     *
     * Null is a real answer and not a failure: a host that mirrors media lazily
     * may still be downloading it, and the caller retries rather than sending
     * the message without its attachment.
     */
    public function attachment(string $tenantId, string $externalMessageId): ?StreamInterface;
}
