<?php

declare(strict_types=1);

namespace App\Support;

/** Links into the SPA (emails, OAuth redirects). */
final class Frontend
{
    /** @param  array<string, string|int>  $query */
    public static function url(string $path = '/', array $query = []): string
    {
        $base = rtrim((string) config('feedgenix.frontend_url'), '/');
        $url = $base.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
