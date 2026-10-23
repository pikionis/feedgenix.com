<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Providers\Providers;

/**
 * The models a request may run on, in order: the requested model, or the purpose's route
 * (config ai.routes). Only priced models on a configured provider are candidates, so an unpriced
 * model is refused before anything leaves (fail closed).
 */
final readonly class Routes
{
    /**
     * @param  array<string, list<string>>  $routes  purpose => model ids
     * @param  array<string, string>  $modelProviders  model id => provider name
     */
    public function __construct(
        private array $routes,
        private array $modelProviders,
        private Pricing $pricing,
        private Providers $providers,
    ) {}

    public static function fromConfig(Pricing $pricing, Providers $providers): self
    {
        $routes = [];
        foreach ((array) config('ai.routes', []) as $purpose => $models) {
            $routes[(string) $purpose] = array_values(array_map(strval(...), (array) $models));
        }
        $modelProviders = [];
        foreach ((array) config('ai.models', []) as $model => $entry) {
            $provider = ((array) $entry)['provider'] ?? null;
            if (is_string($provider)) {
                $modelProviders[(string) $model] = $provider;
            }
        }

        return new self($routes, $modelProviders, $pricing, $providers);
    }

    /**
     * @return non-empty-list<array{model: string, provider: string}>
     *
     * @throws AiTerminal
     */
    public function candidates(AiRequest $request): array
    {
        $models = $request->model !== null ? [$request->model] : ($this->routes[$request->purpose->value] ?? []);

        $priced = array_values(array_filter(
            $models,
            fn (string $model): bool => isset($this->modelProviders[$model]) && $this->pricing->isPriced($model),
        ));
        if ($priced === []) {
            throw new AiTerminal(AiTerminal::UNPRICED_MODEL, "No priced model for [{$request->purpose->value}].");
        }

        $candidates = [];
        foreach ($priced as $model) {
            $provider = $this->modelProviders[$model];
            if ($this->providers->has($provider)) {
                $candidates[] = ['model' => $model, 'provider' => $provider];
            }
        }

        return $candidates !== []
            ? $candidates
            : throw new AiTerminal(AiTerminal::PROVIDER_UNAVAILABLE, "No configured provider for [{$request->purpose->value}].");
    }
}
