<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Pricing;
use RuntimeException;

/**
 * The model hosts the gateway can reach, by provider name (gemini, anthropic). A provider without
 * credentials is simply absent, and the router skips its models.
 */
final readonly class Providers
{
    /** @param array<string, AiProvider> $providers */
    public function __construct(private array $providers) {}

    public static function fromConfig(bool $fake, bool $production, ?Pricing $pricing = null): self
    {
        if ($fake) {
            if ($production) {
                throw new RuntimeException('The fake AI provider is refused in production (FEEDGENIX_AI_FAKE).');
            }
            $provider = new FakeAiProvider;

            return new self(array_fill_keys(array_keys((array) config('ai.providers', [])), $provider));
        }

        $providers = [];
        $gemini = (array) config('ai.providers.gemini', []);
        if (is_string($gemini['key'] ?? null) && $gemini['key'] !== '') {
            $providers['gemini'] = new GeminiProvider($gemini['key'], (string) $gemini['base_url'], (int) ($gemini['timeout'] ?? 180));
        }
        $anthropic = (array) config('ai.providers.anthropic', []);
        if (is_string($anthropic['key'] ?? null) && $anthropic['key'] !== '') {
            $providers['anthropic'] = ClaudeProvider::make(
                $anthropic['key'],
                (string) $anthropic['base_url'],
                (int) ($anthropic['timeout'] ?? 180),
                self::efforts(),
                $pricing?->allRefusalFallbacks() ?? [],
            );
        }

        return new self($providers);
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

    /** @return array<string, string> model => effort */
    private static function efforts(): array
    {
        $efforts = [];
        foreach ((array) config('ai.models', []) as $model => $entry) {
            $effort = ((array) $entry)['effort'] ?? null;
            if (is_string($effort)) {
                $efforts[(string) $model] = $effort;
            }
        }

        return $efforts;
    }
}
