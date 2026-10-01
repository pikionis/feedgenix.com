<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

/** Start a chunked upload. Whether the size fits the plan is checked next (402 or 422, FeedSizeLimit). */
final class StartUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.min' => 'This file is empty.',
        ];
    }
}
