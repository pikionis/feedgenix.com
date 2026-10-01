<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\UploadSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

function gib(float $gigabytes): int
{
    return (int) round($gigabytes * 1024 ** 3);
}

/** The server's ceiling (FEEDGENIX_FEED_MAX_BYTES): 2 GB unless a test sizes the server larger. */
function serverReads(int $bytes): void
{
    config(['ingest.limits.max_bytes' => $bytes, 'ingest.limits.max_inflated_bytes' => $bytes]);
}

function uploadFeedFor(Tenant $tenant): int
{
    return (int) createFeed(owner($tenant), ['name' => 'Uploads'])->json('feed.id');
}

/** A Free workspace whose feed limits are lowered (as a deal would raise them), so tests stay small. */
function smallLimits(array $limits): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->forceFill(['limits_override' => $limits])->save();

    return $tenant;
}

it('refuses an upload larger than the plan reads, naming the cheapest plan that reads it', function (float $size, string $upgrade, string $says): void {
    serverReads(gib(50));
    $tenant = Tenant::factory()->create();
    $feedId = uploadFeedFor($tenant);

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'big.xml', 'size' => gib($size)])
        ->assertStatus(402)
        ->assertJsonPath('error', 'plan_limit')
        ->assertJsonPath('limit', 'feed_bytes')
        ->assertJsonPath('allowed', gib(2))
        ->assertJsonPath('requested', gib($size))
        ->assertJsonPath('upgrade_to', $upgrade)
        ->assertJsonPath('message', $says);

    expect(tenancy()->run($tenant->id, fn (): int => UploadSession::query()->count()))->toBe(0);
})->with([
    [3, 'pro', 'This file is 3 GB; your Free plan reads feeds up to 2 GB. Pro reads feeds up to 10 GB.'],
    [12.5, 'ultra', 'This file is 12.5 GB; your Free plan reads feeds up to 2 GB. Ultra reads feeds up to 50 GB.'],
]);

it('suggests writing to us when even the largest plan does not read the file', function (): void {
    serverReads(gib(100));
    $tenant = Tenant::factory()->onPlan('ultra')->create();

    $this->actingAs(owner($tenant))->postJson('/api/v1/feeds/'.uploadFeedFor($tenant).'/uploads', ['filename' => 'huge.xml', 'size' => gib(60)])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_to', null)
        ->assertJsonPath('message', 'This file is 60 GB; your Ultra plan reads feeds up to 50 GB. Write to us about a Custom plan for larger feeds.');
});

it('says what can be read for now when this server reads less than any upgrade would', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create(); // the server stays at its 2 GB default

    $this->actingAs(owner($tenant))->postJson('/api/v1/feeds/'.uploadFeedFor($tenant).'/uploads', ['filename' => 'big.xml', 'size' => gib(3)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.size.0', 'This file is 3 GB; feeds up to 2 GB can be read for now.');
});

it('lets a plan upload what it reads, up to exactly its limit', function (string $plan, float $size): void {
    serverReads(gib(50));
    $tenant = Tenant::factory()->onPlan($plan)->create();

    $this->actingAs(owner($tenant))->postJson('/api/v1/feeds/'.uploadFeedFor($tenant).'/uploads', ['filename' => 'big.xml', 'size' => gib($size)])
        ->assertCreated()
        ->assertJsonPath('upload.size', gib($size));
})->with([['pro', 9.5], ['free', 2], ['pro', 10]]);

it('refuses one byte over the limit', function (): void {
    serverReads(gib(50));
    $tenant = Tenant::factory()->create();

    $this->actingAs(owner($tenant))->postJson('/api/v1/feeds/'.uploadFeedFor($tenant).'/uploads', ['filename' => 'big.xml', 'size' => gib(2) + 1])
        ->assertStatus(402);
});

it('checks the first file before making an upload feed, so a refusal leaves nothing behind', function (): void {
    serverReads(gib(50));
    $tenant = Tenant::factory()->create();

    createFeed(owner($tenant), ['name' => 'Big', 'upload_size' => gib(3)])
        ->assertStatus(402)
        ->assertJsonPath('limit', 'feed_bytes')
        ->assertJsonPath('upgrade_to', 'pro');
    createFeed(owner($tenant), ['name' => 'Both', 'url' => 'https://shop.example/feed.xml', 'upload_size' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('upload_size');
    createFeed(owner($tenant), ['name' => 'Empty', 'upload_size' => 0])->assertJsonValidationErrors('upload_size');

    expect(tenancy()->run($tenant->id, fn (): int => Feed::query()->count()))->toBe(0);

    createFeed(owner($tenant), ['name' => 'Fits', 'upload_size' => gib(1.5)])->assertCreated();
});

it('refuses an empty upload in words', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs(owner($tenant))->postJson('/api/v1/feeds/'.uploadFeedFor($tenant).'/uploads', ['filename' => 'empty.csv', 'size' => 0])
        ->assertJsonPath('errors.size.0', 'This file is empty.');
});

it('stops a download at the plan’s limit and names what reads more on this server', function (): void {
    $network = network()->dns('shop.example', ['93.184.215.14'])
        ->respond('https://shop.example/feed.csv', 200, "sku,title\n".str_repeat("A-1,Hammer\n", 200_000));
    $tenant = smallLimits(['feed_bytes' => 1024 ** 2]);

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv'])
        ->assertJsonPath('feed.latest_import.status', 'failed')
        ->assertJsonPath('feed.latest_import.failure.reason', 'too_large')
        // Pro reads 10 GB, but this server reads 2 GB: the promise is what upgrading really gives here.
        ->assertJsonPath('feed.latest_import.failure.message', 'This feed is larger than 1 MB uncompressed, the most your Free plan reads. Pro reads feeds up to 2 GB.');

    // The download itself stops there: a 50 GB file is never fetched whole to be refused after.
    expect($network->requests[0]['max_bytes'])->toBe(1024 ** 2);
});

it('promises no upgrade when this server reads no more than the plan already does', function (): void {
    serverReads(1024 ** 2);
    network()->dns('shop.example', ['93.184.215.14'])
        ->respond('https://shop.example/feed.csv', 200, "sku,title\n".str_repeat("A-1,Hammer\n", 200_000));
    $tenant = Tenant::factory()->onPlan('pro')->create();

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv'])
        ->assertJsonPath('feed.latest_import.failure.reason', 'too_large')
        ->assertJsonPath('feed.latest_import.failure.message', 'This feed is larger than 1 MB uncompressed, the most that can be read for now.');
});

it('refuses a gzip that grows past the plan once uncompressed', function (): void {
    $csv = "sku,title\n".str_repeat("A-1,Hammer\n", 300_000); // ~3 MB, a few KB compressed
    network()->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/feed.csv.gz', 200, (string) gzencode($csv));
    $tenant = smallLimits(['feed_bytes' => 1024 ** 2]);

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv.gz'])
        ->assertJsonPath('feed.latest_import.failure.reason', 'too_large')
        ->assertJsonPath('feed.latest_import.failure.message', 'This feed is larger than 1 MB uncompressed, the most your Free plan reads. Pro reads feeds up to 2 GB.');
});

it('refuses more rows than the plan reads', function (): void {
    network()->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/feed.csv', 200, "sku\nA-1\nA-2\nA-3\nA-4\n");
    $tenant = smallLimits(['feed_rows' => 3]);

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv'])
        ->assertJsonPath('feed.latest_import.failure.reason', 'too_many_rows')
        ->assertJsonPath('feed.latest_import.failure.message', 'This feed has more than 3 rows, the most your Free plan reads. Pro reads feeds up to 25,000,000 rows.');
});

it('reads a feed right at the plan’s row limit', function (): void {
    network()->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/feed.csv', 200, "sku\nA-1\nA-2\nA-3\n");
    $tenant = smallLimits(['feed_rows' => 3]);

    createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv'])
        ->assertJsonPath('feed.latest_import.status', 'profiled')
        ->assertJsonPath('feed.latest_import.row_count', 3);
});

it('shows the limits that apply: the plan’s, within what this server reads', function (): void {
    $free = Tenant::factory()->create();
    $pro = Tenant::factory()->onPlan('pro')->create();

    $this->actingAs(owner($free))->getJson('/api/v1/workspace')
        ->assertJsonPath('entitlements.limits.feed_bytes', gib(2))
        ->assertJsonPath('entitlements.limits.feed_rows', 5_000_000);
    $this->actingAs(owner($pro))->getJson('/api/v1/workspace')->assertJsonPath('entitlements.limits.feed_bytes', gib(2));

    serverReads(gib(50));
    $this->actingAs(owner($pro))->getJson('/api/v1/workspace')
        ->assertJsonPath('entitlements.limits.feed_bytes', gib(10))
        ->assertJsonPath('entitlements.limits.feed_rows', 25_000_000);

    $this->getJson('/api/plans')
        ->assertJsonPath('plans.0.limits.feed_bytes', gib(2))
        ->assertJsonPath('plans.1.limits.feed_bytes', gib(10))
        ->assertJsonPath('plans.2.limits.feed_bytes', gib(50))
        ->assertJsonPath('plans.2.limits.feed_rows', 100_000_000);
});
