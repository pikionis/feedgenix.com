<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Translation\Models\Language;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full set of target languages (it replaces the current one). Codes are matched to the
 * registry case-insensitively ("PT-br" → "pt-BR") and duplicates collapse.
 */
final class UpdateWorkspaceLanguagesRequest extends FormRequest
{
    /** @var list<string> */
    private array $canonical = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'languages' => ['present', 'array', 'max:50'],
            'languages.*' => ['required', 'string', 'max:16'],
        ];
    }

    /**
     * @return list<callable(Validator):void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $registry = Language::query()->where('active', true)->pluck('code')
                ->mapWithKeys(static fn (string $code): array => [mb_strtolower($code) => $code]);

            $input = $this->input('languages');
            foreach (is_array($input) ? $input : [] as $index => $code) {
                $match = $registry->get(mb_strtolower((string) $code));
                if ($match === null) {
                    $validator->errors()->add("languages.{$index}", "Unknown language [{$code}].");

                    continue;
                }
                $this->canonical[] = $match;
            }

            $this->canonical = array_values(array_unique($this->canonical));
        }];
    }

    /** @return list<string> the registry codes, without duplicates */
    public function languageCodes(): array
    {
        return $this->canonical;
    }
}
