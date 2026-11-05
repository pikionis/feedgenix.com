<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Feeds\Data\SourceChange;
use App\Domain\Ingest\Fetch\UrlGuard;
use App\Domain\Ingest\ParseOptions;
use App\Http\Requests\App\Concerns\ValidatesParseOptions;
use App\Http\Requests\App\Concerns\ValidatesSourceLogin;
use Illuminate\Foundation\Http\FormRequest;

/** Only what is sent changes: an omitted URL, login or server key keeps the stored one (legacy D16). */
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

    public function changesSource(): bool
    {
        return $this->has('url') || $this->has('auth') || $this->has('host_key');
    }

    public function sourceChange(): SourceChange
    {
        return new SourceChange(
            target: $this->has('url') ? UrlGuard::check((string) $this->validated('url')) : null,
            changeLogin: $this->has('auth'),
            login: $this->login(),
            changeHostKey: $this->has('host_key'),
            hostKey: $this->hostKey(),
        );
    }
}
