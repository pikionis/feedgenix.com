<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\Prompt;
use App\Domain\Catalog\Schema\CanonicalSchema;

/**
 * The translation prompt (`translate@1`): instructions built from config/translation_rules.php for
 * the field and the target locale, the texts as items with ids. The legacy prompt numbered the
 * texts and matched answers by the number the model wrote (D12); here each answer comes back
 * under its item's id, and the gateway refuses anything else.
 */
final readonly class TranslationPrompt
{
    public const string NAME = 'translate';

    public const string VERSION = '1';

    public function __construct(private CanonicalSchema $schema) {}

    /** @param array<string, string> $texts item id => source text */
    public function request(int $tenantId, ?int $reservationId, string $field, string $sourceLocale, string $targetLocale, array $texts): AiRequest
    {
        $items = [];
        $bytes = 0;
        foreach ($texts as $id => $text) {
            $items[] = new AiItem((string) $id, $text);
            $bytes += strlen($text);
        }

        return new AiRequest(
            tenantId: $tenantId,
            purpose: AiPurpose::Translation,
            prompt: new Prompt(self::NAME, self::VERSION, $this->system($sourceLocale, $targetLocale), $this->instructions($field, $targetLocale)),
            items: $items,
            valueSchema: ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            maxOutputTokens: UnitSizing::maxOutputTokens($bytes, count($items)),
            reservationId: $reservationId,
        );
    }

    private function system(string $source, string $target): string
    {
        return "You translate e-commerce product data from {$this->name($source)} ({$source}) into {$this->name($target)} ({$target}). "
            .'Your translations are published in shops and marketplaces as they are, so they must be exact, natural and complete.';
    }

    private function instructions(string $field, string $target): string
    {
        $label = $this->schema->has($field) ? $this->schema->get($field)->label : $field;
        $rules = [
            ...(array) config('translation_rules.always', []),
            ...(array) config("translation_rules.fields.{$field}", []),
        ];
        foreach ((array) config('translation_rules.locales', []) as $locale => $localeRules) {
            if (strcasecmp((string) $locale, $target) === 0 || str_starts_with(strtolower($target), strtolower((string) $locale).'-')) {
                $rules = [...$rules, ...(array) $localeRules];
            }
        }

        return "Every item is the {$label} of one product. Translate each item's text and answer it under its id as {\"text\": …}.\n"
            .implode("\n", array_map(static fn (mixed $rule): string => '- '.$rule, $rules));
    }

    private function name(string $locale): string
    {
        $entry = config("languages.{$locale}");

        return is_array($entry) && is_string($entry[0] ?? null) ? $entry[0] : $locale;
    }
}
