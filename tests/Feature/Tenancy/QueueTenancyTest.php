<?php

declare(strict_types=1);

use App\Domain\Tenancy\Queue\QueueTenancy;
use App\Domain\Tenancy\TenancyMode;
use App\Models\Tenant;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordTenantJob;

beforeEach(function (): void {
    RecordTenantJob::$seen = [];
});

it('runs a job as the workspace that dispatched it and keeps the dispatcher context', function (): void {
    $a = Tenant::factory()->create();
    tenancy()->set($a->id);

    dispatch(new RecordTenantJob);

    expect(RecordTenantJob::$seen)->toBe([['mode' => TenancyMode::Tenant, 'tenant' => $a->id, 'session' => (string) $a->id]])
        ->and(tenancy()->tenantId())->toBe($a->id);
});

it('runs a job dispatched without a workspace with no context', function (): void {
    dispatch(new RecordTenantJob);

    expect(RecordTenantJob::$seen[0]['mode'])->toBe(TenancyMode::None);
});

it('never hands the system context to a queued job implicitly', function (): void {
    tenancy()->system('staff tool');

    dispatch(new RecordTenantJob);

    expect(RecordTenantJob::$seen[0]['mode'])->toBe(TenancyMode::None)
        ->and(tenancy()->isSystem())->toBeTrue();
});

it('restores the dispatcher context when the job fails', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    tenancy()->set($b->id);
    $job = new RecordTenantJob(fail: true);

    tenancy()->set($a->id);
    expect(fn (): PendingDispatch => dispatch($job))->toThrow(RuntimeException::class, 'job failed on purpose');

    expect(tenancy()->tenantId())->toBe($a->id);
});

it('carries the workspace through a real queue and a worker, then leaves the worker clean', function (): void {
    config(['queue.default' => 'database']);
    $a = Tenant::factory()->create();

    // A block closure, not `fn () => dispatch(...)`: dispatch() queues the job when its
    // PendingDispatch is destroyed, which for a returned value is after run() has restored the
    // previous context.
    tenancy()->run($a->id, function (): void {
        dispatch(new RecordTenantJob);
    });
    tenancy()->run($a->id, function (): void {
        dispatch(new RecordTenantJob);
    });

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    expect($payload[QueueTenancy::PAYLOAD_KEY] ?? null)->toBe($a->id);

    tenancy()->clear();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(RecordTenantJob::$seen)->toBe([['mode' => TenancyMode::Tenant, 'tenant' => $a->id, 'session' => (string) $a->id]])
        ->and(tenancy()->tenantId())->toBeNull();
});
