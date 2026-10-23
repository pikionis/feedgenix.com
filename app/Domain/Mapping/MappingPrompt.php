<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\Prompt;
use App\Domain\Catalog\Schema\CanonicalField;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Data\Recipe;

/**
 * The mapping prompt (`map@1`). Each item is one feed column: its name, what its values look like,
 * how full it is and a few example values. A protected column's values are never sent (rule 1):
 * the model judges it by its name. The answer per column is the field it fills, or null.
 */
final readonly class MappingPrompt
{
    public const string NAME = 'map';

    public const string VERSION = '1';

    /** Columns per call: enough context, a bounded answer. */
    public const int BATCH = 40;

    /** Room for the answer and the model's reasoning (both count against the output limit). */
    public const int MAX_OUTPUT_TOKENS = 16_000;

    public function __construct(private CanonicalSchema $schema) {}

    /** @return list<AiRequest> one per batch of columns */
    public function requests(int $tenantId, ?int $reservationId, MappingInput $input, ?string $sourceLocale, ?string $instructions = null): array
    {
        $columns = array_values(array_filter($input->columns, static fn (array $column): bool => $column['fill_rate'] > 0.0));
        $requests = [];
        foreach (array_chunk($columns, self::BATCH) as $batch) {
            $items = array_map(static fn (array $column): AiItem => new AiItem('c'.$column['position'], (string) json_encode([
                'column' => $column['name'],
                'values_look_like' => $column['kind']->value,
                'filled' => (int) round($column['fill_rate'] * 100).'%',
                'examples' => $column['protected'] ? 'withheld (prices, stock, ids, images and weights are never shown)' : array_map(
                    static fn (string $example): string => mb_substr($example, 0, 120),
                    array_slice($column['examples'], 0, 5),
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)), $batch);

            $requests[] = new AiRequest(
                tenantId: $tenantId,
                purpose: AiPurpose::Mapping,
                prompt: new Prompt(self::NAME, self::VERSION, $this->system(), $this->instructions($sourceLocale, $instructions)),
                items: $items,
                valueSchema: $this->valueSchema(),
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                reservationId: $reservationId,
            );
        }

        return $requests;
    }

    /**
     * The answers as proposals: a "direct" column fills its field alone; "part" columns of the same
     * composable field and locale become one recipe, in feed order.
     *
     * @param  array<array-key, mixed>  $values  item id => answer
     * @return list<Proposal>
     */
    public function proposals(MappingInput $input, array $values): array
    {
        $proposals = [];
        $parts = [];
        foreach ($input->columns as $column) {
            $answer = $values['c'.$column['position']] ?? null;
            if (! is_array($answer) || ! is_string($answer['target'] ?? null) || ! $this->schema->has($answer['target'])) {
                continue;
            }
            $target = $answer['target'];
            $locale = is_string($answer['locale'] ?? null) ? $answer['locale'] : null;
            $confidence = max(0.0, min(1.0, (float) ($answer['confidence'] ?? 0)));
            $reason = is_string($answer['reason'] ?? null) ? mb_substr($answer['reason'], 0, 200) : null;

            if (($answer['role'] ?? 'direct') === 'part') {
                $key = "{$target}@{$locale}";
                $parts[$key]['target'] = $target;
                $parts[$key]['locale'] = $locale;
                $parts[$key]['sources'][] = ['column' => $column['name'], 'label' => is_string($answer['label'] ?? null) ? mb_substr(trim($answer['label']), 0, 60) : ''];
                $parts[$key]['confidence'][] = $confidence;
                $parts[$key]['reasons'][] = $reason;

                continue;
            }
            $proposals[] = Proposal::direct($target, $locale, $column['name'], $confidence, $reason, 'ai');
        }

        foreach ($parts as $part) {
            $reason = implode(' ', array_unique(array_filter($part['reasons'])));
            $proposals[] = Proposal::composed(
                $part['target'],
                $part['locale'],
                new Recipe($part['sources']),
                // The recipe is as sure as its least sure part.
                min($part['confidence']),
                $reason === '' ? null : mb_substr($reason, 0, 300),
                'ai',
            );
        }

        return $proposals;
    }

    private function system(): string
    {
        return 'You map the columns of an e-commerce product feed to a fixed set of canonical product fields. '
            .'You are careful: a wrong mapping corrupts a shop\'s catalogue, while an empty one is simply reviewed by a person.';
    }

    private function instructions(?string $sourceLocale, ?string $sellerInstructions): string
    {
        $fields = implode("\n", array_map(
            static fn (CanonicalField $field): string => "- {$field->key}: {$field->label}. {$field->description}"
                .($field->isComposable() ? ' (can be composed from several columns)' : '')
                .($field->isLocalized() ? ' [language-specific]' : ''),
            array_values($this->schema->mappable()),
        ));

        $text = "The canonical fields:\n{$fields}\n\n"
            ."For every column, answer:\n"
            ."- target: the canonical field it fills, or null when no field fits clearly. Leaving a column unmapped is always better than a loose guess.\n"
            ."- role: \"direct\" when the column alone is the field; \"part\" when it is one of several columns that together make a composable field (for example separate width, height and depth columns for dimensions_text, or material and finish columns for material).\n"
            ."- locale: for a language-specific field, the language the column's text is written in, as a code from the allowed list; null for every other field.\n"
            ."- label: for a part, a short label for its values in the feed's language (\"Breite\", \"Material\"); null otherwise.\n"
            ."- confidence: from 0 to 1, honest.\n"
            ."- reason: one short sentence.\n"
            ."Never map a column of prices, stock, ids, barcodes, images or weights to a text field. Map the SKU only to the column that identifies each product.\n"
            .'The feed\'s main language appears to be '.($sourceLocale ?? 'unknown').'.';

        if ($sellerInstructions !== null && trim($sellerInstructions) !== '') {
            $text .= "\n\nThe seller reviewed an earlier mapping and asks for these corrections; follow them unless they break a rule above:\n"
                .mb_substr(trim($sellerInstructions), 0, 2000);
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private function valueSchema(): array
    {
        $targets = [...array_keys($this->schema->mappable()), null];
        $locales = [...array_map(strval(...), array_keys((array) config('languages', []))), null];

        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => ['string', 'null'], 'enum' => $targets],
                'role' => ['type' => 'string', 'enum' => ['direct', 'part']],
                'locale' => ['type' => ['string', 'null'], 'enum' => $locales],
                // No length limits here: a long reason must not fail the whole batch; it is cut on read.
                'label' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['target', 'role', 'locale', 'label', 'confidence', 'reason'],
        ];
    }
}
