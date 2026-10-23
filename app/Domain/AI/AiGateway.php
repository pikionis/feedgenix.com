<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Gateway\CircuitBreaker;
use App\Domain\AI\Gateway\PolicyGate;
use App\Domain\AI\Gateway\ProviderDispatch;
use App\Domain\AI\Gateway\Recorder;
use App\Domain\AI\Gateway\ReservationGate;
use App\Domain\AI\Gateway\Retry;
use App\Domain\AI\Gateway\Router;
use App\Domain\AI\Providers\Providers;
use App\Domain\Credits\Actions\ConsumeReservation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the gateway chain in the order ADR 0004 fixes: PolicyGate → ReservationGate → Router →
 * CircuitBreaker → Retry → Recorder → ProviderDispatch. This is the only place the links are put
 * together; an arch test keeps every other class from using them directly.
 */
final class AiGateway
{
    public static function build(Container $app): AiClient
    {
        $pricing = $app->make(Pricing::class);
        $routes = Routes::fromConfig($pricing, $app->make(Providers::class));

        $chain = new ProviderDispatch($app->make(Providers::class));
        $chain = new Recorder($chain, $pricing, $app->make(ConsumeReservation::class), (int) config('ai.outer_transaction_level', 0));
        $chain = new Retry(
            $chain,
            (int) config('ai.retry.attempts', 4),
            (int) config('ai.retry.base_ms', 1000),
            (int) config('ai.retry.cap_ms', 30_000),
            static fn (int $ceiling): int => random_int(0, max(0, $ceiling)),
        );
        $chain = new CircuitBreaker(
            $chain,
            $app->make(Repository::class),
            (int) config('ai.breaker.threshold', 5),
            (int) config('ai.breaker.cooldown_seconds', 60),
        );
        $chain = new Router($chain, $routes);
        $chain = new ReservationGate($chain, $routes, $pricing);

        return new PolicyGate($chain, $app->make(TenantContext::class), (bool) config('ai.enabled', false));
    }
}
