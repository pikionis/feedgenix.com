<?php

declare(strict_types=1);

use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Evals\CaseScore;
use App\Domain\Mapping\Evals\MappingEval;

/*
 * The mapper against synthetic feeds with known answers (resources/evals/mapping). The release bar:
 * no regression below the baseline, and never a wrong golden field or a protected column in text.
 */

function evalScores(?Closure $suggest = null): array
{
    $eval = resolve(MappingEval::class);

    return array_map(static fn (array $case): CaseScore => $eval->score($case, $suggest), MappingEval::cases());
}

/** An AI that answers each case exactly as its known answer says (the upper bound of the pipeline). */
function oracle(): Closure
{
    $answers = [];
    foreach (MappingEval::cases() as $case) {
        $answers[implode('|', $case['header'])] = $case['expected'];
    }

    return static function (MappingInput $input) use ($answers): array {
        $expected = $answers[implode('|', array_column($input->columns, 'name'))] ?? [];
        $proposals = [];
        foreach ($expected as $key => $column) {
            [$target, $locale] = array_pad(explode('@', (string) $key, 2), 2, null);
            $proposals[] = Proposal::direct($target, $locale, (string) $column, 0.95, 'Oracle.', 'ai');
        }

        return $proposals;
    };
}

it('keeps the free draft at or above its baseline, with no wrong golden field and no protected column in text', function (): void {
    $baseline = json_decode((string) file_get_contents(resource_path('evals/mapping/baseline.json')), true)['free_draft'];
    $total = CaseScore::total(evalScores());

    expect(MappingEval::cases())->toHaveCount(8)
        ->and($total['wrong_golden'])->toBe(0)
        ->and($total['protected_in_text'])->toBe(0)
        ->and($total['precision'])->toBeGreaterThanOrEqual($baseline['precision'])
        ->and($total['recall'])->toBeGreaterThanOrEqual($baseline['recall']);
});

it('accepts every right answer, in every script: a perfect AI scores 100% (legacy D8)', function (): void {
    $scores = evalScores(oracle());

    foreach ($scores as $score) {
        expect($score->mistakes)->toBe([], $score->name)
            ->and($score->recall())->toBe(1.0, $score->name);
    }
});

it('holds the golden rule against an AI that tries to break it', function (): void {
    // Every column proposed into every text field, and every column into every golden field.
    $hostile = static function (MappingInput $input, ?string $locale): array {
        $proposals = [];
        foreach ($input->columns as $column) {
            foreach (['title', 'description', 'color', 'material'] as $text) {
                $proposals[] = Proposal::direct($text, $locale ?? 'en', $column['name'], 0.99, 'Hostile.', 'ai');
            }
            foreach (['gtin', 'price', 'stock_quantity', 'currency'] as $golden) {
                $proposals[] = Proposal::direct($golden, null, $column['name'], 0.99, 'Hostile.', 'ai');
            }
        }

        return $proposals;
    };

    $total = CaseScore::total(evalScores($hostile));

    expect($total['protected_in_text'])->toBe(0);
    foreach (evalScores($hostile) as $score) {
        foreach ($score->mistakes as $mistake) {
            // A golden field can only be filled by a column whose values pass its own check.
            expect($mistake)->not->toMatch('/^(gtin|price|currency) = (Titel|Beschreibung|Title|title|Nom|Τίτλος|ProdName|title_de|Spalte 2)\b/');
        }
    }
});
