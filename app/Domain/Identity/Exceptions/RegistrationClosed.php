<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Sign-up is switched off (config feedgenix.registration = closed). */
final class RegistrationClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sign-up is closed at the moment.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
