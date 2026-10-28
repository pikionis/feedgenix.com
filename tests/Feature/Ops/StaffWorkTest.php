<?php

declare(strict_types=1);

use App\Domain\Export\Jobs\BuildExportJob;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* What does not move, across every workspace (ADR 0026). */

/** A run of the workspace's (only) feed, last changed $minutesAgo. */
function runOf(Tenant $tenant, string $stage, string $status, int $minutesAgo = 0, ?string $failure = null): PipelineRun
{
    return tenancy()->run($tenant->id, static function () use ($stage, $status, $minutesAgo, $failure): PipelineRun {
        $feed = Feed::query()->firstOrFail();
        $run = PipelineRun::query()->forceCreate([
            'feed_id' => $feed->id, 'stage' => $stage, 'status' => $status, 'counts' => [], 'warnings' => [],
            'failure_reason' => $failure === null ? null : 'test', 'failure_message' => $failure,
        ]);
        DB::table('pipeline_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $run;
    });
}

function workspaceWithFeed(string $name): Tenant
{
    $tenant = Tenant::factory()->create(['name' => $name]);
    createFeed(owner($tenant), ['name' => "{$name} feed"])->assertCreated();

    return $tenant;
}

it('keeps customers out', function (): void {
    $tenant = Tenant::factory()->create();

    asStaff(owner($tenant), '/work')->assertForbidden();
});

it('shows the runs that failed, wait or seem stuck, from every workspace, and none that move', function (): void {
    $failed = runOf(workspaceWithFeed('Alpine'), 'sync', 'failed', 60, 'The products could not be read.');
    $held = runOf(workspaceWithFeed('Harbour'), 'translate', 'held', 5);
    $stuck = runOf(workspaceWithFeed('Coast'), 'translate', 'running', 45);
    runOf(workspaceWithFeed('Moving'), 'translate', 'running', 5);
    runOf(workspaceWithFeed('Done'), 'done', 'done', 60);
    runOf(workspaceWithFeed('Long ago'), 'sync', 'failed', 60 * 24 * 8);

    $runs = collect((array) asStaff(staffWithTwoFactor(), '/work')->assertOk()->json('runs'))->keyBy('id');

    expect($runs->keys()->sort()->values()->all())->toBe(collect([$failed->id, $held->id, $stuck->id])->sort()->values()->all())
        ->and($runs[$failed->id]['workspace'])->toBe('Alpine')
        ->and($runs[$failed->id]['feed'])->toBe('Alpine feed')
        ->and($runs[$failed->id]['failure_message'])->toBe('The products could not be read.')
        ->and($runs[$stuck->id]['stuck'])->toBeTrue()
        ->and($runs[$held->id]['stuck'])->toBeFalse();
});

it('says whether the reconciler still runs', function (): void {
    $staff = staffWithTwoFactor();
    asStaff($staff, '/work')->assertJsonPath('reconciler.last_at', null)->assertJsonPath('reconciler.running', false);

    Artisan::call('ops:reconcile');
    asStaff($staff, '/work')->assertJsonPath('reconciler.running', true);

    $this->travel(6)->minutes();
    asStaff($staff, '/work')->assertJsonPath('reconciler.running', false);
});

it('counts held batches by reason across workspaces', function (): void {
    foreach (['Alpine', 'Harbour'] as $name) {
        $tenant = workspaceWithFeed($name);
        $run = runOf($tenant, 'translate', 'held');
        tenancy()->run($tenant->id, static fn () => DB::table('ai_work_units')->insert([
            'tenant_id' => $tenant->id, 'run_id' => $run->id, 'feed_id' => $run->feed_id, 'field' => 'title',
            'source_locale' => 'en', 'target_locale' => 'de', 'items' => '[]', 'status' => 'held', 'hold_reason' => 'credits',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    asStaff(staffWithTwoFactor(), '/work')->assertOk()
        ->assertJsonPath('held.0.reason', 'credits')
        ->assertJsonPath('held.0.units', 2)
        ->assertJsonPath('held.0.workspaces', 2);
});

it('lists failed jobs by name with the first line of the error only', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => BuildExportJob::class]),
        'exception' => "RuntimeException: The disk is full.\n#0 /var/www/app/Some.php(12): secret()\n#1 {main}",
        'failed_at' => now()->subHour(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => 'not json', 'exception' => str_repeat('x', 400), 'failed_at' => now()->subDays(3),
    ]);

    $response = asStaff(staffWithTwoFactor(), '/work')->assertOk()
        ->assertJsonPath('queue.failed_24h', 1)
        ->assertJsonPath('failed_jobs.0.job', 'Unknown job')
        ->assertJsonPath('failed_jobs.1.job', 'BuildExportJob')
        ->assertJsonPath('failed_jobs.1.error', 'RuntimeException: The disk is full.');

    expect(mb_strlen((string) $response->json('failed_jobs.0.error')))->toBe(161)
        ->and((string) $response->getContent())->not->toContain('secret()');
});
