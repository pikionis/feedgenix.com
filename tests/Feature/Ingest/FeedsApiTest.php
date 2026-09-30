<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetchNetwork;

beforeEach(function (): void {
    Storage::fake('local');
});

const SHOP_CSV = "sku;title;price\nA-1;Hammer;9.90\nA-2;Nail;0.05\n";

/** @return array{0: Tenant, 1: FakeFetchNetwork} */
function shopWithFeedUrl(string $plan = 'pro'): array
{
    $network = network()->dns('shop.example', ['93.184.215.14'])
        ->respond('https://shop.example/feed.csv?token=secret-token', 200, SHOP_CSV, ['ETag' => '"v1"']);

    return [Tenant::factory()->onPlan($plan)->create(), $network];
}

it('creates a URL feed and reads it at once, showing the host but never the URL', function (): void {
    [$tenant] = shopWithFeedUrl();

    $response = createFeed(owner($tenant), ['name' => 'Main shop', 'url' => 'https://shop.example/feed.csv?token=secret-token'])
        ->assertCreated()
        ->assertJsonPath('feed.name', 'Main shop')
        ->assertJsonPath('feed.source', 'url')
        ->assertJsonPath('feed.host', 'shop.example')
        ->assertJsonPath('feed.latest_import.status', 'profiled')
        ->assertJsonPath('feed.latest_import.row_count', 2)
        ->assertJsonPath('feed.latest_import.column_count', 3);

    expect((string) $response->getContent())->not->toContain('secret-token')->not->toContain('feed.csv');
});

it('stores the URL encrypted', function (): void {
    [$tenant] = shopWithFeedUrl();
    createFeed(owner($tenant), ['name' => 'Main shop', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->assertCreated();

    $raw = DB::table('feed_sources')->value('url');

    expect($raw)->not->toContain('secret-token')
        ->and(tenancy()->run($tenant->id, fn (): string => FeedSource::query()->firstOrFail()->url))->toBe('https://shop.example/feed.csv?token=secret-token');
});

it('creates an upload feed that waits for a file', function (): void {
    $tenant = Tenant::factory()->create();

    createFeed(owner($tenant), ['name' => 'Spring catalogue'])
        ->assertCreated()
        ->assertJsonPath('feed.source', 'upload')
        ->assertJsonPath('feed.host', null)
        ->assertJsonPath('feed.latest_import', null);
});

it('refuses URLs the SSRF guard does not allow, with the reason', function (string $url, string $says): void {
    $tenant = Tenant::factory()->create();

    createFeed(owner($tenant), ['name' => 'X', 'url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => $says]);

    expect(tenancy()->run($tenant->id, fn (): int => Feed::query()->count()))->toBe(0);
})->with([
    ['ftp://shop.example/feed.csv', 'Only http and https'],
    ['https://user:pass@shop.example/feed.csv', 'username and password'],
    ['http://2130706433/', 'standard IP address'],
    ['http://shop.example:22/', 'Port 22'],
]);

it('lets a URL that resolves to a private address fail its import, never fetch it', function (): void {
    $network = network()->dns('internal.example', ['10.0.0.8']);
    $tenant = Tenant::factory()->create();

    createFeed(owner($tenant), ['name' => 'X', 'url' => 'https://internal.example/feed.csv'])
        ->assertCreated()
        ->assertJsonPath('feed.latest_import.status', 'failed')
        ->assertJsonPath('feed.latest_import.failure.reason', 'blocked_address');

    expect($network->requests)->toBe([]);
});

it('keeps to the plan’s feed limit with a 402 that names the plan that fits', function (): void {
    $tenant = Tenant::factory()->create(); // Free: one feed
    createFeed(owner($tenant), ['name' => 'First'])->assertCreated();

    createFeed(owner($tenant), ['name' => 'Second'])
        ->assertStatus(402)
        ->assertJsonPath('error', 'plan_limit')
        ->assertJsonPath('limit', 'feeds')
        ->assertJsonPath('allowed', 1)
        ->assertJsonPath('used', 1)
        ->assertJsonPath('upgrade_to', 'pro');

    expect(tenancy()->run($tenant->id, fn (): int => Feed::query()->count()))->toBe(1);
});

it('serialises concurrent feed creation on the workspace row', function (): void {
    $tenant = Tenant::factory()->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from "tenants"') && str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    createFeed(owner($tenant), ['name' => 'First'])->assertCreated();

    expect($locks)->toHaveCount(1);
});

it('lets owners and admins manage feeds, and members only read and import', function (): void {
    [$tenant] = shopWithFeedUrl();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->json('feed.id');
    $member = memberOf($tenant, 'member');

    $this->actingAs($member)->getJson('/api/v1/feeds')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($member)->postJson('/api/v1/feeds', ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($member)->patchJson("/api/v1/feeds/{$feedId}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/v1/feeds/{$feedId}")->assertForbidden();
    $this->actingAs($member)->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(202);
    $this->actingAs(memberOf($tenant, 'admin'))->patchJson("/api/v1/feeds/{$feedId}", ['name' => 'Renamed'])->assertOk();
});

it('never shows or changes another workspace’s feeds and imports (404)', function (): void {
    [$theirs] = shopWithFeedUrl();
    $feedId = (int) createFeed(owner($theirs), ['name' => 'Theirs', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->json('feed.id');
    $importId = tenancy()->run($theirs->id, fn (): int => Import::query()->firstOrFail()->id);
    $me = owner(Tenant::factory()->onPlan('pro')->create());

    $this->actingAs($me)->getJson("/api/v1/feeds/{$feedId}")->assertNotFound();
    $this->actingAs($me)->patchJson("/api/v1/feeds/{$feedId}", ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($me)->deleteJson("/api/v1/feeds/{$feedId}")->assertNotFound();
    $this->actingAs($me)->postJson("/api/v1/feeds/{$feedId}/fetch")->assertNotFound();
    $this->actingAs($me)->getJson("/api/v1/feeds/{$feedId}/imports")->assertNotFound();
    $this->actingAs($me)->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'x.csv', 'size' => 10])->assertNotFound();
    $this->actingAs($me)->getJson("/api/v1/imports/{$importId}")->assertNotFound();
    $this->actingAs($me)->postJson("/api/v1/imports/{$importId}/reread", [])->assertNotFound();
    $this->actingAs($me)->getJson('/api/v1/feeds')->assertOk()->assertJsonCount(0, 'data');
});

it('renames and re-points a feed; an omitted URL is kept, a new one forgets the old validators', function (): void {
    [$tenant] = shopWithFeedUrl();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->json('feed.id');

    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['name' => 'Renamed', 'parse_options' => ['delimiter' => ';']])
        ->assertOk()->assertJsonPath('feed.name', 'Renamed')->assertJsonPath('feed.parse_options.delimiter', ';');
    $source = tenancy()->run($tenant->id, fn (): FeedSource => FeedSource::query()->firstOrFail());
    expect($source->url)->toContain('secret-token')->and($source->etag)->toBe('"v1"');

    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['url' => 'https://cdn.example/new.csv'])
        ->assertOk()->assertJsonPath('feed.host', 'cdn.example');
    $source = tenancy()->run($tenant->id, fn (): FeedSource => FeedSource::query()->firstOrFail());
    expect($source->url)->toBe('https://cdn.example/new.csv')->and($source->etag)->toBeNull();
});

it('refuses a URL on an upload feed and bad reading options', function (): void {
    $tenant = Tenant::factory()->create();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Uploads'])->json('feed.id');

    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['url' => 'https://shop.example/f.csv'])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['parse_options' => ['encoding' => 'EBCDIC']])->assertUnprocessable()->assertJsonValidationErrors('parse_options.encoding');
    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['parse_options' => ['delimiter' => ':']])->assertUnprocessable()->assertJsonValidationErrors('parse_options.delimiter');
    $this->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feedId}", ['parse_options' => ['surprise' => 1]])->assertUnprocessable();
});

it('deletes a feed with its imports and stored files, keeping files another feed still uses', function (): void {
    $network = network()->dns('shop.example', ['93.184.215.14'])
        ->respond('https://shop.example/a.csv', 200, SHOP_CSV)->respond('https://shop.example/b.csv', 200, SHOP_CSV);
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $a = (int) createFeed(owner($tenant), ['name' => 'A', 'url' => 'https://shop.example/a.csv'])->json('feed.id');
    createFeed(owner($tenant), ['name' => 'B', 'url' => 'https://shop.example/b.csv'])->assertCreated();
    $blob = tenancy()->run($tenant->id, fn (): string => (string) Import::query()->where('feed_id', $a)->value('blob_path'));
    Storage::disk('local')->assertExists($blob);

    $this->actingAs(owner($tenant))->deleteJson("/api/v1/feeds/{$a}")->assertNoContent();

    expect(tenancy()->run($tenant->id, fn (): int => Import::query()->where('feed_id', $a)->count()))->toBe(0);
    Storage::disk('local')->assertExists($blob); // feed B holds the same content

    $b = tenancy()->run($tenant->id, fn (): int => Feed::query()->firstOrFail()->id);
    $this->actingAs(owner($tenant))->deleteJson("/api/v1/feeds/{$b}")->assertNoContent();
    Storage::disk('local')->assertMissing($blob);
    expect($network->requests)->toHaveCount(2);
});

it('answers 409 while an import of the feed is still running, and frees a feed whose import died', function (): void {
    [$tenant] = shopWithFeedUrl();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->json('feed.id');
    $running = tenancy()->run($tenant->id, fn (): Import => Import::query()->create(['feed_id' => $feedId, 'origin' => 'fetch', 'status' => ImportStatus::Reading]));

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")
        ->assertStatus(409)->assertJsonPath('error', 'feed_busy');

    // Two hours without progress: the import is over, and the feed can be fetched again.
    DB::table('imports')->where('id', $running->id)->update(['updated_at' => now()->subHours(3)]);
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(202);

    expect(tenancy()->run($tenant->id, fn (): string => (string) Import::query()->findOrFail($running->id)->failure_reason))->toBe('interrupted');
});

it('rate-limits fetching on demand', function (): void {
    [$tenant] = shopWithFeedUrl();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main', 'url' => 'https://shop.example/feed.csv?token=secret-token'])->json('feed.id');

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(202);
    }

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/fetch")->assertStatus(429);
});
