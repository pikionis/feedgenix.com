<?php

declare(strict_types=1);

/*
 * The language registry: BCP-47 codes a workspace can choose as targets (ADR 0003).
 * Seeded into the `languages` table by migration; add a language here and in a new migration.
 * Regional variants carry their own rules (e.g. de-CH writes "ss" for "ß").
 */
return [
    'en' => ['English', 'English', 'ltr'],
    'de' => ['German', 'Deutsch', 'ltr'],
    'de-CH' => ['German (Switzerland)', 'Deutsch (Schweiz)', 'ltr'],
    'de-AT' => ['German (Austria)', 'Deutsch (Österreich)', 'ltr'],
    'fr' => ['French', 'Français', 'ltr'],
    'fr-CH' => ['French (Switzerland)', 'Français (Suisse)', 'ltr'],
    'it' => ['Italian', 'Italiano', 'ltr'],
    'es' => ['Spanish', 'Español', 'ltr'],
    'pt' => ['Portuguese', 'Português', 'ltr'],
    'pt-BR' => ['Portuguese (Brazil)', 'Português (Brasil)', 'ltr'],
    'nl' => ['Dutch', 'Nederlands', 'ltr'],
    'pl' => ['Polish', 'Polski', 'ltr'],
    'cs' => ['Czech', 'Čeština', 'ltr'],
    'sk' => ['Slovak', 'Slovenčina', 'ltr'],
    'sl' => ['Slovenian', 'Slovenščina', 'ltr'],
    'hr' => ['Croatian', 'Hrvatski', 'ltr'],
    'hu' => ['Hungarian', 'Magyar', 'ltr'],
    'ro' => ['Romanian', 'Română', 'ltr'],
    'bg' => ['Bulgarian', 'Български', 'ltr'],
    'el' => ['Greek', 'Ελληνικά', 'ltr'],
    'sv' => ['Swedish', 'Svenska', 'ltr'],
    'da' => ['Danish', 'Dansk', 'ltr'],
    'fi' => ['Finnish', 'Suomi', 'ltr'],
    'nb' => ['Norwegian Bokmål', 'Norsk bokmål', 'ltr'],
    'et' => ['Estonian', 'Eesti', 'ltr'],
    'lv' => ['Latvian', 'Latviešu', 'ltr'],
    'lt' => ['Lithuanian', 'Lietuvių', 'ltr'],
    'tr' => ['Turkish', 'Türkçe', 'ltr'],
    'uk' => ['Ukrainian', 'Українська', 'ltr'],
    'ja' => ['Japanese', '日本語', 'ltr'],
    'ko' => ['Korean', '한국어', 'ltr'],
    'zh-Hans' => ['Chinese (Simplified)', '简体中文', 'ltr'],
    'zh-Hant' => ['Chinese (Traditional)', '繁體中文', 'ltr'],
    'ar' => ['Arabic', 'العربية', 'rtl'],
    'he' => ['Hebrew', 'עברית', 'rtl'],
];
