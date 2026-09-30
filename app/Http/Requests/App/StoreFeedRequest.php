<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\UrlGuard;
use App\Domain\Ingest\ParseOptions;
use App\Http\Requests\App\Concerns\ValidatesParseOptions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class StoreFeedRequest extends FormRequest
{
    use ValidatesParseOptions;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            // Without a URL the feed is fed by uploads.
            'url' => ['nullable', 'string', 'max:2048', self::feedUrl()],
            ...$this->parseOptionRules(),
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function url(): ?string
    {
        $url = $this->validated('url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function parseOptions(): ParseOptions
    {
        return $this->parseOptionsFrom();
    }

    /** The SSRF guard's rules for the URL itself (addresses are checked when fetching). */
    public static function feedUrl(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                UrlGuard::check($value);
            } catch (FetchFailed $invalid) {
                $fail($invalid->getMessage());
            }
        };
    }
}
