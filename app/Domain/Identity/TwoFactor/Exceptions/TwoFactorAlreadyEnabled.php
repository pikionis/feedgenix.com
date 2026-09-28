<?php

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Setting up again would replace a working secret; that needs a deliberate reset instead. */
final class TwoFactorAlreadyEnabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Two-factor sign-in is already set up.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
