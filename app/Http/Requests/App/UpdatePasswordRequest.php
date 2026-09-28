<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Change the password (the current one is required), or set a first one for an account that has
 * only ever signed in with Google.
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $hasPassword = $user instanceof User && $user->hasPassword();

        return [
            'current_password' => $hasPassword ? ['required', 'string', 'current_password:web'] : ['prohibited'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
