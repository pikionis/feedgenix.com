<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Catalog\Schema\CanonicalField;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Catalog\Schema\FieldRef;
use App\Domain\Mapping\Data\Decision;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\Data\Verdict;
use InvalidArgumentException;

/**
 * The one way a suggestion becomes part of a mapping, whoever made it: the heuristics, the AI, a
 * refinement or a person's edit (legacy D7: refine and save skipped the validation that generate
 * ran). In order:
 *
 *  1. the field must exist and be mappable; a localized field needs a registry locale;
 *  2. the column must exist; a protected column (prices, stock, ids, images, weights) may only fill
 *     a golden or pass-through field, never text (rule 1);
 *  3. a composition takes only unprotected columns, needs confidence ≥ 0.5, must compose something
 *     on the sample rows, and must not fail validation;
 *  4. the values are checked against the field (FieldValidator): a failure leaves the field empty
 *     rather than wrong, a warning keeps it for the reviewer;
 *  5. one decision per field and locale: a person's word wins; otherwise the most confident, and two
 *     close suggestions for different columns leave the field empty for the reviewer to choose.
 */
final readonly class MappingPipeline
{
    public const float MIN_COMPOSE_CONFIDENCE = 0.5;

    /** Two suggestions closer than this are a tie for a person to break. */
    public const float AMBIGUITY_MARGIN = 0.15;

    public function __construct(private CanonicalSchema $schema) {}

    /**
     * @param  list<Proposal>  $proposals
     * @return list<Decision> in schema order
     */
    public function decide(MappingInput $input, array $proposals): array
    {
        $byKey = [];
        foreach ($proposals as $proposal) {
            $decision = $this->judge($input, $proposal);
            if ($decision instanceof Decision) {
                $byKey[$decision->key()][] = $decision;
            }
        }

        $decisions = [];
        foreach ($byKey as $candidates) {
            $decisions[] = self::settle($candidates);
        }
        $decisions = self::flagSharedColumns($decisions);

        $order = array_flip(array_keys($this->schema->all()));
        usort($decisions, static fn (Decision $a, Decision $b): int => [$order[$a->target] ?? PHP_INT_MAX, (string) $a->locale] <=> [$order[$b->target] ?? PHP_INT_MAX, (string) $b->locale]);

        return $decisions;
    }

    private function judge(MappingInput $input, Proposal $proposal): ?Decision
    {
        if (! $this->schema->has($proposal->target)) {
            return null;
        }
        $field = $this->schema->get($proposal->target);
        if (! $field->class->isMappable()) {
            return null;
        }
        try {
            $locale = FieldRef::of($this->schema, $field->key, $field->isLocalized() ? $proposal->locale : null)->locale;
        } catch (InvalidArgumentException) {
            return null; // a localized field without a known locale cannot be placed
        }

        if ($proposal->column === null && ! $proposal->recipe instanceof Recipe) {
            return new Decision($field->key, $locale, 'none', null, null, null, $proposal->origin, 1.0, null, 'cleared', []);
        }

        return $proposal->recipe instanceof Recipe
            ? $this->judgeComposition($input, $field, $locale, $proposal, $proposal->recipe)
            : $this->judgeColumn($input, $field, $locale, $proposal, (string) $proposal->column);
    }

    private function judgeColumn(MappingInput $input, CanonicalField $field, ?string $locale, Proposal $proposal, string $column): ?Decision
    {
        if (! $input->has($column)) {
            return null;
        }
        if ($input->isProtected($column) && ! in_array($field->class, [FieldClass::Golden, FieldClass::PassThrough], true)) {
            return self::refused($field, $locale, $proposal, $column, 'protected_column', "\"{$column}\" holds prices, stock, ids, images or weights: it never fills a text field.");
        }

        $validation = FieldValidator::validate($field, $input->values($column), $locale);
        if ($validation->verdict === Verdict::Fail) {
            return self::refused($field, $locale, $proposal, $column, 'values_do_not_fit', ($validation->firstMessage() ?? "The values do not fit {$field->label}.").' Left empty.', $validation->issues);
        }
        $status = match ($validation->verdict) {
            Verdict::Pass => 'validated',
            Verdict::Warn => 'needs_input',
            default => 'mapped',
        };

        return new Decision($field->key, $locale, 'direct', $column, null, null, $proposal->origin, $proposal->confidence, $proposal->reason, $status, $validation->issues);
    }

    private function judgeComposition(MappingInput $input, CanonicalField $field, ?string $locale, Proposal $proposal, Recipe $recipe): Decision
    {
        if (! $field->isComposable()) {
            return self::refused($field, $locale, $proposal, null, 'not_composable', "{$field->label} is never composed from several columns.");
        }
        $sources = array_values(array_filter($recipe->sources, static fn (array $source): bool => $input->has($source['column']) && ! $input->isProtected($source['column'])));
        if ($sources === []) {
            return self::refused($field, $locale, $proposal, null, 'no_usable_sources', 'None of the suggested columns can be composed (missing, or prices, ids, images or weights).');
        }
        $recipe = new Recipe($sources, $recipe->separator, $recipe->labelSeparator);
        if ($proposal->origin !== 'manual' && $proposal->confidence < self::MIN_COMPOSE_CONFIDENCE) {
            return self::refused($field, $locale, $proposal, null, 'uncertain', 'The suggestion was too uncertain. Left empty.', [], $recipe);
        }

        $composed = $input->composed($recipe);
        if ($composed === []) {
            return self::refused($field, $locale, $proposal, null, 'composes_nothing', 'The columns are empty in the sample rows. Left empty.', [], $recipe);
        }
        $validation = FieldValidator::validate($field, $composed, $locale);
        if ($validation->verdict === Verdict::Fail) {
            return self::refused($field, $locale, $proposal, null, 'values_do_not_fit', ($validation->firstMessage() ?? "The composed values do not fit {$field->label}.").' Left empty.', $validation->issues, $recipe);
        }

        return new Decision($field->key, $locale, 'compose', null, $recipe, null, $proposal->origin, $proposal->confidence, $proposal->reason, $validation->verdict === Verdict::Warn ? 'needs_input' : 'composed', $validation->issues);
    }

    /**
     * @param  list<array{type: string, severity: string, count: int, message: string}>  $issues
     */
    private static function refused(CanonicalField $field, ?string $locale, Proposal $proposal, ?string $column, string $type, string $message, array $issues = [], ?Recipe $recipe = null): Decision
    {
        $issues = [['type' => $type, 'severity' => 'error', 'count' => 1, 'message' => $message], ...$issues];
        $candidate = $column ?? ($recipe instanceof Recipe ? implode(', ', $recipe->columns()) : null);

        return new Decision($field->key, $locale, 'none', null, $recipe, $candidate, $proposal->origin, $proposal->confidence, $proposal->reason, 'rejected', $issues);
    }

    /** @param non-empty-list<Decision> $candidates */
    private static function settle(array $candidates): Decision
    {
        // A person's decision, filled or cleared, is never overridden.
        foreach (array_reverse($candidates) as $candidate) {
            if ($candidate->origin === 'manual') {
                return $candidate;
            }
        }

        $filled = array_values(array_filter($candidates, static fn (Decision $d): bool => $d->isFilled()));
        if ($filled === []) {
            usort($candidates, static fn (Decision $a, Decision $b): int => $b->confidence <=> $a->confidence);

            return $candidates[0];
        }
        usort($filled, static fn (Decision $a, Decision $b): int => $b->confidence <=> $a->confidence);
        [$best, $runnerUp] = [$filled[0], $filled[1] ?? null];

        $differ = $runnerUp instanceof Decision && ($runnerUp->column !== $best->column || $runnerUp->strategy !== $best->strategy);
        if ($differ && $best->confidence - $runnerUp->confidence < self::AMBIGUITY_MARGIN) {
            $columns = implode(' or ', array_filter([$best->column ?? 'a composition', $runnerUp->column ?? 'a composition']));

            return new Decision($best->target, $best->locale, 'none', null, null, $best->column, $best->origin, $best->confidence, $best->reason, 'rejected', [
                ['type' => 'ambiguous', 'severity' => 'error', 'count' => 2, 'message' => "Both {$columns} could fill it: choose one. Left empty."],
            ]);
        }

        return $best;
    }

    /**
     * A column feeding two fields is allowed (a name can be the title and the meta title) but worth
     * a second look.
     *
     * @param  list<Decision>  $decisions
     * @return list<Decision>
     */
    private static function flagSharedColumns(array $decisions): array
    {
        $uses = [];
        foreach ($decisions as $decision) {
            if ($decision->strategy === 'direct') {
                $uses[(string) $decision->column][] = $decision->key();
            }
        }

        return array_map(static function (Decision $decision) use ($uses): Decision {
            $shared = $decision->strategy === 'direct' ? ($uses[(string) $decision->column] ?? []) : [];
            if (count($shared) < 2 || $decision->origin === 'manual') {
                return $decision;
            }
            $others = implode(', ', array_diff($shared, [$decision->key()]));
            $issues = [...$decision->issues, ['type' => 'shared_column', 'severity' => 'warning', 'count' => count($shared), 'message' => "\"{$decision->column}\" also fills {$others}."]];

            return new Decision($decision->target, $decision->locale, $decision->strategy, $decision->column, $decision->recipe, $decision->candidate, $decision->origin, $decision->confidence, $decision->reason, 'needs_input', $issues);
        }, $decisions);
    }
}
