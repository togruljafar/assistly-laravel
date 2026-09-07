<?php

declare(strict_types=1);

namespace Assistly\Channel\Data;

/**
 * What one tenant needs to talk to Assistly.
 *
 * The signing secret is separate from the API key on purpose. The key says who
 * is calling; the secret proves the request was not replayed. A host that
 * stores only the key can still be impersonated from an allowed address.
 *
 * Both are secrets and neither belongs in a log line — hence the redacted
 * __debugInfo below, which is the only thing standing between a `dd($creds)`
 * during debugging and a credential in a bug report.
 */
final readonly class AssistlyCredentials
{
    public function __construct(
        public string $baseUrl,
        public string $apiToken,
        public string $signingSecret,
        /** Verifies callbacks coming the other way. */
        public string $webhookSecret,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiToken' => '[redacted]',
            'signingSecret' => '[redacted]',
            'webhookSecret' => '[redacted]',
        ];
    }
}
