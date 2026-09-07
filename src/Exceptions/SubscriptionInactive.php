<?php

declare(strict_types=1);

namespace Assistly\Channel\Exceptions;

use RuntimeException;

/**
 * The merchant's Assistly account cannot answer right now, and waiting will not
 * change that.
 *
 * Covers a lapsed subscription and a missing AI credential. Both are
 * configuration, both are the merchant's to fix, and both must stop the host
 * from sending — not slow it down.
 *
 * The distinction matters more here than it looks. Assistly's widget path
 * answers a lapsed account with a neutral sentence so a visitor is never shown
 * billing internals. If the channel path did the same, a host would relay that
 * sentence to a real customer over WhatsApp, once per incoming message, with
 * nothing telling it to stop.
 */
final class SubscriptionInactive extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isMissingCredential(): bool
    {
        return $this->errorCode === 'MISSING_AI_KEY';
    }
}
