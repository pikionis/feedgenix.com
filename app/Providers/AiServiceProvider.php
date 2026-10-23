<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Pricing;
use App\Domain\AI\Providers\Providers;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** The AI gateway (ADR 0004): the one AiClient, built fresh from config on every resolve. */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Pricing::class, static fn (): Pricing => Pricing::fromConfig());
        // Scoped: one set of provider clients per request or job (and one fake per test).
        $this->app->scoped(Providers::class, static fn (Application $app): Providers => Providers::fromConfig(
            (bool) config('ai.fake', false),
            $app->isProduction(),
        ));
        $this->app->bind(AiClient::class, static fn (Application $app): AiClient => AiGateway::build($app));
    }
}
