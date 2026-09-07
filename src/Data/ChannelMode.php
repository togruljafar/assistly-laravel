<?php

declare(strict_types=1);

namespace Assistly\Channel\Data;

/**
 * How much Assistly does for one thread.
 *
 * Three states rather than a boolean because the middle one is the whole point:
 * a host can let Assistly read and classify a conversation for weeks before it
 * is allowed to say anything to a customer.
 */
enum ChannelMode: string
{
    /** Nothing leaves the host. No request is made. */
    case Off = 'off';

    /** Messages are recorded and classified. No reply is produced. */
    case Analyze = 'analyze';

    /** Messages are answered. Implies everything Analyze does. */
    case Reply = 'reply';

    public function sendsRequests(): bool
    {
        return $this !== self::Off;
    }

    public function answers(): bool
    {
        return $this === self::Reply;
    }
}
