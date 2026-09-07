<?php

declare(strict_types=1);

namespace Assistly\Channel\Http;

use Assistly\Channel\Contracts\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a callback really came from Assistly.
 *
 * The endpoint is public by necessity — Assistly cannot log into the host — so
 * the signature is the only thing between it and anyone who guesses the URL.
 * Without this check, a stranger could post "the operator says your order is
 * cancelled" and the host would send it to a customer over WhatsApp.
 *
 * The tenant is named in the path rather than the body: the body is what is
 * being authenticated, so trusting it to choose the key that authenticates it
 * would be circular.
 */
final readonly class VerifyAssistlySignature
{
    /** Matches the window Assistly enforces on requests in the other direction. */
    private const WINDOW_SECONDS = 300;

    public function __construct(private TenantResolver $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) $request->route('tenant');
        $credentials = $this->tenants->credentials($tenantId);

        if ($credentials === null) {
            // Indistinguishable from a wrong signature on purpose: a 404 here
            // and a 401 there would let anyone enumerate which tenants have
            // connected Assistly.
            abort(401, 'Unauthorized');
        }

        $timestamp = (string) $request->header('X-Assistly-Timestamp', '');
        $provided = (string) $request->header('X-Assistly-Signature', '');

        if ($timestamp === '' || $provided === '') {
            abort(401, 'Unauthorized');
        }

        if (abs(time() - (int) $timestamp) > self::WINDOW_SECONDS) {
            abort(401, 'Unauthorized');
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            $credentials->webhookSecret,
        );

        if (! hash_equals($expected, $provided)) {
            abort(401, 'Unauthorized');
        }

        return $next($request);
    }
}
