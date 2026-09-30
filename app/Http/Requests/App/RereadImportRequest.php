<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Ingest\ParseOptions;
use App\Http\Requests\App\Concerns\ValidatesParseOptions;
use Illuminate\Foundation\Http\FormRequest;

/** Read the same file again with corrected options (encoding, delimiter, quoting, XML item). */
final class RereadImportRequest extends FormRequest
{
    use ValidatesParseOptions;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->parseOptionRules('');
    }

    public function parseOptions(): ParseOptions
    {
        return ParseOptions::fromArray($this->validated());
    }
}
