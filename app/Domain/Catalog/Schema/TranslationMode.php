<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

/** What happens to a localized field in a target language; the workspace can change it per field. */
enum TranslationMode: string
{
    /** The source text as it is (sizes, model names). */
    case Keep = 'keep';
    case Translate = 'translate';
    /** Rewritten for the channel, from the source facts only (Pro). */
    case Optimize = 'optimize';
}
