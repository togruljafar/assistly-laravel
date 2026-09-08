# assistly/laravel-channel

Route a Laravel application's own conversations through Assistly. Your app keeps
the transport and the message history; Assistly supplies the answer.

The package knows nothing about any host. It lives in the Assistly monorepo
beside the contract it speaks
([`docs/specs/assistly-channel-v1.md`](../../docs/specs/assistly-channel-v1.md)),
so the client and the endpoint it calls change in the same commit.

It is equally deliberate that it does not live inside the first application that
used it. A package grown inside one app ends up shaped like that app, and the
coupling is only discovered when a second one tries to adopt it. `SecondHostTest`
drives the whole flow against a host that is not WhatsApp and does not exist
outside that file; if a fourth thing ever becomes required of a host, that test
stops compiling.

## Install

```bash
composer require assistly/laravel-channel
php artisan vendor:publish --tag=assistly-config
```

Set the channel this application speaks — it is stored against every
conversation and there is no safe default:

```dotenv
ASSISTLY_CHANNEL=whatsapp   # or telegram, sms, …
```

## What you implement

Three interfaces. Nothing else.

### `TenantResolver` — which account, and how much may it do

```php
final class OrgTenants implements TenantResolver
{
    public function credentials(string $tenantId): ?AssistlyCredentials
    {
        // null is a normal state: most tenants never connect Assistly.
    }

    public function mode(string $tenantId, string $threadId): ChannelMode
    {
        // Off | Analyze | Reply. Resolved per thread, so one conversation can
        // be silenced when an operator takes it over without changing anything
        // else about the tenant.
    }
}
```

### `ReplyTransport` — how an answer reaches the customer

```php
final class SendViaGateway implements ReplyTransport
{
    public function deliver(ChannelReply $reply): void
    {
        // Use the sending path you already have, with its quotas and retries.
        // Throwing marks the queued job failed; swallowing the error would
        // report an answer the customer never saw.
    }
}
```

### `ConversationSource` — what was said before

```php
final class History implements ConversationSource
{
    public function history(string $tenantId, string $threadId, int $limit): iterable
    {
        // Oldest first. The caller trims to what Assistly will actually read,
        // so returning more than a few dozen is wasted work.
    }

    public function attachment(string $tenantId, string $externalMessageId): ?StreamInterface
    {
        // null means "not fetched yet", not "failed" — a host that mirrors
        // media lazily is retried rather than sent a message without its file.
    }
}
```

Bind them:

```php
$this->app->singleton(TenantResolver::class, OrgTenants::class);
$this->app->singleton(ReplyTransport::class, SendViaGateway::class);
$this->app->singleton(ConversationSource::class, History::class);
```

## Sending a message

```php
$message = new InboundMessage(
    channel: config('assistly.channel'),
    externalId: $thread->id,          // your thread identifier
    externalMessageId: $incoming->id, // stable across retries — dedup depends on it
    text: $incoming->body,
    sentAt: $incoming->created_at->toDateTimeImmutable(),
);

$mode->answers()
    ? RequestAssistlyReply::dispatch($tenantId, $message)
    : RecordAssistlyMessage::dispatch($tenantId, $message);
```

Both are queued. A slow or unreachable Assistly must never slow down your own
message pipeline — a failed job here is a missing reply, not a missing message.

## The base URL

`AssistlyCredentials::$baseUrl` is whatever the host handed over, and in every
host that matters it came from a tenant — someone who signed up. The package
therefore refuses to follow redirects: a checked hostname that forwards to an
unchecked one is the same request against the host's internal network, signed
and already authenticated. A 302 surfaces as `AssistlyRequestFailed` instead.

What the package cannot do is vet the address itself; it does not know which
hosts a given installation considers legitimate. **A host must validate the URL
before storing it** — require `https`, reject credentials in the authority,
reject addresses that resolve into private, loopback or link-local ranges, or
pin the hostname outright. Validating only on save is not enough on its own:
a name that resolved publicly then can resolve privately later.

## Callbacks

The service provider registers `POST {assistly.webhook.path}/{tenant}` for
replies typed by a human operator, handoffs, and classifications. Every request
is signature-verified against that tenant's webhook secret; the tenant is a path
segment because the body is what is being authenticated and cannot be trusted to
choose its own key.

Listen for what you care about:

```php
Event::listen(ConversationHandedOff::class, PauseTheBot::class);
Event::listen(ConversationInsight::class, TagTheThread::class);
Event::listen(AssistlyUnavailable::class, AlertTheOperator::class);
```

`AssistlyUnavailable` fires when waiting will not help — a lapsed subscription, a
missing AI credential, an address the key does not permit. **Never relay it to a
customer.** A billing state is not an answer, and a host that forwards one sends
it once per incoming message with nothing telling it to stop.

## Queues

Two, both finite and named in config: `assistly` for live traffic and
`assistly-backfill` for priming. Finite because a supervisor cannot watch a
queue whose name it did not know at boot — a dynamically named queue is a job
that waits forever with nothing wrong in any log.

## Development

```bash
composer install
./vendor/bin/phpunit
```

Published to Packagist from a read-only split of this directory. Develop against
the monorepo path; install from the split.
