<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Pricing;
use App\Domain\Credits\Millicredits;

/*
 * The owner's pricing rule (ADR 0005): provider cost × 1.1, exact decimals, every billed token.
 * The prices here are synthetic test values.
 */

function testPricing(array $overrides = [], string $fx = '0.92'): Pricing
{
    config([
        'ai.models' => ['m' => array_merge(['provider' => 'gemini', 'input' => '0.30', 'output' => '2.50', 'cached_input' => '0.075', 'max_input_tokens' => null], $overrides)],
        'ai.usd_to_eur' => $fx,
        'credits.credit_eur' => '0.0001',
    ]);

    return Pricing::fromConfig();
}

/** @param list<string> $texts */
function pricedRequest(array $texts, int $maxOutputTokens = 1000): AiRequest
{
    return new AiRequest(
        tenantId: 1,
        purpose: AiPurpose::Translation,
        prompt: new Prompt('t', '1', 'system', 'instructions'),
        items: array_map(static fn (int $i, string $text): AiItem => new AiItem("id-{$i}", $text), array_keys($texts), $texts),
        valueSchema: ['type' => 'object'],
        maxOutputTokens: $maxOutputTokens,
        reservationId: 1,
    );
}

it('keeps the markup at exactly 1.1, in code', function (): void {
    expect(Pricing::MARKUP)->toBe('1.1');
});

it('bills thinking tokens as output and cached input at its own price', function (): void {
    $pricing = testPricing();

    expect((string) $pricing->costUsd('m', new AiUsage(1_000, 500, 200, 400))->strippedOfTrailingZeros())->toBe('0.00208')
        ->and($pricing->charge('m', new AiUsage(1_000, 500, 200, 400)))->toBe(21_050)
        ->and($pricing->charge('m', new AiUsage(1_000, 500, 200)))->toBe(20_746)
        ->and($pricing->charge('m', new AiUsage))->toBe(0);
});

it('rounds a charge up to a whole millicredit, never down', function (): void {
    // One input token: $0.0000003 × 0.92 × 1.1 = €0.0000003036 = 0.003036 credits = 3.036 millicredits.
    expect(testPricing()->charge('m', new AiUsage(inputTokens: 1)))->toBe(4);
});

it('follows the FX rate', function (): void {
    expect(testPricing(fx: '1')->charge('m', new AiUsage(1_000, 500, 200)))->toBe(22_550);
});

it('bounds the worst case above any usage the same request can report', function (array $texts): void {
    $pricing = testPricing();
    $request = pricedRequest($texts, maxOutputTokens: 800);
    $bytes = strlen('system') + strlen('instructions') + array_sum(array_map(strlen(...), $texts));

    // Even a tokenizer that made every byte a token, using every output token, stays within it.
    expect($pricing->worstCase($request, 'm'))->toBeGreaterThanOrEqual($pricing->charge('m', new AiUsage(inputTokens: $bytes, outputTokens: 800)));
})->with([
    'latin' => [['Wanderschuh aus Leder, wasserdicht']],
    'greek' => [['Αδιάβροχο μπουφάν από ανακυκλωμένο πολυεστέρα']],
    'cjk and emoji' => [['防水ジャケット 🧥🌧️', '登山靴']],
    'quotes and escapes' => [['He said "waterproof" \\ 100% <b>sure</b>', "tab\tnew\nline"]],
]);

it('refuses a model without every price, or a prompt past the size its price holds for', function (): void {
    expect(fn (): int => testPricing(['cached_input' => null])->charge('m', new AiUsage(1)))
        ->toThrow(AiTerminal::class, 'no confirmed price')
        ->and(testPricing(['output' => null])->isPriced('m'))->toBeFalse()
        ->and(fn (): int => testPricing(['max_input_tokens' => 2_000])->worstCase(pricedRequest([str_repeat('x', 500)]), 'm'))
        ->toThrow(AiTerminal::class, 'too large');
});

it('refuses prices and rates that are not exact decimals', function (array $overrides, string $fx): void {
    expect(fn (): Pricing => testPricing($overrides, $fx))->toThrow(InvalidArgumentException::class);
})->with([
    'a float price' => [['input' => 0.3], '0.92'],
    'a negative price' => [['output' => '-1'], '0.92'],
    'not a number' => [['input' => 'cheap'], '0.92'],
    'a zero FX rate' => [[], '0'],
    'a word for FX' => [[], 'par'],
]);

it('converts euros to millicredits exactly and shows credits rounded up', function (): void {
    config(['credits.credit_eur' => '0.0001']);

    expect(Millicredits::fromEuros('10'))->toBe(100_000_000)
        ->and(Millicredits::fromEuros('0.29'))->toBe(2_900_000)
        ->and(Millicredits::fromEuros('0.00000000001'))->toBe(1)
        ->and(Millicredits::toCredits(1))->toBe(1)
        ->and(Millicredits::toCredits(1_000))->toBe(1)
        ->and(Millicredits::toCredits(1_001))->toBe(2);
});
