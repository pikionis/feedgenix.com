<?php

declare(strict_types=1);

/*
 * The translation prompt's rules (prompt translate@1), ported from the legacy field rules without
 * any shop, marketplace or language list. Short directives, one sentence each. Changing them is a
 * prompt change: bump TranslationPrompt::VERSION and run the evals.
 */
return [
    // Every translation, whatever the field.
    'always' => [
        'Translate exactly the same information: no additions, no omissions, no claims the source does not make.',
        'Keep brand names, model names, model numbers and other proper nouns unchanged.',
        'Keep every number, measurement, unit, price, code, size and percentage exactly as written.',
        'Keep HTML tags and their structure exactly: the same tags, in the same order, with the same number of list items and paragraphs.',
        'Write in the target language only; never leave a sentence in the source language.',
        'Prefer plain words a shopper uses over jargon and marketing filler; no exclamation marks, no invented superlatives.',
    ],

    // Per canonical field.
    'fields' => [
        'title' => [
            'Put what the product is first; keep the order brand, product type, key attribute, variant.',
            'Keep it as short as the source: never pad it with detail the source does not have.',
            'Use the capitalisation conventions of the target language for product names.',
        ],
        'description' => [
            'Keep every specification, dimension, material and warranty term from the source.',
            'Keep paragraphs and lists exactly as they are.',
            'Translate measurement labels, never their values ("Länge: 50 cm" becomes "Length: 50 cm").',
        ],
        'category' => [
            'The text is a category path with levels separated by ">", "/" or "\\".',
            'Translate each level on its own, keeping the same number of levels, their order and the separator.',
            'Use the usual e-commerce term for each level in the target language; a brand level stays unchanged.',
        ],
        'color' => [
            'Use the standard single colour name in the target language; keep the order of compound colours.',
            'Keep separators between several colours ("/", "+", "and") as they are.',
        ],
        'size' => [
            'Sizes are usually the same in every language: translate only words, never size codes or numbers.',
        ],
        'material' => [
            'Use the standard material names; keep percentages and the breakdown exactly ("80% cotton, 20% polyester").',
        ],
        'care_instructions' => [
            'Use the usual phrasing of care instructions in the target language; keep temperatures, durations and warnings exactly.',
        ],
        'warranty' => [
            'Use the usual phrasing of warranty terms; keep numbers and durations exactly ("24 months" never becomes "2 years").',
        ],
        'features' => [
            'Each line is "Label: value": translate the labels and descriptive values; keep numbers, sizes, codes and units exactly.',
            'Keep the same number of lines, in the same order.',
        ],
        'other_attributes' => [
            'Each line is "Label: value": translate the labels and descriptive values; keep numbers, sizes, codes and units exactly.',
        ],
        'dimensions_text' => [
            'Translate only the labels (width, height, depth); never touch the numbers, units, separators or their order.',
        ],
    ],

    // Per target locale (BCP-47 prefix match).
    'locales' => [
        'de-CH' => ['Swiss Standard German: write "ss", never "ß".'],
        'fr-CH' => ['Swiss French conventions: use the Swiss number and price formats only where the source has them.'],
        'pt-BR' => ['Brazilian Portuguese spelling and vocabulary.'],
        'zh-Hans' => ['Simplified Chinese characters.'],
        'zh-Hant' => ['Traditional Chinese characters.'],
    ],
];
