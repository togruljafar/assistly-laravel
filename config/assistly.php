<?php

declare(strict_types=1);

return [
    /*
     * Queues the package puts work on.
     *
     * Named finitely and read from config rather than built per tenant. A host
     * running Horizon cannot supervise a queue whose name it did not know at
     * boot — a dynamic name is a job that waits forever with nothing wrong in
     * any log.
     */
    'queues' => [
        'live' => env('ASSISTLY_QUEUE', 'assistly'),
        'backfill' => env('ASSISTLY_BACKFILL_QUEUE', 'assistly-backfill'),
    ],

    /*
     * How many prior turns to send when a thread is first connected.
     *
     * Matches what Assistly reads back. Sending more is storage nobody reads;
     * the host's own inbox is where the full thread lives.
     */
    'prime_window' => (int) env('ASSISTLY_PRIME_WINDOW', 20),

    /*
     * Which channel this host speaks.
     *
     * A short lowercase identifier Assistly stores against every conversation:
     * whatsapp, telegram, sms. It has to be configured rather than assumed —
     * the package was written to be reusable, and a default of 'whatsapp'
     * would make a Telegram host file its threads under the wrong name and
     * only notice when the reporting looked strange.
     */
    'channel' => env('ASSISTLY_CHANNEL', 'whatsapp'),

    'http' => [
        'timeout' => (int) env('ASSISTLY_TIMEOUT', 20),
    ],

    /*
     * Route the host exposes for Assistly's callbacks.
     *
     * Left unregistered by default: a host should mount it deliberately, with
     * its own middleware, rather than discover it appeared.
     */
    'webhook' => [
        'path' => env('ASSISTLY_WEBHOOK_PATH', 'assistly/webhook'),
        /*
         * Throttled by default.
         *
         * The endpoint is public by necessity — Assistly cannot log in — so the
         * signature is what authenticates it, and an unthrottled public endpoint
         * lets anyone force an HMAC and a credential lookup per request. The
         * limit is generous enough that real callbacks, which arrive one per
         * conversation event, never approach it.
         */
        'middleware' => ['api', 'throttle:120,1'],
    ],
];
