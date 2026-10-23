<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\Dialects\Dialects;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Export\Models\FeedToken;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A feed URL, as channels pull it (ADR 0007): `/f/{token}/{file}`, or `{file}.gz` for the gzip.
 * The published build only; a request never starts one. Nothing published yet: 503 with
 * Retry-After. An unknown token, a token past its grace period and an unknown file are all 404.
 * A paused profile keeps serving its last build. ETags are the file's SHA-256, so a channel that
 * pulls an unchanged feed gets a 304.
 */
final class FeedFileController
{
    public function __invoke(Request $request, string $token, string $file, TenantContext $context, ArtifactServer $server): Response
    {
        $found = $context->runAsSystem('look up a feed token', static fn (): ?FeedToken => FeedToken::query()->where('token_hash', FeedToken::hashOf($token))
            ->where(static fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first());
        if (! $found instanceof FeedToken) {
            return self::plain(404, 'Not found.');
        }

        return $context->run($found->tenant_id, static function () use ($found, $request, $file, $server): Response {
            $profile = ExportProfile::query()->find($found->profile_id);
            $gzip = str_ends_with($file, '.gz');
            $name = $gzip ? substr($file, 0, -3) : $file;
            if (! $profile instanceof ExportProfile) {
                return self::plain(404, 'Not found.');
            }
            $artifact = ExportArtifact::query()->find($profile->published_artifact_id);
            if (! $artifact instanceof ExportArtifact || $artifact->storage_key === null) {
                return self::plain(503, 'This feed has not been published yet.', ['Retry-After' => (string) config('export.retry_after_seconds', 300)]);
            }
            $entry = $artifact->file($name);
            if ($entry === null) {
                return self::plain(404, 'Not found.');
            }

            // The plain URL sends the gzip when the client takes it: the same build, compressed.
            $compressed = $gzip || str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip');
            $etag = '"'.($compressed ? $entry['gz_sha256'] : $entry['sha256']).'"';
            $headers = [
                'Content-Type' => $gzip ? 'application/gzip' : Dialects::for($profile->dialect)->contentType(),
                'ETag' => $etag,
                'Last-Modified' => $artifact->published_at?->toRfc7231String() ?? '',
                'Cache-Control' => 'public, max-age=60',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if (! $gzip) {
                $headers['Vary'] = 'Accept-Encoding';
            }
            if ($compressed && ! $gzip) {
                $headers['Content-Encoding'] = 'gzip';
            }
            $headers['Content-Disposition'] = ($gzip ? 'attachment' : 'inline').'; filename="'.$file.'"';

            if (in_array($etag, array_map(trim(...), explode(',', (string) $request->header('If-None-Match'))), true)) {
                return response('', 304, array_intersect_key($headers, array_flip(['ETag', 'Cache-Control', 'Vary', 'X-Robots-Tag', 'Last-Modified'])));
            }

            return $server->respond($artifact->storage_key, $compressed ? $name.'.gz' : $name, $headers);
        });
    }

    /** @param array<string, string> $headers */
    private static function plain(int $status, string $message, array $headers = []): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store', ...$headers]);
    }
}
