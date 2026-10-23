<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Exceptions\AiTerminal;
use RuntimeException;

/**
 * The model hosts the gateway can reach, by provider name (gemini, anthropic). A provider without
 * credentials is simply absent, and the router skips its models.
 */
final readonly class Providers
{
    /** @param array<string, AiProvider> $providers */
    public function __construct(private array $providers) {}

    public static function fromConfig(bool $fake, bool $production): self
    {
        if ($fake) {
            if ($production) {
                throw new RuntimeException('The fake AI provider is refused in production (FEEDGENIX_AI_FAKE).');
            }
            $provider = new FakeAiProvider;

            return new self(array_fill_keys(array_keys((array) config('ai.providers', [])), $provider));
        }

        // The Gemini and Claude providers arrive with M3 step 2; until then no host is reachable.
        return new self([]);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): AiProvider
    {
        return $this->providers[$name]
            ?? throw new AiTerminal(AiTerminal::PROVIDER_UNAVAILABLE, "Provider [{$name}] is not configured.");
    }
}
