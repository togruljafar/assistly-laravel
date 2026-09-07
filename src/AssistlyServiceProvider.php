<?php

declare(strict_types=1);

namespace Assistly\Channel;

use Assistly\Channel\Http\AssistlyWebhookController;
use Assistly\Channel\Http\VerifyAssistlySignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the package into a host application.
 *
 * Note what it does *not* do: it binds no implementation of the three
 * contracts. Those are the host's, and a default binding here would let an
 * application boot half-configured and fail at the first message instead of at
 * startup.
 */
final class AssistlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/assistly.php', 'assistly');
    }

    public function boot(): void
    {
        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/assistly.php' => config_path('assistly.php'),
            ], 'assistly-config');
        }
    }

    /**
     * Mount the callback endpoint.
     *
     * The tenant is a path segment, not a body field: the signature
     * authenticates the body, so the body cannot be trusted to choose the key
     * that verifies it.
     */
    private function registerWebhookRoute(): void
    {
        /** @var array{path: string, middleware: list<string>} $config */
        $config = config('assistly.webhook');

        Route::middleware([...$config['middleware'], VerifyAssistlySignature::class])
            ->post($config['path'].'/{tenant}', AssistlyWebhookController::class)
            ->name('assistly.webhook');
    }
}
