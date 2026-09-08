<?php

declare(strict_types=1);

namespace Assistly\Channel\Tests;

use Assistly\Channel\AssistlyClient;
use Assistly\Channel\Data\AssistlyCredentials;
use Assistly\Channel\Data\InboundMessage;
use Assistly\Channel\Exceptions\AssistlyRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * A redirect is a request the host never asked to make.
 *
 * The base URL belongs to a tenant, and in every host that matters a tenant is
 * someone who signed up — so it is attacker-controlled in the only sense that
 * counts. A host can vet the address it stores; it cannot vet the address that
 * address forwards to. Following a redirect turns a checked hostname into an
 * unchecked one, with the request already signed and the internal network on
 * the other side.
 */
final class RedirectTest extends TestCase
{
    public function test_it_does_not_follow_a_redirect(): void
    {
        Http::fake([
            'assistly.test/*' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
            '169.254.169.254/*' => Http::response(['ok' => true], 200),
        ]);

        $client = new AssistlyClient(
            app(HttpFactory::class),
            new AssistlyCredentials(
                baseUrl: 'https://assistly.test',
                apiToken: 'sk_live_test',
                signingSecret: 'whsec_sign',
                webhookSecret: 'whsec_hook',
            ),
        );

        try {
            $client->record(new InboundMessage(
                channel: 'whatsapp',
                externalId: 'thread-1',
                externalMessageId: 'msg-1',
                text: 'Salam',
                sentAt: new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            ));

            $this->fail('A 302 must surface as a failed request, not a followed one.');
        } catch (AssistlyRequestFailed $e) {
            $this->assertSame(302, $e->status);
        }

        Http::assertSentCount(1);
        Http::assertNotSent(
            static fn ($request): bool => str_contains($request->url(), '169.254.169.254'),
        );
    }
}
