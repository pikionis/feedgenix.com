<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // Required in the private beta; checked by the invite itself so a missing and a wrong
            // code get the same answer.
            'invite_code' => ['nullable', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
