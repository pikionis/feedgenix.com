<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Models\EngineCall;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\AI\Providers\Providers;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Actions\ReserveCredits;
use App\Domain\Credits\Models\CreditReservation;
use App\Domain\Credits\Models\CreditWallet;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * The AI gateway under test: the real chain with fake providers under it (ADR 0004). The prices are
 * synthetic test values, chosen so that charges are easy to compute by hand.
 */
const TEST_PRICES = [
    'gemini-2.5-pro' => ['provider' => 'gemini', 'input' => '1.25', 'output' => '10', 'cached_input' => '0.3125', 'max_input_tokens' => 200_000, 'priced_on' => 'test'],
    'gemini-2.5-flash' => ['provider' => 'gemini', 'input' => '0.30', 'output' => '2.50', 'cached_input' => '0.075', 'max_input_tokens' => null, 'priced_on' => 'test'],
    'claude-opus-5-5' => ['provider' => 'anthropic', 'input' => '3', 'output' => '15', 'cached_input' => '0.30', 'max_input_tokens' => null, 'priced_on' => 'test'],
    // A refusal fallback target, unpriced unless a test prices it.
    'claude-opus-5' => ['provider' => 'anthropic', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => null, 'priced_on' => null],
];

/**
 * Switch AI on with test prices, put a fake under each provider and fake every sleep.
 *
 * @param  list<string>  $providers
 * @return array<string, FakeAiProvider>
 */
function fakeAi(array $providers = ['gemini', 'anthropic']): array
{
    DB::select('select 1'); // starts the test's transaction, so its level is the baseline
    config([
        'ai.enabled' => true,
        'ai.models' => TEST_PRICES,
        'ai.usd_to_eur' => '0.92',
        'ai.outer_transaction_level' => DB::transactionLevel(),
    ]);
    $fakes = [];
    foreach ($providers as $provider) {
        $fakes[$provider] = new FakeAiProvider;
    }
    app()->instance(Providers::class, new Providers($fakes));
    Sleep::fake();

    return $fakes;
}

/** Change one setting of a model. Model ids contain dots, so config()'s dot notation cannot reach them. */
function setModel(string $model, string $key, mixed $value): void
{
    $models = (array) config('ai.models');
    $models[$model][$key] = $value;
    config(['ai.models' => $models]);
}

/** Credits granted and a reservation held for the workspace, as its own context. */
function reserveFor(Tenant $tenant, int $millicredits = 1_000_000, string $reference = 'unit-1', ?int $grant = null): CreditReservation
{
    return tenancy()->run($tenant->id, static function () use ($millicredits, $reference, $grant): CreditReservation {
        resolve(GrantCredits::class)->handle($grant ?? $millicredits);

        return resolve(ReserveCredits::class)->handle($reference, $millicredits);
    });
}

/** @param array<string, string> $items */
function aiRequest(
    Tenant $tenant,
    ?int $reservationId,
    AiPurpose $purpose = AiPurpose::Translation,
    array $items = ['p-1' => 'Wanderschuh aus Leder', 'p-2' => 'Regenjacke'],
    int $maxOutputTokens = 1000,
    ?string $model = null,
): AiRequest {
    return new AiRequest(
        tenantId: $tenant->id,
        purpose: $purpose,
        prompt: new Prompt('translate', '1', 'You translate product texts.', 'Translate every item into en.'),
        items: array_map(static fn (string $id, string $text): AiItem => new AiItem($id, $text), array_keys($items), array_values($items)),
        valueSchema: ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        maxOutputTokens: $maxOutputTokens,
        reservationId: $reservationId,
        model: $model,
    );
}

/** Call the gateway as the workspace (the only way production code may). */
function generateAs(Tenant $tenant, AiRequest $request): AiResponse
{
    return tenancy()->run($tenant->id, static fn (): AiResponse => resolve(AiClient::class)->generate($request));
}

/** A workspace whose owner may use AI (verified), on a plan. */
function aiWorkspace(): Tenant
{
    return Tenant::factory()->create();
}

/** The typed failure a gateway call ended with. */
function aiFailure(callable $call): AiException
{
    try {
        $call();
    } catch (AiException $failure) {
        return $failure;
    }

    throw new RuntimeException('Expected the gateway to refuse or fail the call.');
}

/** @return list<EngineCall> every recorded attempt of the workspace, oldest first */
function engineCalls(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, static fn (): array => EngineCall::query()->orderBy('id')->get()->all());
}

function reservationOf(Tenant $tenant, int $id): CreditReservation
{
    return tenancy()->run($tenant->id, static fn (): CreditReservation => CreditReservation::query()->findOrFail($id));
}

function walletOf(Tenant $tenant): ?CreditWallet
{
    return tenancy()->run($tenant->id, static fn (): ?CreditWallet => CreditWallet::query()->first());
}
