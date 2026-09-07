<?php

declare(strict_types=1);

namespace Assistly\Channel\Exceptions;

use RuntimeException;

/**
 * Assistly could not be reached, or answered with something the client cannot use.
 *
 * Retryable by default. The two states that are not retryable get their own
 * class — see SubscriptionInactive — because "try again later" is the wrong
 * response to a billing state and the right one to a timeout.
 */
class AssistlyRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'UNKNOWN',
        /** Seconds the server asked us to wait, when it said. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRateLimit(): bool
    {
        return $this->status === 429;
    }

    /**
     * A key rejected for where it was used, rather than for what it is.
     *
     * Worth separating in a host's alerting: this one usually means the server's
     * address changed, which nobody will guess from "request failed".
     */
    public function isAddressRejected(): bool
    {
        return $this->errorCode === 'IP_NOT_ALLOWED';
    }
}
