<?php

declare(strict_types=1);

use App\Domain\AI\Models\EngineCall;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/* Staff area helpers: requests as staff with the second factor proven, and AI calls with a cost. */

function asStaff(User $staff, string $path): TestResponse
{
    // The SPA's calls: same origin, so Sanctum starts the session the two-factor proof lives in.
    return test()->withHeader('Referer', config('app.url').'/app')
        ->actingAs($staff)->withSession(twoFactorPassedSession())->getJson('/api/v1/admin'.$path);
}

/** An AI call as the gateway records it, with a cost no response may ever show. */
function recordAiCall(Tenant $tenant, int $millicredits, string $status = 'ok'): void
{
    tenancy()->run($tenant->id, static fn () => EngineCall::query()->forceCreate([
        'reservation_id' => null, 'purpose' => 'translate', 'provider' => 'gemini', 'model' => 'gemini-test', 'prompt' => 'translate.v1',
        'attempt' => 1, 'item_count' => 4, 'input_tokens' => 1200, 'output_tokens' => 300, 'thinking_tokens' => 50,
        'cached_input_tokens' => 0, 'latency_ms' => 900, 'status' => $status, 'error_code' => null,
        'cost_usd' => '0.0071234567', 'millicredits' => $millicredits,
    ]));
}

/** Every key in a decoded JSON document, at any depth. */
function allKeys(array $document): array
{
    $keys = [];
    $walk = static function (array $node) use (&$walk, &$keys): void {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($document);

    return $keys;
}

function staffPost(User $staff, string $path, array $body): TestResponse
{
    return test()->withHeader('Referer', config('app.url').'/app')
        ->actingAs($staff)->withSession(twoFactorPassedSession())->postJson('/api/v1/admin'.$path, $body);
}
