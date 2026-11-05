<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Ingest\ParseOptions;
use App\Http\Requests\App\Concerns\ValidatesParseOptions;
use App\Http\Requests\App\Concerns\ValidatesSourceLogin;
use Illuminate\Foundation\Http\FormRequest;

/** Only what is sent changes: an omitted URL or login keeps the stored one (legacy D16). */
final class UpdateFeedRequest extends FormRequest
{
    use ValidatesParseOptions;
    use ValidatesSourceLogin;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'url' => ['sometimes', 'string', 'max:2048', StoreFeedRequest::feedUrl()],
            ...$this->loginRules(),
            ...$this->parseOptionRules(),
        ];
    }

    public function parseOptions(): ?ParseOptions
    {
        return $this->has('parse_options') ? $this->parseOptionsFrom() : null;
    }
}
