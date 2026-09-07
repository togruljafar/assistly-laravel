<?php

declare(strict_types=1);

namespace Assistly\Channel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Assistly declined in a way waiting will not fix.
 *
 * The package does not decide what happens next — it cannot know whether the
 * host wants to switch a mode off, raise a banner, or page someone. It reports,
 * and the host acts.
 *
 * The one thing the host must not do is relay anything to the customer. A
 * billing state is not an answer.
 */
final class AssistlyUnavailable
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        /** SUBSCRIPTION_INACTIVE, MISSING_AI_KEY or IP_NOT_ALLOWED. */
        public readonly string $errorCode,
        public readonly string $message,
    ) {}
}
