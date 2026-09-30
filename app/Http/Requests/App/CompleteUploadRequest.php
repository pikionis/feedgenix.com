<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The SHA-256 of the whole file, when the client computed it: a corrupted upload is caught here.
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ];
    }
}
