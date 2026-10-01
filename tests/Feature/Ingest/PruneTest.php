<?php

declare(strict_types=1);

use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/** Writes the files, then dates them (and the folders given) $days back. Order matters: writing into a folder refreshes it. */
function agedFiles(array $files, array $folders, int $days): void
{
    $disk = Storage::disk('local');
    foreach ($files as $file) {
        $disk->put($file, 'x');
    }
    foreach ([...$files, ...$folders] as $path) {
        touch($disk->path($path), time() - $days * 86_400);
    }
}

it('deletes the expired uploads of every workspace with their chunks, and keeps the rest', function (): void {
    $tenants = [Tenant::factory()->create(), Tenant::factory()->create()];
    $sessions = [];
    foreach ($tenants as $tenant) {
        $feedId = (int) createFeed(owner($tenant), ['name' => 'Uploads'])->json('feed.id');
        foreach (['expired', 'fresh'] as $kind) {
            $id = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => 4])->json('upload.id');
            $this->actingAs(owner($tenant))->call('PUT', "/api/v1/feeds/{$feedId}/uploads/{$id}/chunks/0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'sku ')->assertNoContent();
            $sessions[$kind][] = ['tenant' => $tenant->id, 'id' => $id];
        }
    }
    $expired = array_column($sessions['expired'], 'id');
    tenancy()->runAsSystem('test', fn (): int => DB::table('upload_sessions')->whereIn('id', $expired)->update(['expires_at' => now()->subMinute()]));

    $this->artisan('ingest:prune')->assertSuccessful();

    $left = tenancy()->runAsSystem('test', fn (): array => UploadSession::query()->pluck('id')->sort()->values()->all());
    expect($left)->toBe(collect($sessions['fresh'])->pluck('id')->sort()->values()->all());
    foreach ($sessions['expired'] as $session) {
        expect(Storage::disk('local')->exists("uploads/{$session['tenant']}/{$session['id']}"))->toBeFalse();
    }
    foreach ($sessions['fresh'] as $session) {
        expect(Storage::disk('local')->exists("uploads/{$session['tenant']}/{$session['id']}/0"))->toBeTrue();
    }
});

it('deletes work folders untouched for a day and staged files older than a day, never stored feeds', function (): void {
    $disk = Storage::disk('local');
    agedFiles(['work/9-old/received', 'work/9-old/inner/x'], ['work/9-old/inner', 'work/9-old'], 2);
    agedFiles(['work/10-busy/a'], ['work/10-busy'], 2);
    $disk->put('work/10-busy/b', 'still being written');
    touch($disk->path('work/10-busy'), time() - 2 * 86_400);
    $disk->put('work/11-new/received', 'x');
    agedFiles(['staging/1/old-upload'], ['staging/1'], 2);
    $disk->put('staging/2/new-upload', 'x');
    agedFiles(['imports/1/ab/abcdef'], ['imports/1/ab', 'imports/1'], 30);

    $this->artisan('ingest:prune')->assertSuccessful();

    expect($disk->exists('work/9-old'))->toBeFalse()
        ->and($disk->exists('work/10-busy/a'))->toBeTrue()
        ->and($disk->exists('work/11-new/received'))->toBeTrue()
        ->and($disk->exists('staging/1/old-upload'))->toBeFalse()
        ->and($disk->directoryExists('staging/1'))->toBeTrue()
        ->and($disk->exists('staging/2/new-upload'))->toBeTrue()
        ->and($disk->get('imports/1/ab/abcdef'))->toBe('x');
});

it('only lists what it would delete in a dry run', function (): void {
    agedFiles(['work/9-old/received', 'staging/1/old-upload'], ['work/9-old'], 2);

    $this->artisan('ingest:prune', ['--dry-run' => true])
        ->expectsOutputToContain('would delete work/9-old')
        ->expectsOutputToContain('would delete staging/1/old-upload')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('work/9-old/received'))->toBeTrue()
        ->and(Storage::disk('local')->exists('staging/1/old-upload'))->toBeTrue();
});

it('runs every night', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('ingest:prune')->assertSuccessful();
});

it('keeps each feed’s last files and its profile’s, and deletes an older file once nothing uses it', function (): void {
    $disk = Storage::disk('local');
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $main = (int) createFeed(owner($tenant), ['name' => 'Main'])->json('feed.id');
    $other = (int) createFeed(owner($tenant), ['name' => 'Other'])->json('feed.id');
    $blob = fn (string $name): string => "imports/{$tenant->id}/aa/{$name}";
    foreach (['A', 'B', 'C', 'D', 'G'] as $name) {
        $disk->put($blob($name), $name);
    }
    $add = fn (int $feedId, ImportStatus $status, string $name): int => tenancy()->run($tenant->id, fn (): int => Import::query()
        ->create(['feed_id' => $feedId, 'origin' => 'fetch', 'status' => $status, 'blob_path' => $blob($name)])->id);

    $profile = $add($main, ImportStatus::Profiled, 'A');   // older than the last three, but the current profile
    $shared = $add($main, ImportStatus::Failed, 'B');      // beyond the last three; feed Other still uses B
    $alone = $add($main, ImportStatus::Failed, 'G');       // beyond the last three, used by nothing else
    $recent = [$add($main, ImportStatus::Failed, 'C'), $add($main, ImportStatus::Failed, 'D'), $add($main, ImportStatus::Failed, 'C')];
    $elsewhere = $add($other, ImportStatus::Profiled, 'B');

    $this->artisan('ingest:prune')->expectsOutputToContain("deleted file of import {$alone}")->assertSuccessful();

    $paths = tenancy()->run($tenant->id, fn (): array => Import::query()->pluck('blob_path', 'id')->all());
    expect($paths[$profile])->toBe($blob('A'))
        ->and($paths[$shared])->toBeNull()
        ->and($paths[$alone])->toBeNull()
        ->and(array_map(fn (int $id): ?string => $paths[$id], $recent))->toBe([$blob('C'), $blob('D'), $blob('C')])
        ->and($paths[$elsewhere])->toBe($blob('B'))
        ->and($disk->exists($blob('G')))->toBeFalse();
    foreach (['A', 'B', 'C', 'D'] as $name) {
        expect($disk->exists($blob($name)))->toBeTrue();
    }

    $this->actingAs(owner($tenant))->getJson("/api/v1/imports/{$alone}")->assertJsonPath('import.can_reread', false);
});

it('only lists the old feed files it would delete in a dry run', function (): void {
    $disk = Storage::disk('local');
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Main'])->json('feed.id');
    $ids = [];
    foreach (range(1, 4) as $n) {
        $disk->put("imports/{$tenant->id}/aa/{$n}", 'x');
        $ids[] = tenancy()->run($tenant->id, fn (): int => Import::query()
            ->create(['feed_id' => $feedId, 'origin' => 'fetch', 'status' => ImportStatus::Failed, 'blob_path' => "imports/{$tenant->id}/aa/{$n}"])->id);
    }

    $this->artisan('ingest:prune', ['--dry-run' => true])->expectsOutputToContain("would delete file of import {$ids[0]}")->assertSuccessful();

    expect($disk->exists("imports/{$tenant->id}/aa/1"))->toBeTrue()
        ->and(tenancy()->run($tenant->id, fn (): int => Import::query()->whereNull('blob_path')->count()))->toBe(0);
});
