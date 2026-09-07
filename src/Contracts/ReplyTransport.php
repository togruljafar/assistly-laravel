<?php

declare(strict_types=1);

namespace Assistly\Channel\Contracts;

use Assistly\Channel\Data\ChannelReply;

/**
 * Delivers an answer over whatever the host talks to customers with.
 *
 * The package deliberately does not know whether that is WhatsApp, SMS or a web
 * widget, and it does not queue the send itself: the host already owns a
 * sending path with its own quotas, rate limits and retry rules, and a second
 * one would compete with it.
 */
interface ReplyTransport
{
    /**
     * Send one reply.
     *
     * Called from a queued job. Throwing marks that job failed and lets the
     * host's own backoff apply — swallowing the error here would report a
     * delivered answer the customer never saw.
     */
    public function deliver(ChannelReply $reply): void;
}
