<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

final class StartUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.(int) config('ingest.limits.max_bytes')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.max' => sprintf('The file is larger than %d MB, the most one import can hold.', intdiv((int) config('ingest.limits.max_bytes'), 1024 ** 2)),
        ];
    }
}
