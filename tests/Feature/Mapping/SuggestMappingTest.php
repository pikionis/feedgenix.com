<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Mapping\Actions\EditMappingField;
use App\Domain\Mapping\Actions\SuggestMapping;
use App\Domain\Mapping\Models\Mapping;
use App\Models\Tenant;

/*
 * The AI suggestion: reserved before anything is spent, run through the real gateway with a fake
 * provider, every answer through the mapping pipeline. Rule 1: a protected column's values never
 * reach a prompt.
 */

function suggest(Tenant $tenant, Mapping $mapping, ?string $instructions = null): Mapping
{
    return tenancy()->run($tenant->id, static function () use ($mapping, $instructions): Mapping {
        resolve(SuggestMapping::class)->queue($mapping, $instructions);

        return $mapping->refresh();
    });
}

function fundedWorkspace(int $millicredits = 50_000_000): Tenant
{
    $tenant = aiWorkspace();
    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle($millicredits));

    return $tenant;
}

it('reserves the worst case, runs the suggestion, stores what the pipeline accepts and settles', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([
        'Material' => answer('features', 'de', 0.8, 'part', 'Material'),
        'Pflegehinweis' => answer('features', 'de', 0.7, 'part', 'Pflege'),
        'Farbe' => answer('color', 'de', 0.95),
    ]));

    $mapping = suggest($tenant, $mapping);

    $fields = fieldsOf($tenant, $mapping);
    expect($mapping->ai_status)->toBe('done')
        ->and($fields['features@de']->strategy)->toBe('compose')
        ->and($fields['features@de']->recipe['sources'])->toEqual([['column' => 'Material', 'label' => 'Material'], ['column' => 'Pflegehinweis', 'label' => 'Pflege']])
        ->and($fields['features@de']->confidence)->toBe('0.700')
        ->and($fields['color@de']->origin)->toBe('ai')
        ->and($fields['sku']->source_column)->toBe('Artikelnummer')
        ->and($mapping->version)->toBe(2);
    $wallet = walletOf($tenant);
    expect($wallet?->reserved)->toBe(0)
        ->and($wallet?->balance)->toBeLessThan(50_000_000)
        ->and(engineCalls($tenant))->toHaveCount(1)
        ->and(engineCalls($tenant)[0]->prompt)->toBe('map@1');
});

it('never sends the values of prices, stock, ids, barcodes, images or weights (rule 1)', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([]));

    suggest($tenant, $mapping);

    $sent = implode("\n", array_map(static fn (AiRequest $request): string => $request->prompt->system."\n".$request->prompt->instructions."\n".implode("\n", array_map(static fn (AiItem $item): string => $item->text, $request->items)), $fakes['gemini']->calls));
    foreach (['AO-1001', '4006381333931', '189.90', '149.00', 'https://img.example/ao-1001.jpg', '329.00'] as $protected) {
        expect($sent)->not->toContain($protected);
    }
    expect($sent)->toContain('Wanderschuh aus Leder')->toContain('"column":"Preis CHF"')->toContain('withheld');
});

it('never sends a protected value, whatever the feed looks like (property)', function (int $seed): void {
    mt_srand($seed);
    $words = ['Jacke', 'Schuh', 'Zelt', 'robust', 'leicht', 'warm', 'Berg', 'Leder', 'blau', 'rot', 'für', 'mit', 'und'];
    $protectedNames = ['Preis', 'VK Preis', 'Lager', 'Bestand', 'EAN', 'product_ean', 'SKU', 'Bild URL', 'Gewicht', 'Spalte'];
    $columns = [];
    for ($i = 0; $i < 12; $i++) {
        $columns[] = mt_rand(0, 1) === 1
            ? ['name' => $protectedNames[array_rand($protectedNames)].' '.$i, 'protected' => true]
            : ['name' => 'Text '.$i, 'protected' => false];
    }
    $rows = [];
    $secrets = [];
    for ($r = 0; $r < 6; $r++) {
        $row = [];
        foreach ($columns as $column) {
            if ($column['protected']) {
                // Numbers, as prices, stock and ids are: a column of them is protected by its values too.
                $secret = mt_rand(100_000, 999_999).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
                $secrets[] = $secret;
                $row[] = $secret;
            } else {
                $row[] = implode(' ', array_map(static fn (): string => $words[array_rand($words)], range(1, 5)));
            }
        }
        $rows[] = implode(';', $row);
    }
    $csv = implode(';', array_column($columns, 'name'))."\n".implode("\n", $rows)."\n";

    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant, $csv));
    $fakes['gemini']->push(mappingAnswers([]));
    suggest($tenant, $mapping);

    $sent = implode("\n", array_map(static fn (AiRequest $request): string => $request->prompt->instructions."\n".implode("\n", array_map(static fn (AiItem $item): string => $item->text, $request->items)), $fakes['gemini']->calls));
    foreach ($secrets as $secret) {
        expect($sent)->not->toContain($secret);
    }
})->with(range(1, 12));

it('leaves a field empty when two suggestions for it are too close to call', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([
        'Material' => answer('features', 'de', 0.7),
        'Pflegehinweis' => answer('features', 'de', 0.65),
    ]));

    $field = fieldsOf($tenant, suggest($tenant, $mapping))['features@de'];

    expect($field->strategy)->toBe('none')
        ->and($field->status)->toBe('rejected')
        ->and($field->issues[0]['type'])->toBe('ambiguous');
});

it('drops protected columns from a composition, and an uncertain composition altogether', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([
        'Material' => answer('features', 'de', 0.8, 'part', 'Material'),
        'Preis CHF' => answer('features', 'de', 0.8, 'part', 'Preis'),
        'Farbe' => answer('other_attributes', 'de', 0.3, 'part', 'Farbe'),
        'Titel' => answer('other_attributes', 'de', 0.3, 'part', 'Name'),
    ]));

    $fields = fieldsOf($tenant, suggest($tenant, $mapping));

    expect($fields['features@de']->recipe['sources'])->toEqual([['column' => 'Material', 'label' => 'Material']])
        ->and($fields['other_attributes@de']->status)->toBe('rejected')
        ->and($fields['other_attributes@de']->issues[0]['type'])->toBe('uncertain');
});

it('never composes numeric measurement columns: they are golden (rule 1)', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([
        'Breite cm' => answer('dimensions_text', 'de', 0.9, 'part', 'Breite'),
        'Höhe cm' => answer('dimensions_text', 'de', 0.9, 'part', 'Höhe'),
    ]));

    $field = fieldsOf($tenant, suggest($tenant, $mapping))['dimensions_text@de'];

    expect($field->strategy)->toBe('none')
        ->and($field->issues[0]['type'])->toBe('no_usable_sources');
});

it('never lets the AI override what a person set', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    tenancy()->run($tenant->id, fn () => resolve(EditMappingField::class)->handle($mapping, $mapping->version, 'title', 'de', 'Beschreibung', null));
    $fakes['gemini']->push(mappingAnswers(['Titel' => answer('title', 'de', 0.99)]));

    $fields = fieldsOf($tenant, suggest($tenant, $mapping->refresh()));

    expect($fields['title@de']->source_column)->toBe('Beschreibung')->and($fields['title@de']->origin)->toBe('manual');
});

it('passes a seller\'s corrections to the model as instructions', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $fakes['gemini']->push(mappingAnswers([]));

    suggest($tenant, $mapping, 'Grösse is a shoe size, not a colour.');

    expect($fakes['gemini']->calls[0]->prompt->instructions)->toContain('Grösse is a shoe size, not a colour.');
});

it('waits with the exact numbers when the credits do not cover the worst case', function (): void {
    $tenant = fundedWorkspace(1_000);
    fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    expect(fn (): Mapping => suggest($tenant, $mapping))->toThrow(InsufficientCredits::class);
    expect($mapping->refresh()->ai_status)->toBe('none')
        ->and(walletOf($tenant)?->reserved)->toBe(0);
});

it('suggests nothing while AI is switched off', function (): void {
    $tenant = fundedWorkspace();
    fakeAi();
    config(['ai.enabled' => false]);
    $mapping = draftOf($tenant, profiledFeed($tenant));

    expect(fn (): Mapping => suggest($tenant, $mapping))->toThrow(AiDenied::class);
});

it('records a failed suggestion, keeps the draft and releases the hold', function (): void {
    $tenant = fundedWorkspace();
    $fakes = fakeAi();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $before = columnsOf($tenant, $mapping);
    $fakes['gemini']->push(new AiTerminal(AiTerminal::BLOCKED, 'Blocked.'));

    $mapping = suggest($tenant, $mapping);

    expect($mapping->ai_status)->toBe('failed')
        ->and($mapping->ai_failure)->toBe('blocked')
        ->and(columnsOf($tenant, $mapping))->toBe($before)
        ->and(walletOf($tenant)?->reserved)->toBe(0);
});
