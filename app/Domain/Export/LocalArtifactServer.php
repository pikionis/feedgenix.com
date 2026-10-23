<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Export\Contracts\ArtifactServer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export files on the server's private disk, under `exports/`. A build is stored by renaming its
 * scratch directory, which is atomic on one file system: a key holds a complete build or nothing.
 */
final class LocalArtifactServer implements ArtifactServer
{
    private const string ROOT = 'exports';

    public function scratch(): string
    {
        $relative = self::ROOT.'/scratch/'.Str::uuid()->toString();
        Storage::disk('local')->makeDirectory($relative);

        return Storage::disk('local')->path($relative);
    }

    public function store(string $scratch, string $key): void
    {
        $target = $this->absolute($key);
        if (file_exists($target)) {
            throw new RuntimeException("The export key [{$key}] is taken: builds are never written twice.");
        }
        Storage::disk('local')->makeDirectory(dirname(self::ROOT.'/'.$key));
        if (! rename($scratch, $target)) {
            throw new RuntimeException("The build could not be stored under [{$key}].");
        }
    }

    public function discard(string $scratch): void
    {
        if (is_dir($scratch)) {
            foreach (glob($scratch.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($scratch);
        }
    }

    public function exists(string $key, string $file): bool
    {
        return is_file($this->absolute($key).'/'.basename($file));
    }

    public function respond(string $key, string $file, array $headers): BinaryFileResponse
    {
        return new BinaryFileResponse($this->absolute($key).'/'.basename($file), 200, $headers, public: true, autoEtag: false, autoLastModified: false);
    }

    public function delete(string $key): void
    {
        Storage::disk('local')->deleteDirectory(self::ROOT.'/'.$key);
    }

    private function absolute(string $key): string
    {
        if (preg_match('~^[A-Za-z0-9/_-]+$~', $key) !== 1 || str_contains($key, '..')) {
            throw new RuntimeException("[{$key}] is not an export key.");
        }

        return Storage::disk('local')->path(self::ROOT.'/'.$key);
    }
}
