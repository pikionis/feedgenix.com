<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/** An attempt to write a row for another workspace, or to move a row between workspaces. */
final class CrossTenantWrite extends LogicException
{
    public static function create(string $model, int $context, int $row): self
    {
        return new self("Refusing to create [{$model}] for workspace {$row} while acting for workspace {$context}.");
    }

    public static function move(string $model): self
    {
        return new self("Refusing to change the workspace of an existing [{$model}].");
    }
}
