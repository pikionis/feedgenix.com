<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Actions\StartCatalogSync;
use App\Domain\Ingest\Events\ImportProfiled;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Events\MappingApproved;
use App\Domain\Mapping\Models\Mapping;

/** A newly approved mapping, or a new file whose columns have one: read it into the catalogue. */
final readonly class StartSyncOnNewData
{
    public function __construct(private StartCatalogSync $start) {}

    public function handle(MappingApproved|ImportProfiled $event): void
    {
        if ($event instanceof MappingApproved) {
            $mapping = Mapping::query()->find($event->mappingId);
            if ($mapping instanceof Mapping) {
                $this->start->forMapping($mapping);
            }

            return;
        }

        $import = Import::query()->find($event->importId);
        if ($import instanceof Import) {
            $this->start->forImport($import);
        }
    }
}
