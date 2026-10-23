<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Evals;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Ingest\Profile\ColumnProfiler;
use App\Domain\Mapping\Data\Decision;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Heuristics;
use App\Domain\Mapping\MappingPipeline;
use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * Scores the mapper on synthetic feeds with known answers (resources/evals/mapping/*.json): what it
 * fills against what it should. A wrong golden field and a protected column in a text field are
 * counted on their own: the release bar for both is zero. The AI part is optional: a callback adds
 * its proposals (live through the gateway, or replayed from a recording).
 */
final readonly class MappingEval
{
    public function __construct(
        private CanonicalSchema $schema,
        private MappingPipeline $pipeline,
    ) {}

    /** @return list<array<string, mixed>> every case in the directory, by file name */
    public static function cases(?string $directory = null): array
    {
        $files = glob(($directory ?? resource_path('evals/mapping')).'/*.json') ?: [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            if (basename($file) === 'baseline.json') {
                continue;
            }
            try {
                $case = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException("Eval case [{$file}] is not valid JSON.");
            }
            $cases[] = ['name' => basename($file, '.json'), ...(array) $case];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $case  header, rows, expected (field@locale => column), optional set_locale and ignore
     * @param  (Closure(MappingInput, ?string): list<Proposal>)|null  $suggest  more proposals, e.g. the AI's
     */
    public function score(array $case, ?Closure $suggest = null): CaseScore
    {
        $header = array_map(strval(...), (array) ($case['header'] ?? []));
        $rows = [];
        foreach ((array) ($case['rows'] ?? []) as $row) {
            $rows[] = array_combine($header, array_map(strval(...), array_pad((array) $row, count($header), '')));
        }
        $input = MappingInput::fromProfile(ColumnProfiler::fromConfig()->profile($rows));
        $locale = is_string($case['set_locale'] ?? null) ? $case['set_locale'] : Heuristics::sourceLocale($input);

        $proposals = (new Heuristics($this->schema))->propose($input, $locale);
        if ($suggest instanceof Closure) {
            $proposals = [...$proposals, ...$suggest($input, $locale)];
        }
        $decisions = $this->pipeline->decide($input, $proposals);

        /** @var array<string, string> $expected */
        $expected = array_map(strval(...), (array) ($case['expected'] ?? []));
        $ignored = array_flip(array_map(strval(...), (array) ($case['ignore'] ?? [])));

        $right = $wrong = $golden = $protected = 0;
        $mistakes = [];
        foreach ($decisions as $decision) {
            $got = $this->value($decision);
            if ($got === null || isset($ignored[$decision->key()])) {
                continue;
            }
            if (($expected[$decision->key()] ?? null) === $got) {
                $right++;

                continue;
            }
            $wrong++;
            $mistakes[] = "{$decision->key()} = {$got}".(isset($expected[$decision->key()]) ? " (expected {$expected[$decision->key()]})" : ' (expected empty)');
            if ($this->schema->isGolden($decision->target)) {
                $golden++;
            }
            if ($decision->strategy === 'direct' && $input->isProtected((string) $decision->column) && ! in_array($this->schema->get($decision->target)->class, [FieldClass::Golden, FieldClass::PassThrough], true)) {
                $protected++;
            }
        }

        return new CaseScore((string) $case['name'], count($expected), $right, $wrong, $golden, $protected, $locale, $mistakes);
    }

    /** "Titel", or "compose:Material+Pflege" for a composition; null when left empty. */
    private function value(Decision $decision): ?string
    {
        return match ($decision->strategy) {
            'direct' => $decision->column,
            'compose' => 'compose:'.implode('+', $decision->recipe?->columns() ?? []),
            default => null,
        };
    }
}
