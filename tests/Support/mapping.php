<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Mapping\Actions\DraftMapping;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use App\Models\Tenant;

/*
 * A synthetic German shop export (no real data), read through the real upload and profile path.
 */
const ALPINE_SHOP_CSV = <<<'CSV'
Artikelnummer;EAN;Titel;Beschreibung;Farbe;Grösse;Preis CHF;Lagerbestand;Bild URL;Material;Pflegehinweis;Breite cm;Höhe cm
AO-1001;4006381333931;Wanderschuh aus Leder;Robuster Wanderschuh für die Berge und für lange Touren;Braun;42;189.90;12;https://img.example/ao-1001.jpg;Leder;Mit einem feuchten Tuch reinigen;30;12
AO-1002;5901234123457;Regenjacke mit Kapuze;Leichte Jacke für den Regen mit einer Kapuze und zwei Taschen;Rot;M;149.00;5;https://img.example/ao-1002.jpg;Polyester;Bei 30 Grad waschen;55;70
AO-1003;9780201379624;Rucksack für Touren;Ein Rucksack für die Tagestour mit einem Fach für die Trinkblase;Grün;30 l;89.50;0;https://img.example/ao-1003.jpg;Nylon;Von Hand waschen;28;48
AO-1004;4012345678901;Zelt für zwei Personen;Ein leichtes Zelt für zwei Personen und für die Berge;Gelb;2P;329.00;3;https://img.example/ao-1004.jpg;Polyester;Trocken lagern;210;110
AO-1005;4000000000006;Schlafsack für den Winter;Ein warmer Schlafsack für die kalten Nächte in den Bergen;Blau;L;259.00;7;https://img.example/ao-1005.jpg;Daunen;Nicht in den Trockner;80;220
CSV;

/** A feed of $tenant with ALPINE_SHOP_CSV (or $csv) uploaded and profiled. */
function profiledFeed(Tenant $tenant, string $csv = ALPINE_SHOP_CSV): Feed
{
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Alpine shop'])->assertCreated()->json('feed.id');
    uploadFeed(owner($tenant), $feedId, $csv, 'export.csv')->assertStatus(202)->assertJsonPath('import.status', 'profiled');

    return tenancy()->run($tenant->id, static fn (): Feed => Feed::query()->findOrFail($feedId));
}

function draftOf(Tenant $tenant, Feed $feed): Mapping
{
    return tenancy()->run($tenant->id, static fn (): Mapping => resolve(DraftMapping::class)->current($feed) ?? throw new RuntimeException('No profile.'));
}

/** @return array<string, MappingField> field@locale => field */
function fieldsOf(Tenant $tenant, Mapping $mapping): array
{
    return tenancy()->run($tenant->id, static function () use ($mapping): array {
        $fields = [];
        foreach (MappingField::query()->where('mapping_id', $mapping->id)->get() as $field) {
            $fields[$field->key()] = $field;
        }

        return $fields;
    });
}

/** @return array<string, string|null> field@locale => its column, for the filled fields */
function columnsOf(Tenant $tenant, Mapping $mapping): array
{
    $columns = [];
    foreach (fieldsOf($tenant, $mapping) as $key => $field) {
        if ($field->strategy === 'direct') {
            $columns[$key] = $field->source_column;
        }
    }
    ksort($columns);

    return $columns;
}

/**
 * A fake model that answers per column name; columns not listed get "no field".
 *
 * @param  array<string, array<string, mixed>>  $byColumn
 */
function mappingAnswers(array $byColumn): Closure
{
    return static function (AiRequest $request) use ($byColumn): ProviderResult {
        $values = [];
        foreach ($request->items as $item) {
            $column = (string) (json_decode($item->text, true)['column'] ?? '');
            $values[$item->id] = $byColumn[$column] ?? ['target' => null, 'role' => 'direct', 'locale' => null, 'label' => null, 'confidence' => 0.9, 'reason' => 'No field fits.'];
        }

        return new ProviderResult($values, new AiUsage(inputTokens: 2_000, outputTokens: 500), (string) $request->model);
    };
}

function answer(?string $target, ?string $locale = null, float $confidence = 0.9, string $role = 'direct', ?string $label = null): array
{
    return ['target' => $target, 'role' => $role, 'locale' => $locale, 'label' => $label, 'confidence' => $confidence, 'reason' => 'Suggested.'];
}
