<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/** Google sign-in that must not go through; the reason becomes the SPA's ?error= code. */
final class GoogleSignInRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Google sign-in refused: {$reason}.");
    }
}
