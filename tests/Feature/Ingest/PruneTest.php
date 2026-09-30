<?php

declare(strict_types=1);

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
