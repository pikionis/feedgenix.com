<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A plan limit was reached or a feature is on a higher plan. Rendered as HTTP 402 with everything
 * the SPA's upgrade dialog needs — {error, limit, allowed, used, plan, upgrade_to, message} — and
 * never anything internal (guards, costs, markup).
 */
final class PlanLimitExceeded extends RuntimeException
{
    /** Customer-facing names; the SPA uses the same words. */
    private const array LABELS = [
        'feeds' => ['feed', 'feeds'],
        'languages' => ['target language', 'target languages'],
        'products_per_month' => ['product this month', 'products this month'],
    ];

    private const array FEATURES = [
        'ai_translation' => 'AI translation',
        'ai_agent' => 'Your own AI agent',
        'rag_memory' => 'Private memory of your catalogue',
        'field_instructions' => 'Per-field instructions',
        'model_choice' => 'Choosing the AI model',
        'priority_queue' => 'Priority processing',
        'dedicated_infrastructure' => 'Dedicated infrastructure',
        'api_mcp' => 'API and MCP access',
    ];

    /**
     * @param  array{error: string, limit: string, allowed: int|null, used: int|null, plan: string, upgrade_to: string|null}  $payload
     */
    private function __construct(string $message, private readonly array $payload)
    {
        parent::__construct($message);
    }

    public static function limit(string $plan, string $key, int $allowed, int $used, ?string $upgradeTo): self
    {
        [$one, $many] = self::LABELS[$key] ?? [$key, $key];
        $message = 'Your '.PlanCatalog::name($plan).' plan includes '.number_format($allowed).' '.($allowed === 1 ? $one : $many).'.';

        if ($upgradeTo !== null) {
            $upgraded = PlanCatalog::limit($upgradeTo, $key);
            $message .= ' '.PlanCatalog::name($upgradeTo).' gives you '
                .($upgraded === null ? 'unlimited '.$many : number_format($upgraded).' '.($upgraded === 1 ? $one : $many)).'.';
        }

        return new self($message, [
            'error' => 'plan_limit',
            'limit' => $key,
            'allowed' => $allowed,
            'used' => $used,
            'plan' => $plan,
            'upgrade_to' => $upgradeTo,
        ]);
    }

    public static function feature(string $plan, string $feature, ?string $upgradeTo): self
    {
        $label = self::FEATURES[$feature] ?? $feature;
        $message = $upgradeTo === null
            ? "{$label} isn't available on your plan."
            : "{$label} is part of ".PlanCatalog::name($upgradeTo).'.';

        return new self($message, [
            'error' => 'plan_feature',
            'limit' => $feature,
            'allowed' => null,
            'used' => null,
            'plan' => $plan,
            'upgrade_to' => $upgradeTo,
        ]);
    }

    /**
     * @return array{error: string, limit: string, allowed: int|null, used: int|null, plan: string, upgrade_to: string|null, message: string}
     */
    public function payload(): array
    {
        return $this->payload + ['message' => $this->getMessage()];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload(), 402);
    }
}
