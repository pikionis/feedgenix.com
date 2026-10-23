<?php

declare(strict_types=1);

namespace App\Domain\Export\Contracts;

use Symfony\Component\HttpFoundation\Response;

/**
 * Where export files live and how they are served (ADR 0007). A build writes into a scratch
 * directory; storing it moves the whole directory under a key that is never written again. Moving
 * exports to object storage later replaces this one class.
 */
interface ArtifactServer
{
    /** A new, empty directory for a build to write into (an absolute path). */
    public function scratch(): string;

    /** Move a finished build's directory under its key, which must not exist yet. */
    public function store(string $scratch, string $key): void;

    /** Throw a scratch directory away. */
    public function discard(string $scratch): void;

    public function exists(string $key, string $file): bool;

    /** @param array<string, string> $headers */
    public function respond(string $key, string $file, array $headers): Response;

    public function delete(string $key): void;
}
