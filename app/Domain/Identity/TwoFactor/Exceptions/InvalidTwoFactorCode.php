<?php

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A wrong, expired or already used code: a 422 on `code`. */
final class InvalidTwoFactorCode extends ValidationException
{
    public function __construct(string $message = 'That code did not match. Use the current code from your app.')
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('code', $message);

        parent::__construct($validator);
    }
}
