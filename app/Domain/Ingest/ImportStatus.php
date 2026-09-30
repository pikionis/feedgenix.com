<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

enum ImportStatus: string
{
    case Queued = 'queued';
    case Fetching = 'fetching';
    case Reading = 'reading';
    case Profiled = 'profiled';
    case Unchanged = 'unchanged';
    case Failed = 'failed';

    /** Still running: at most one such import per feed (a partial unique index enforces it). */
    public function isUnfinished(): bool
    {
        return in_array($this, [self::Queued, self::Fetching, self::Reading], true);
    }
}
