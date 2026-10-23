<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\Credits\Actions\GrantCredits;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/* The eval command: free by default; with --ai, paid from a workspace through the gateway. */

it('scores the free draft at no cost', function (): void {
    $output = new BufferedOutput;

    expect(Artisan::call('mapping:eval', [], $output))->toBe(0)
        ->and($output->fetch())->toContain('Total: precision 100.0%, recall 82.9%, wrong golden 0, protected in text 0.');
});

it('asks the AI through the gateway, paid from the workspace and settled', function (): void {
    $tenant = aiWorkspace();
    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle(50_000_000));
    $fakes = fakeAi();
    // A model that knows the Greek columns: the free draft finds only the barcode there.
    $greek = ['Κωδικός' => 'sku', 'Τίτλος' => 'title', 'Περιγραφή' => 'description', 'Χρώμα' => 'color'];
    $answer = static function (AiRequest $request) use ($greek): ProviderResult {
        $values = [];
        foreach ($request->items as $item) {
            $column = (string) (json_decode($item->text, true)['column'] ?? '');
            $target = $greek[$column] ?? null;
            $values[$item->id] = ['target' => $target, 'role' => 'direct', 'locale' => $target === null || $target === 'sku' ? null : 'el', 'label' => null, 'confidence' => 0.9, 'reason' => 'Greek column.'];
        }

        return new ProviderResult($values, new AiUsage(inputTokens: 1_000, outputTokens: 200), (string) $request->model);
    };
    $fakes['gemini']->push(...array_fill(0, 8, $answer));
    $output = new BufferedOutput;

    Artisan::call('mapping:eval', ['--ai' => true, '--workspace' => $tenant->slug], $output);

    $text = $output->fetch();
    expect($text)->toMatch('/el-home\s+precision 100\.0%\s+recall\s+71\.4%/')
        ->and(walletOf($tenant)?->reserved)->toBe(0)
        ->and(walletOf($tenant)?->balance)->toBeLessThan(50_000_000)
        ->and(engineCalls($tenant))->toHaveCount(8);
});

it('needs a workspace to pay for --ai', function (): void {
    expect(Artisan::call('mapping:eval', ['--ai' => true], new BufferedOutput))->toBe(2);
});
