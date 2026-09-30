<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Concerns;

use App\Domain\Ingest\Encoding\Encodings;
use App\Domain\Ingest\ParseOptions;
use Illuminate\Validation\Rule;

/** The reading options a customer may set on a feed (null or absent = detect). */
trait ValidatesParseOptions
{
    /** @return array<string, mixed> */
    protected function parseOptionRules(string $prefix = 'parse_options.'): array
    {
        return [
            rtrim($prefix, '.') => ['sometimes', 'array:encoding,delimiter,quoted,xml_item_path'],
            $prefix.'encoding' => ['nullable', 'string', Rule::in(Encodings::names())],
            $prefix.'delimiter' => ['nullable', 'string', Rule::in(ParseOptions::DELIMITERS)],
            $prefix.'quoted' => ['sometimes', 'boolean'],
            $prefix.'xml_item_path' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\s]+(\/[^\/\s]+)*$/'],
        ];
    }

    protected function parseOptionsFrom(string $prefix = 'parse_options'): ParseOptions
    {
        $options = $this->input($prefix, []);

        return ParseOptions::fromArray(is_array($options) ? $options : []);
    }
}
