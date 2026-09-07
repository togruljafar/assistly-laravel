<?php

declare(strict_types=1);

namespace Assistly\Channel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Assistly reclassified a conversation.
 *
 * Advisory. The host may tag the thread, colour it in an inbox, or ignore it —
 * nothing here asks for an action, and the one classification that does trigger
 * something (anger routing to a person) arrives as ConversationHandedOff
 * instead, because that is a different kind of message.
 *
 * Every field is nullable: a conversation too short to judge is reported with
 * nulls rather than a guess.
 */
final class ConversationInsight
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $externalId,
        public readonly ?string $topic,
        public readonly ?string $sentiment,
        public readonly ?string $intent,
    ) {}
}
