<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Illuminate\Support\Str;

/**
 * The address hash inside an email-verification link. The link itself is HMAC-signed; the hash only
 * ties it to the address it was sent to (SHA-256 rather than Laravel's default SHA-1).
 */
final class EmailHash
{
    public static function of(string $email): string
    {
        return hash('sha256', Str::lower($email));
    }
}
