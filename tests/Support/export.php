<?php

declare(strict_types=1);

use App\Domain\Export\Dialects\Dialects;
use App\Domain\Export\ExportBuilder;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Feeds\Models\Feed;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/* Export helpers: a profile, a build, and the rows of a published file as a channel reads them. */

function exportProfile(Tenant $tenant, Feed $feed, array $attributes = []): ExportProfile
{
    return tenancy()->run($tenant->id, static fn (): ExportProfile => ExportProfile::query()->forceCreate([
        'feed_id' => $feed->id, 'name' => 'Main', 'preset' => 'standard', 'dialect' => 'csv', 'layout' => 'wide', 'locales' => ['de', 'en'],
        ...$attributes,
    ]));
}

function buildExport(Tenant $tenant, ExportProfile $profile, bool $allowShrink = false): ExportArtifact
{
    return tenancy()->run($tenant->id, static fn (): ExportArtifact => resolve(ExportBuilder::class)->build($profile->fresh() ?? $profile, $allowShrink));
}

/** @return list<array<string, string>> the rows of a published file, keyed by header */
function exportRows(ExportArtifact $artifact, string $file, string $dialect = 'csv'): array
{
    $path = Storage::disk('local')->path("exports/{$artifact->storage_key}/{$file}");
    $handle = fopen($path, 'rb');
    $rows = iterator_to_array(Dialects::for($dialect)->read($handle), false);
    fclose($handle);
    $header = array_shift($rows);

    return array_map(static fn (array $row): array => array_combine($header, $row), $rows);
}

/** A synthetic German shop export with $count products (no real data). */
function manyProductsCsv(int $count): string
{
    $lines = ['Artikelnummer;EAN;Titel;Beschreibung;Preis CHF;Lagerbestand'];
    for ($index = 1; $index <= $count; $index++) {
        $lines[] = sprintf('AO-%04d;;Zelt Nummer %d;Ein leichtes Zelt für die Berge, Modell %d;%d.90;%d', $index, $index, $index, 100 + $index, $index % 7);
    }

    return implode("\n", $lines)."\n";
}
