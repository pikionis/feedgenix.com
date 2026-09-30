<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Jobs\ProcessImport;
use App\Domain\Ingest\Models\Import;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/** A Pro workspace with a URL feed served by the scripted network; returns [tenant, feed id, network]. */
function urlFeed(string $body, array $headers = []): array
{
    $network = network()->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/feed', 200, $body, $headers);
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed'])->json('feed.id');

    return [$tenant, $feedId, $network];
}

function latestImport(Tenant $tenant, int $feedId): Import
{
    return tenancy()->run($tenant->id, fn (): Import => Import::query()->where('feed_id', $feedId)->latest('id')->firstOrFail());
}

it('shows how the file was read, its column profile, samples, SKU and warnings', function (): void {
    [$tenant, $feedId] = urlFeed("sku;title;gtin;price\nA-1;Hammer;4006381333931;9,90\nA-2;Nail;5901234123457;0,05\nA-3;Saw;96385074;19,90\n");
    $import = latestImport($tenant, $feedId);

    $response = $this->actingAs(owner($tenant))->getJson("/api/v1/imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('import.status', 'profiled')
        ->assertJsonPath('import.format', 'csv')
        ->assertJsonPath('import.delimiter', ';')
        ->assertJsonPath('import.encoding.name', 'UTF-8')
        ->assertJsonPath('import.row_count', 3)
        ->assertJsonPath('import.sku.column', 'sku')
        ->assertJsonPath('import.sku.confidence', 'high')
        ->assertJsonPath('import.columns.2.name', 'gtin')
        ->assertJsonPath('import.columns.2.kind', 'gtin')
        ->assertJsonPath('import.columns.3.kind', 'decimal')
        ->assertJsonPath('import.columns.0.fill_rate', 1)
        ->assertJsonPath('import.sample_rows.0', ['sku' => 'A-1', 'title' => 'Hammer', 'gtin' => '4006381333931', 'price' => '9,90'])
        ->assertJsonPath('import.warnings', []);

    expect((string) $response->getContent())->not->toContain('imports/')->not->toContain('staging');
});

it('keeps the received file once per workspace, under its SHA-256', function (): void {
    $csv = "sku\nA-1\n";
    [$tenant, $feedId] = urlFeed($csv);
    $import = latestImport($tenant, $feedId);

    expect($import->content_sha256)->toBe(hash('sha256', $csv))
        ->and($import->blob_path)->toBe("imports/{$tenant->id}/".substr(hash('sha256', $csv), 0, 2).'/'.hash('sha256', $csv));
    Storage::disk('local')->assertExists((string) $import->blob_path);
    expect(Storage::disk('local')->get((string) $import->blob_path))->toBe($csv);
});

it('marks a fetch that brings the same bytes as unchanged, reusing the profile', function (): void {
    [$tenant, $feedId] = urlFeed("sku\nA-1\n");
    $first = latestImport($tenant, $feedId);

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")
        ->assertStatus(202)
        ->assertJsonPath('import.status', 'unchanged')
        ->assertJsonPath('import.reuses_import_id', $first->id);
});

it('asks conditionally after a good import and treats 304 as unchanged', function (): void {
    [$tenant, $feedId, $network] = urlFeed("sku\nA-1\n", ['ETag' => '"v1"', 'Last-Modified' => 'Tue, 29 Sep 2026 10:00:00 GMT']);
    $network->respond('https://shop.example/feed', 304);

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertJsonPath('import.status', 'unchanged');

    expect($network->requests[0]['headers'])->not->toHaveKey('If-None-Match')
        ->and($network->requests[1]['headers'])->toMatchArray(['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Tue, 29 Sep 2026 10:00:00 GMT']);
});

it('never asks conditionally while the feed has no readable profile: a 304 would leave it with nothing', function (): void {
    [$tenant, $feedId, $network] = urlFeed('<!DOCTYPE html><html>Log in</html>');
    // Validators without any successful import (however they got there) must not be used.
    tenancy()->run($tenant->id, fn (): int => FeedSource::query()->update(['etag' => '"stale"', 'last_modified' => 'Tue, 29 Sep 2026 10:00:00 GMT']));

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(202);

    expect($network->requests[1]['headers'])->not->toHaveKey('If-None-Match')->not->toHaveKey('If-Modified-Since');
});

it('never remembers the validators of a download that failed to read', function (): void {
    $network = network()->dns('shop.example', ['93.184.215.14'])
        ->respond('https://shop.example/feed', 200, '<!DOCTYPE html><html>Log in</html>', ['ETag' => '"login-page"']);
    $tenant = Tenant::factory()->onPlan('pro')->create();

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed'])
        ->assertJsonPath('feed.latest_import.status', 'failed')
        ->assertJsonPath('feed.latest_import.failure.reason', 'unsupported_format');

    expect(tenancy()->run($tenant->id, fn (): ?string => FeedSource::query()->firstOrFail()->etag))->toBeNull();
    expect($network->requests)->toHaveCount(1);
});

it('records a rate limit as "try later", with the server’s Retry-After', function (): void {
    network()->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/feed', 429, '', ['Retry-After' => '600']);
    $tenant = Tenant::factory()->onPlan('pro')->create();

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed'])
        ->assertJsonPath('feed.latest_import.status', 'failed')
        ->assertJsonPath('feed.latest_import.failure.reason', 'rate_limited')
        ->assertJsonPath('feed.latest_import.failure.retry_after_seconds', 600);
});

it('records network failures with their reason', function (FetchFailure $failure): void {
    network()->dns('shop.example', ['93.184.215.14'])->fail('https://shop.example/feed', $failure);
    $tenant = Tenant::factory()->onPlan('pro')->create();

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed'])
        ->assertJsonPath('feed.latest_import.failure.reason', $failure->value);
})->with([FetchFailure::Timeout, FetchFailure::TlsFailed, FetchFailure::Truncated, FetchFailure::TooLarge]);

it('reads a stored file again with a corrected encoding, and keeps the choice for next time', function (): void {
    // Greek Windows-1253 bytes: detection falls back to Windows-1252 and gets them wrong.
    [$tenant, $feedId] = urlFeed("sku;title\nA-1;\xD0\xEF\xF4\xE7\xF1\xE9\n");
    $first = latestImport($tenant, $feedId);
    expect($first->encoding)->toBe(['name' => 'Windows-1252', 'reason' => 'fallback']);

    $this->actingAs(owner($tenant))->postJson("/api/v1/imports/{$first->id}/reread", ['encoding' => 'Windows-1253'])
        ->assertStatus(202)
        ->assertJsonPath('import.status', 'profiled')
        ->assertJsonPath('import.reuses_import_id', $first->id);

    $second = latestImport($tenant, $feedId);
    expect($second->sample_rows[0]['title'] ?? null)->toBe('Ποτηρι')
        ->and($second->encoding)->toBe(['name' => 'Windows-1253', 'reason' => 'override'])
        ->and($second->blob_path)->toBe($first->blob_path);

    $this->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feedId}")->assertJsonPath('feed.parse_options.encoding', 'Windows-1253');
});

it('keeps the file of an import it could not read, so it can be read again', function (): void {
    [$tenant, $feedId] = urlFeed('<products><product><sku>1</sku><name>Broken &amp</name></product>');
    $failed = latestImport($tenant, $feedId);

    expect($failed->status)->toBe(ImportStatus::Failed)
        ->and($failed->failure_reason)->toBe('malformed_xml')
        ->and($failed->blob_path)->not->toBeNull();
});

it('explains reading failures in the import', function (): void {
    [$tenant, $feedId] = urlFeed("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 64));
    $import = latestImport($tenant, $feedId);

    $this->actingAs(owner($tenant))->getJson("/api/v1/imports/{$import->id}")
        ->assertJsonPath('import.status', 'failed')
        ->assertJsonPath('import.failure.reason', 'legacy_excel')
        ->assertJsonPath('import.failure.message', 'This is an old Excel file (.xls). Save it as .xlsx or CSV and upload it again.');
});

it('lists a feed’s imports, newest first', function (): void {
    [$tenant, $feedId] = urlFeed("sku\nA-1\n");
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(202);

    $this->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feedId}/imports")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'unchanged')
        ->assertJsonPath('data.1.status', 'profiled');
});

it('marks an import whose job died as interrupted, in its own workspace', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Uploads'])->json('feed.id');
    $import = tenancy()->run($tenant->id, fn (): Import => Import::query()->create(['feed_id' => $feedId, 'origin' => 'upload', 'status' => ImportStatus::Reading]));

    (new ProcessImport($import->id, $tenant->id))->failed(new RuntimeException('killed'));

    $import = tenancy()->run($tenant->id, fn (): Import => Import::query()->findOrFail($import->id));
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toBe('interrupted');
});

it('leaves a finished import alone when its job reports a late failure', function (): void {
    [$tenant, $feedId] = urlFeed("sku\nA-1\n");
    $import = latestImport($tenant, $feedId);

    (new ProcessImport($import->id, $tenant->id))->failed(new RuntimeException('late'));

    expect(latestImport($tenant, $feedId)->status)->toBe(ImportStatus::Profiled);
});
