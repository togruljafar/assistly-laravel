<?php

declare(strict_types=1);

namespace Assistly\Channel\Tests;

use Assistly\Channel\AssistlyClient;
use Assistly\Channel\Contracts\ConversationSource;
use Assistly\Channel\Contracts\ReplyTransport;
use Assistly\Channel\Contracts\TenantResolver;
use Assistly\Channel\Data\AssistlyCredentials;
use Assistly\Channel\Data\ChannelMode;
use Assistly\Channel\Data\ChannelReply;
use Assistly\Channel\Data\InboundMessage;
use Assistly\Channel\Exceptions\SubscriptionInactive;
use Assistly\Channel\Jobs\RequestAssistlyReply;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * The reuse claim, as a test.
 *
 * The plan says a new host connects by writing three classes and registering one
 * route. That is easy to assert in a document and easy to be wrong about, so it
 * is asserted here instead — against a host that is not WahaChat and does not
 * exist outside this file.
 *
 * If the package ever grows a fourth thing a host must provide, this file stops
 * compiling. That is the point.
 */

/** Class one: which account, and how much is it allowed to do. */
final class FakeTenants implements TenantResolver
{
    public function __construct(public ChannelMode $mode = ChannelMode::Reply) {}

    public function credentials(string $tenantId): ?AssistlyCredentials
    {
        return new AssistlyCredentials(
            baseUrl: 'https://assistly.test',
            apiToken: 'sk_live_test',
            signingSecret: 'whsec_sign',
            webhookSecret: 'whsec_hook',
        );
    }

    public function mode(string $tenantId, string $threadId): ChannelMode
    {
        return $this->mode;
    }
}

/** Class two: how an answer reaches the customer. */
final class RecordingTransport implements ReplyTransport
{
    /** @var list<ChannelReply> */
    public array $delivered = [];

    public function deliver(ChannelReply $reply): void
    {
        $this->delivered[] = $reply;
    }
}

/** Class three: what was said before, and where the files are. */
final class ArrayHistory implements ConversationSource
{
    public function history(string $tenantId, string $threadId, int $limit): iterable
    {
        yield new InboundMessage(
            channel: 'sms',
            externalId: $threadId,
            externalMessageId: 'h1',
            text: 'Əvvəlki mesaj',
            sentAt: new DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );
    }

    public function attachment(string $tenantId, string $externalMessageId): ?StreamInterface
    {
        return null;
    }
}

final class SecondHostTest extends TestCase
{
    private function message(): InboundMessage
    {
        return new InboundMessage(
            channel: 'sms',
            externalId: '+994501112233',
            externalMessageId: 'm1',
            text: 'Çatdırılma neçə gün çəkir?',
            sentAt: new DateTimeImmutable('2026-09-06T10:00:00+00:00'),
            contactName: 'Aysel',
        );
    }

    public function test_a_host_needs_only_the_three_contracts(): void
    {
        // Nothing else is bound. If the package reached for a fourth thing, the
        // container would fail to resolve the job below.
        $this->app->instance(TenantResolver::class, new FakeTenants());
        $this->app->instance(ReplyTransport::class, $transport = new RecordingTransport());
        $this->app->instance(ConversationSource::class, new ArrayHistory());

        Http::fake([
            '*/api/channels/messages/reply' => Http::response([
                'conversationId' => 'c1',
                'answer' => 'Bakı daxilində 1–2 iş günü.',
                'language' => 'az',
                'operatorMode' => false,
                'handedOff' => false,
            ]),
        ]);

        (new RequestAssistlyReply('tenant-1', $this->message()))
            ->handle(
                $this->app->make(TenantResolver::class),
                $transport,
                $this->app->make(HttpFactory::class),
            );

        $this->assertCount(1, $transport->delivered);
        $this->assertSame('Bakı daxilində 1–2 iş günü.', $transport->delivered[0]->text);
        // The channel came from the host's own message, not from a default
        // buried in the package.
        $this->assertSame('sms', $transport->delivered[0]->channel);
    }

    public function test_it_signs_every_request(): void
    {
        Http::fake(['*' => Http::response(['conversationId' => 'c1', 'mode' => 'analyze'], 202)]);

        $credentials = (new FakeTenants())->credentials('t');
        self::assertNotNull($credentials);

        $client = new AssistlyClient($this->app->make(HttpFactory::class), $credentials);
        $client->record($this->message());

        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-Assistly-Timestamp')[0] ?? '';
            $signature = $request->header('X-Assistly-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_sign');

            // Signed over the body as sent, not over a re-encoding of it: the
            // two differ the moment key order or escaping does.
            return $signature === $expected
                && $request->hasHeader('Idempotency-Key');
        });
    }

    public function test_a_lapsed_subscription_stops_the_host_rather_than_reaching_a_customer(): void
    {
        $this->app->instance(TenantResolver::class, new FakeTenants());
        $this->app->instance(ReplyTransport::class, $transport = new RecordingTransport());

        Http::fake(['*' => Http::response(['error' => 'SUBSCRIPTION_INACTIVE'], 402)]);

        (new RequestAssistlyReply('tenant-1', $this->message()))
            ->handle(
                $this->app->make(TenantResolver::class),
                $transport,
                $this->app->make(HttpFactory::class),
            );

        // The customer hears nothing. A billing state relayed over the
        // customer's channel is the failure this whole path exists to prevent.
        $this->assertSame([], $transport->delivered);
    }

    public function test_silence_is_an_answer(): void
    {
        $this->app->instance(TenantResolver::class, new FakeTenants());
        $this->app->instance(ReplyTransport::class, $transport = new RecordingTransport());

        // 204: a human has the thread.
        Http::fake(['*' => Http::response(null, 204)]);

        (new RequestAssistlyReply('tenant-1', $this->message()))
            ->handle(
                $this->app->make(TenantResolver::class),
                $transport,
                $this->app->make(HttpFactory::class),
            );

        $this->assertSame([], $transport->delivered);
    }

    public function test_a_thread_switched_off_between_dispatch_and_execution_is_not_answered(): void
    {
        // The operator took over while this sat in the queue.
        $this->app->instance(TenantResolver::class, $tenants = new FakeTenants(ChannelMode::Off));
        $this->app->instance(ReplyTransport::class, $transport = new RecordingTransport());

        Http::fake();

        (new RequestAssistlyReply('tenant-1', $this->message()))
            ->handle($tenants, $transport, $this->app->make(HttpFactory::class));

        Http::assertNothingSent();
        $this->assertSame([], $transport->delivered);
    }

    public function test_the_package_names_no_host(): void
    {
        // The claim, checked rather than asserted in prose.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../src'),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file->getPathname()));
            $this->assertStringNotContainsString('WahaChat', (string) $code, $file->getFilename());
            $this->assertStringNotContainsString('App\\', (string) $code, $file->getFilename());
        }
    }
}
