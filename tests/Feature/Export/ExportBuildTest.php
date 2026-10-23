<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Export\ColumnPlan;
use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\Exceptions\ExportBusy;
use App\Domain\Export\LocalArtifactServer;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Building exports (ADR 0007, ADR 0022): golden values byte for byte, only publishable texts,
 * files checked by reading them back, a verified gzip, the shrink guard, and a published build
 * that keeps serving whatever happens to the next one.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

it('writes every mapped field, golden values byte for byte, a column per language, and a matching gzip', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $profile = exportProfile($tenant, $feed);

    $artifact = buildExport($tenant, $profile);

    $rows = exportRows($artifact, 'products.csv');
    $products = productsOf($tenant, $feed);
    expect($artifact->status)->toBe('published')
        ->and($artifact->rows)->toBe(5)
        ->and($profile->fresh()?->published_artifact_id)->toBe($artifact->id)
        ->and(array_key_first($rows[0]))->toBe('sku')
        ->and(array_keys($rows[0]))->toContain('title_de', 'title_en', 'gtin', 'price')
        // Nothing fills these: no empty column that could wipe a channel's own data.
        ->and(array_keys($rows[0]))->not->toContain('sale_price', 'mpn', 'brand')
        ->and($rows[0])->toMatchArray(['sku' => 'AO-1001', 'title_de' => 'Wanderschuh aus Leder', 'title_en' => '[en] Wanderschuh aus Leder']);
    foreach ($rows as $row) {
        foreach ($products[$row['sku']]->source_values as $field => $value) {
            if (array_key_exists($field, $row)) {
                expect($row[$field])->toBe($value);
            }
        }
    }

    $plain = Storage::disk('local')->get("exports/{$artifact->storage_key}/products.csv");
    expect(gzdecode((string) Storage::disk('local')->get("exports/{$artifact->storage_key}/products.csv.gz")))->toBe($plain)
        ->and($artifact->files[0])->toMatchArray(['name' => 'products.csv', 'rows' => 5, 'sha256' => hash('sha256', (string) $plain)]);
});

it('keeps a golden value with quotes or a tab exactly: quoted in CSV, its row left out of TSV and reported', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $products = productsOf($tenant, $feed);
    tenancy()->run($tenant->id, function () use ($products): void {
        Product::query()->whereKey($products['AO-1001']->id)->update(['source_values' => json_encode([...$products['AO-1001']->source_values, 'gtin' => '00"4006381333931'])]);
        Product::query()->whereKey($products['AO-1002']->id)->update(['source_values' => json_encode([...$products['AO-1002']->source_values, 'gtin' => "5901234\t123457"])]);
    });

    $csv = exportRows(buildExport($tenant, exportProfile($tenant, $feed)), 'products.csv');
    $tsvBuild = buildExport($tenant, exportProfile($tenant, $feed, ['name' => 'Tabs', 'dialect' => 'tsv']));
    $tsv = exportRows($tsvBuild, 'products.tsv', 'tsv');

    expect(collect($csv)->pluck('gtin', 'sku')->only(['AO-1001', 'AO-1002'])->all())->toBe(['AO-1001' => '00"4006381333931', 'AO-1002' => "5901234\t123457"])
        ->and(collect($tsv)->pluck('sku')->all())->toBe(['AO-1001', 'AO-1003', 'AO-1004', 'AO-1005'])
        ->and(collect($tsv)->firstWhere('sku', 'AO-1001')['gtin'])->toBe('00"4006381333931')
        ->and($tsvBuild->report)->toMatchArray(['left_out' => 1, 'reasons' => ['golden_unwritable' => 1]])
        ->and($tsvBuild->report['examples'][0])->toBe(['file' => 'products.tsv', 'sku' => 'AO-1002', 'column' => 'gtin', 'reason' => 'golden_unwritable']);
});

it('publishes only ready translations: never a failed, stale or waiting one, and never another language instead', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $products = productsOf($tenant, $feed);
    $title = fn (string $sku) => ProductText::query()->where('product_id', $products[$sku]->id)->where('field', 'title')->where('locale', 'en');
    tenancy()->run($tenant->id, function () use ($title): void {
        $title('AO-1001')->update(['status' => 'qa_failed']);
        $title('AO-1002')->update(['status' => 'stale']);
        $title('AO-1003')->update(['value' => 'Touring backpack', 'origin' => 'manual']);
        $title('AO-1004')->delete();
        $title('AO-1005')->update(['status' => 'held']);
    });

    $titles = collect(exportRows(buildExport($tenant, exportProfile($tenant, $feed)), 'products.csv'))->pluck('title_en', 'sku')->all();

    expect($titles)->toBe(['AO-1001' => '', 'AO-1002' => '', 'AO-1003' => 'Touring backpack', 'AO-1004' => '', 'AO-1005' => '']);
});

it('writes a Google Merchant file per language, with the price and its currency, and counts what the channel requires', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $profile = exportProfile($tenant, $feed, ['preset' => 'google_merchant', 'dialect' => 'tsv', 'layout' => 'per_language']);

    $artifact = buildExport($tenant, $profile);

    $english = exportRows($artifact, 'products-en.tsv', 'tsv');
    $products = productsOf($tenant, $feed);
    $first = $products['AO-1001']->source_values;
    expect(collect($artifact->files)->pluck('name')->all())->toBe(['products-de.tsv', 'products-en.tsv'])
        ->and(array_keys($english[0]))->toContain('id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'gtin')
        ->and(array_keys($english[0]))->not->toContain('sale_price', 'mpn', 'brand', 'custom_label_0')
        ->and($english[0]['id'])->toBe('AO-1001')
        ->and($english[0]['title'])->toBe('[en] Wanderschuh aus Leder')
        ->and($english[0]['price'])->toStartWith($first['price'])
        ->and($english[0]['price'])->toBe(isset($first['currency']) ? "{$first['price']} {$first['currency']}" : $first['price'])
        ->and(collect($english)->pluck('availability', 'id')->only(['AO-1001', 'AO-1003'])->all())->toBe(['AO-1001' => 'in stock', 'AO-1003' => 'out of stock'])
        ->and(exportRows($artifact, 'products-de.tsv', 'tsv')[0]['title'])->toBe('Wanderschuh aus Leder')
        // The feed has no product links: Google requires them, the report says so per file.
        ->and($artifact->report['missing']['products-en.tsv']['link'])->toBe(5);
});

it('publishes nothing new when nothing changed, and a new build when a text did', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $profile = exportProfile($tenant, $feed);
    $first = buildExport($tenant, $profile);

    $again = buildExport($tenant, $profile);
    expect($again->status)->toBe('unchanged')
        ->and($again->storage_key)->toBeNull()
        ->and($profile->fresh()?->published_artifact_id)->toBe($first->id);

    startTranslation($tenant, $feed);
    $changed = buildExport($tenant, $profile);
    expect($changed->status)->toBe('published')
        ->and($first->fresh()?->status)->toBe('superseded')
        ->and($profile->fresh()?->published_artifact_id)->toBe($changed->id);
});

it('refuses to publish a build that lost most rows, unless a person says so (shrink guard)', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant, manyProductsCsv(60));
    approveMapping($tenant, $feed);
    $profile = exportProfile($tenant, $feed, ['locales' => ['de']]);
    $full = buildExport($tenant, $profile);
    tenancy()->run($tenant->id, fn () => Product::query()->where('feed_id', $feed->id)->where('sku', '>', 'AO-0020')->update(['status' => 'disappeared']));

    $shrunk = buildExport($tenant, $profile);
    expect($full->rows)->toBe(60)
        ->and($shrunk->status)->toBe('rejected')
        ->and($shrunk->failure_reason)->toBe('shrink')
        ->and($shrunk->failure_message)->toContain('20 rows', '60')
        ->and($profile->fresh()?->published_artifact_id)->toBe($full->id);

    $forced = buildExport($tenant, $profile, allowShrink: true);
    expect($forced->status)->toBe('published')->and($forced->rows)->toBe(20);
});

it('keeps the published build serving when the next one fails, and leaves no scratch files behind', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $profile = exportProfile($tenant, $feed);
    $good = buildExport($tenant, $profile);
    // The disk refuses to store the next build; everything else works.
    app()->instance(ArtifactServer::class, new readonly class implements ArtifactServer
    {
        private LocalArtifactServer $real;

        public function __construct()
        {
            $this->real = new LocalArtifactServer;
        }

        public function scratch(): string
        {
            return $this->real->scratch();
        }

        public function store(string $scratch, string $key): void
        {
            throw new RuntimeException('The disk on fire refused the build.');
        }

        public function discard(string $scratch): void
        {
            $this->real->discard($scratch);
        }

        public function exists(string $key, string $file): bool
        {
            return $this->real->exists($key, $file);
        }

        public function respond(string $key, string $file, array $headers): BinaryFileResponse
        {
            return $this->real->respond($key, $file, $headers);
        }

        public function delete(string $key): void
        {
            $this->real->delete($key);
        }
    });
    startTranslation($tenant, $feed);

    expect(fn (): ExportArtifact => buildExport($tenant, $profile))->toThrow(RuntimeException::class, 'disk on fire');

    $failed = tenancy()->run($tenant->id, fn () => ExportArtifact::query()->latest('id')->first());
    expect($failed?->status)->toBe('failed')
        ->and($failed?->failure_message)->toContain('disk on fire')
        ->and($profile->fresh()?->published_artifact_id)->toBe($good->id)
        ->and(Storage::disk('local')->files('exports/scratch', true))->toBe([])
        ->and(Storage::disk('local')->exists("exports/{$good->storage_key}/products.csv"))->toBeTrue();
});

it('builds one profile once at a time', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $profile = exportProfile($tenant, $feed);
    tenancy()->run($tenant->id, fn () => ExportArtifact::query()->forceCreate(['profile_id' => $profile->id, 'status' => 'building']));

    expect(fn (): ExportArtifact => buildExport($tenant, $profile))->toThrow(ExportBusy::class);
});

it('keeps the files of the published build and the two before it, and the records of all', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $profile = exportProfile($tenant, $feed, ['locales' => ['de']]);
    $builds = [];
    foreach (range(1, 5) as $round) {
        tenancy()->run($tenant->id, fn () => ProductText::query()->where('field', 'title')->where('locale', 'de')->where('role', 'source')
            ->whereHas('product', fn (Builder $product) => $product->where('sku', 'AO-1001'))->update(['value' => "Wanderschuh {$round}"]));
        $builds[] = buildExport($tenant, $profile);
    }

    $kept = collect($builds)->map(fn (ExportArtifact $build): bool => Storage::disk('local')->exists("exports/{$build->storage_key}/products.csv"))->all();
    expect($kept)->toBe([false, false, true, true, true])
        ->and(collect($builds)->map(fn (ExportArtifact $build) => $build->fresh()?->files_deleted)->all())->toBe([true, true, false, false, false]);
});

it('never lets a preset reshape a golden value', function (): void {
    config(['export.presets.broken' => ['label' => 'Broken', 'dialects' => ['csv'], 'layouts' => ['wide'], 'columns' => [['header' => 'price', 'field' => 'price', 'transform' => 'plain_text']]]]);
    $profile = (new ExportProfile)->forceFill(['preset' => 'broken', 'locales' => ['de']]);

    expect(fn () => resolve(ColumnPlan::class)->columns($profile, ['sku', 'price'], null))->toThrow(InvalidArgumentException::class, 'golden field [price]');

    // Every preset that ships passes the same check with every field filled.
    foreach (array_keys((array) config('export.presets')) as $preset) {
        if ($preset !== 'broken') {
            $columns = resolve(ColumnPlan::class)->columns((new ExportProfile)->forceFill(['preset' => $preset, 'locales' => ['de']]), array_keys((array) config('canonical_schema.fields')), 'de');
            expect($columns)->not->toBeEmpty();
        }
    }
});

it("keeps one workspace's profiles and builds invisible to another", function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $artifact = buildExport($tenant, exportProfile($tenant, $feed));
    $other = aiWorkspace();

    expect(tenancy()->run($other->id, fn (): array => [ExportProfile::query()->count(), ExportArtifact::query()->find($artifact->id)]))->toBe([0, null]);
});
