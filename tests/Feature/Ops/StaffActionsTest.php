<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ops\Models\StaffAuditEntry;
use App\Models\Tenant;

/* What staff change in a workspace: credits and the plan (ADR 0026). */

it('grants credits worth an amount in euros, with the note, and records it in credits', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = staffWithTwoFactor();

    staffPost($staff, "/workspaces/{$tenant->id}/credits", ['euros' => '10', 'note' => 'beta budget'])
        ->assertOk()->assertJsonPath('granted', 100_000)->assertJsonPath('available', 100_000);

    expect(walletOf($tenant)?->balance)->toBe(100_000_000);
    $line = StaffAuditEntry::query()->sole();
    expect($line->action)->toBe('grant_credits')
        ->and($line->status)->toBe(200)
        ->and($line->subject_tenant_id)->toBe($tenant->id)
        // jsonb keeps its own key order: compare the pairs, not the order.
        ->and($line->details)->toEqual(['credits' => 100_000, 'note' => 'beta budget']);

    asStaff($staff, "/workspaces/{$tenant->id}")->assertJsonPath('credits.transactions.0.note', 'beta budget')
        ->assertJsonPath('credits.transactions.0.kind', 'grant');
});

it('refuses an amount the command would refuse, and grants nothing', function (string $euros): void {
    $tenant = Tenant::factory()->create();

    staffPost(staffWithTwoFactor(), "/workspaces/{$tenant->id}/credits", ['euros' => $euros, 'note' => 'beta budget'])
        ->assertUnprocessable()->assertJsonValidationErrors('euros');

    expect(walletOf($tenant))->toBeNull()
        // The refused attempt is in the trail all the same, as a change that did not happen.
        ->and(StaffAuditEntry::query()->sole()->only(['action', 'status']))->toBe(['action' => 'change', 'status' => 422]);
    // A form's spaces are trimmed before validation (TrimStrings), so ' 10' is 10 here.
})->with(['0', '-5', 'abc', '1.23456', '10000.01', '1e3', '10,5', '']);

it('asks why credits are granted', function (): void {
    $tenant = Tenant::factory()->create();

    staffPost(staffWithTwoFactor(), "/workspaces/{$tenant->id}/credits", ['euros' => '10', 'note' => ' '])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
    expect(walletOf($tenant))->toBeNull();
});

it('lets no customer grant credits or change a plan, not even in their own workspace', function (): void {
    $tenant = Tenant::factory()->create();

    staffPost(owner($tenant), "/workspaces/{$tenant->id}/credits", ['euros' => '10', 'note' => 'free money'])->assertForbidden();
    staffPost(owner($tenant), "/workspaces/{$tenant->id}/plan", ['plan' => 'ultra', 'interval' => 'year'])->assertForbidden();

    expect(walletOf($tenant))->toBeNull()->and($tenant->fresh()?->plan)->toBe('free');
});

it('puts a workspace on a plan until a date, and records the change', function (): void {
    $tenant = Tenant::factory()->create();
    $until = now()->addMonths(3)->toDateString();

    staffPost(staffWithTwoFactor(), "/workspaces/{$tenant->id}/plan", ['plan' => 'pro', 'interval' => 'year', 'until' => $until])
        ->assertOk()->assertJsonPath('plan', 'pro')->assertJsonPath('plan_name', 'Pro');

    $tenant->refresh();
    expect($tenant->plan)->toBe('pro')
        ->and($tenant->billing_interval)->toBe('year')
        ->and($tenant->plan_ends_at?->toDateString())->toBe($until);
    $line = StaffAuditEntry::query()->sole();
    expect($line->action)->toBe('set_plan')->and($line->status)->toBe(200);
    expect($line->details)->toMatchArray([
        'from' => ['plan' => 'free', 'interval' => null, 'ends_at' => null],
        'to' => ['plan' => 'pro', 'interval' => 'year', 'ends_at' => $until],
    ]);
});

it('pauses what is over a smaller plan at once, and says so', function (): void {
    $tenant = Tenant::factory()->create();
    onPro($tenant);
    createFeed(owner($tenant), ['name' => 'First'])->assertCreated();
    createFeed(owner($tenant), ['name' => 'Second'])->assertCreated();

    staffPost(staffWithTwoFactor(), "/workspaces/{$tenant->id}/plan", ['plan' => 'free', 'interval' => 'month'])
        ->assertOk()->assertJsonPath('plan', 'free')->assertJsonPath('feeds_paused', 1);

    expect(tenancy()->run($tenant->id, fn (): int => Feed::query()->whereNotNull('plan_paused_at')->count()))->toBe(1)
        ->and(StaffAuditEntry::query()->sole()->details['paused'] ?? null)->toBe(['feeds' => 1, 'languages' => 0]);
});

it('refuses a plan, an interval or a date it cannot use, and changes nothing', function (array $body): void {
    $tenant = Tenant::factory()->create();

    staffPost(staffWithTwoFactor(), "/workspaces/{$tenant->id}/plan", $body)->assertUnprocessable();

    expect($tenant->fresh()?->plan)->toBe('free');
})->with([
    'unknown plan' => [['plan' => 'gold', 'interval' => 'month']],
    'unknown interval' => [['plan' => 'pro', 'interval' => 'week']],
    'a date in the past' => [['plan' => 'pro', 'interval' => 'month', 'until' => '2020-01-31']],
    // A closure: the app (and its clock) exists only when the test runs.
    'today' => [fn (): array => ['plan' => 'pro', 'interval' => 'month', 'until' => now()->toDateString()]],
    'not a date' => [['plan' => 'pro', 'interval' => 'month', 'until' => '31/01/2027']],
]);

it('answers 404 for a workspace that is not there, and writes nothing', function (): void {
    $staff = staffWithTwoFactor();

    staffPost($staff, '/workspaces/999999/credits', ['euros' => '10', 'note' => 'beta budget'])->assertNotFound();
    staffPost($staff, '/workspaces/alpine/plan', ['plan' => 'pro', 'interval' => 'month'])->assertNotFound();
});
