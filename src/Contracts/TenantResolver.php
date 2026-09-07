<?php

declare(strict_types=1);

namespace Assistly\Channel\Contracts;

use Assistly\Channel\Data\AssistlyCredentials;
use Assistly\Channel\Data\ChannelMode;

/**
 * Maps the host's notion of a tenant onto an Assistly account.
 *
 * The host decides what a tenant is — an organisation, a workspace, a team.
 * The package never guesses, and never reads a credential out of config: two
 * tenants on one installation must be able to point at two different Assistly
 * accounts, which a config file cannot express.
 */
interface TenantResolver
{
    /**
     * Credentials for this tenant, or null when it has not connected an
     * Assistly account yet.
     *
     * Returning null is a normal state, not an error: most tenants of a host
     * will never turn the feature on.
     */
    public function credentials(string $tenantId): ?AssistlyCredentials;

    /**
     * How much Assistly may do for one thread.
     *
     * Resolved per thread rather than per tenant so a single conversation can
     * be silenced — an operator who takes over needs the bot to stop, and
     * nothing else about the tenant should change while that is true.
     */
    public function mode(string $tenantId, string $threadId): ChannelMode;
}
