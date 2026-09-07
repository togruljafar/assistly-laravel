<?php

declare(strict_types=1);

namespace Assistly\Channel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A conversation moved to a person, or came back from one.
 *
 * The host must stop asking for automatic replies on this thread until it is
 * resumed — otherwise the bot talks over the operator, in front of the customer.
 *
 * `operatorOnline` is separate from the handoff itself and matters: a handoff
 * with nobody online means the customer is now waiting for someone who is not
 * there. A host that shows "an agent will reply shortly" in that state has told
 * them something untrue.
 */
final class ConversationHandedOff
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $externalId,
        public readonly string $reason,
        public readonly bool $operatorOnline,
        /** True when this is the thread coming back to the bot. */
        public readonly bool $resumed = false,
    ) {}
}
