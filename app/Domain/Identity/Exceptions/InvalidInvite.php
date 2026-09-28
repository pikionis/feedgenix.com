<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An unknown, used, expired or wrong-address invite code. All four are deliberately
 * indistinguishable (a 422 on invite_code), so the endpoint cannot be used to probe codes.
 */
final class InvalidInvite extends ValidationException
{
    public function __construct()
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('invite_code', 'This invite code is not valid.');

        parent::__construct($validator);
    }
}
