<?php

declare(strict_types=1);

use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Notices\Notify;
use App\Domain\Ops\Models\StaffAuditEntry;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/* The staff view of the workspaces and the staff trail (ADR 0026). */

it('keeps customers out, even from telling which workspaces exist', function (): void {
    $tenant = Tenant::factory()->create();
    $customer = owner($tenant);

    foreach (['/workspaces', '/workspaces/'.$tenant->id, '/workspaces/999999', '/audit'] as $path) {
        asStaff($customer, $path)->assertForbidden();
    }
    expect(StaffAuditEntry::query()->count())->toBe(0);
});

it('asks staff for the two-factor check before anything', function (): void {
    $this->withHeader('Referer', config('app.url').'/app')->actingAs(staffWithTwoFactor())->getJson('/api/v1/admin/workspaces')
        ->assertForbidden()->assertJsonPath('error', 'two_factor_required');
});

it('lists every workspace, newest first, with its plan and usage', function (): void {
    $older = Tenant::factory()->create(['name' => 'Alpine Outfitters']);
    $newer = Tenant::factory()->onPlan('pro')->create(['name' => 'Harbour Tools']);
    addLanguage($older, 'de');
    addLanguage($older, 'fr');
    createFeed(owner($newer), ['name' => 'Uploads'])->assertCreated();
    tenancy()->run($newer->id, fn () => resolve(GrantCredits::class)->handle(2_500_000));
    recordAiCall($newer, 41_999);

    asStaff(staffWithTwoFactor(), '/workspaces')->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('workspaces.0.id', $newer->id)
        ->assertJsonPath('workspaces.0.plan', 'pro')
        ->assertJsonPath('workspaces.0.feeds', 1)
        ->assertJsonPath('workspaces.0.credits_available', 2_500)
        ->assertJsonPath('workspaces.0.ai_credits_30d', 42)
        ->assertJsonPath('workspaces.0.owner.email', owner($newer)->email)
        ->assertJsonPath('workspaces.1.id', $older->id)
        ->assertJsonPath('workspaces.1.plan', 'free')
        ->assertJsonPath('workspaces.1.languages', 2)
        ->assertJsonPath('workspaces.1.feeds', 0);
});

it('finds a workspace by name, slug, owner email or id, and takes % literally', function (): void {
    $alpine = Tenant::factory()->create(['name' => 'Alpine Outfitters', 'slug' => 'alpine']);
    $harbour = Tenant::factory()->create(['name' => 'Harbour 100% Tools', 'slug' => 'harbour']);
    $staff = staffWithTwoFactor();
    $ids = static fn (TestResponse $response): array => array_column((array) $response->json('workspaces'), 'id');

    expect($ids(asStaff($staff, '/workspaces?search=outfit')))->toBe([$alpine->id])
        ->and($ids(asStaff($staff, '/workspaces?search=HARBOUR')))->toBe([$harbour->id])
        ->and($ids(asStaff($staff, '/workspaces?search='.urlencode(owner($alpine)->email))))->toBe([$alpine->id])
        ->and($ids(asStaff($staff, '/workspaces?search='.$alpine->id)))->toContain($alpine->id)
        ->and($ids(asStaff($staff, '/workspaces?search='.urlencode('100%'))))->toBe([$harbour->id])
        // A bare % would match every name if it were a wildcard.
        ->and($ids(asStaff($staff, '/workspaces?search='.urlencode('%'))))->toBe([$harbour->id]);
});

it('pages by 25 and clamps a page past the end to the last one', function (): void {
    Tenant::factory()->count(26)->create();
    $staff = staffWithTwoFactor();

    asStaff($staff, '/workspaces?page=2')->assertOk()->assertJsonPath('meta.pages', 2)->assertJsonCount(1, 'workspaces');
    asStaff($staff, '/workspaces?page=9')->assertOk()->assertJsonPath('meta.page', 2)->assertJsonCount(1, 'workspaces');
});

it('shows one workspace in full, and nothing of another', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Alpine Outfitters']);
    $other = Tenant::factory()->create(['name' => 'Harbour Tools']);
    $admin = memberOf($tenant, 'admin');
    addLanguage($tenant, 'de');
    createFeed(owner($tenant), ['name' => 'Alpine uploads'])->assertCreated();
    createFeed(owner($other), ['name' => 'Harbour uploads'])->assertCreated();
    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle(1_000_000, 'grant', 'beta budget'));
    tenancy()->run($other->id, fn () => resolve(GrantCredits::class)->handle(9_000_000, 'grant', 'other budget'));
    recordAiCall($tenant, 12_000);
    recordAiCall($tenant, 3_000, 'retryable');
    recordAiCall($other, 77_000);
    Notify::send($other->id, 'test', 'other-notice', 'info', 'Harbour was told', 'Only Harbour.');

    $response = asStaff(staffWithTwoFactor(), '/workspaces/'.$tenant->id)->assertOk()
        ->assertJsonPath('workspace.name', 'Alpine Outfitters')
        ->assertJsonPath('plan.limits.feeds', 1)
        ->assertJsonPath('languages.0.code', 'de')
        ->assertJsonPath('languages.0.paused', false)
        ->assertJsonCount(1, 'feeds')
        ->assertJsonPath('feeds.0.name', 'Alpine uploads')
        ->assertJsonPath('credits.available', 1_000)
        ->assertJsonPath('credits.transactions.0.note', 'beta budget')
        ->assertJsonPath('ai_usage_30d.0.purpose', 'translate')
        ->assertJsonPath('ai_usage_30d.0.calls', 2)
        ->assertJsonPath('ai_usage_30d.0.failed', 1)
        ->assertJsonPath('ai_usage_30d.0.input_tokens', 2400)
        ->assertJsonPath('ai_usage_30d.0.credits', 15)
        ->assertJsonCount(0, 'notices');

    expect(array_column((array) $response->json('members'), 'email'))->toBe([owner($tenant)->email, $admin->email])
        ->and(array_column((array) $response->json('members'), 'role'))->toBe(['owner', 'admin']);
    expect((string) $response->getContent())->not->toContain('Harbour')->not->toContain('other budget');
});

it('never shows a cost, a markup or a provider price, not even to staff', function (): void {
    $tenant = Tenant::factory()->create();
    recordAiCall($tenant, 12_000);
    $staff = staffWithTwoFactor();

    foreach (['/workspaces', '/workspaces/'.$tenant->id, '/audit'] as $path) {
        $response = asStaff($staff, $path)->assertOk();
        $keys = allKeys((array) $response->json());
        expect(array_filter($keys, static fn (string $key): bool => preg_match('/cost|markup|price|usd|eur/i', $key) === 1))->toBe([])
            ->and((string) $response->getContent())->not->toContain('0.0071234567')->not->toContain('7123456');
    }
});

it('answers 404 for a workspace that is not there', function (): void {
    $staff = staffWithTwoFactor();

    asStaff($staff, '/workspaces/999999')->assertNotFound();
    asStaff($staff, '/workspaces/alpine')->assertNotFound();
    asStaff($staff, '/workspaces/99999999999999999999')->assertNotFound();
});

it('writes one line of the trail per staff request, with its answer and its workspace', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = staffWithTwoFactor();

    asStaff($staff, '/workspaces?search=x')->assertOk();
    asStaff($staff, '/workspaces/'.$tenant->id)->assertOk();
    asStaff($staff, '/workspaces/999999')->assertNotFound();

    $lines = StaffAuditEntry::query()->orderBy('id')->get();
    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('action')->all())->toBe(['view', 'view', 'view'])
        ->and($lines->pluck('status')->all())->toBe([200, 200, 404])
        ->and($lines->pluck('subject_tenant_id')->all())->toBe([null, $tenant->id, 999999])
        ->and($lines->pluck('path')->all())->toBe(['/api/v1/admin/workspaces', '/api/v1/admin/workspaces/'.$tenant->id, '/api/v1/admin/workspaces/999999'])
        ->and($lines->first()?->user_email)->toBe($staff->email);

    asStaff($staff, '/workspaces/'.$tenant->id)->assertJsonPath('audit.0.path', '/api/v1/admin/workspaces/'.$tenant->id);
    asStaff($staff, '/audit')->assertOk()->assertJsonPath('audit.0.path', '/api/v1/admin/workspaces/'.$tenant->id);
});

it('keeps the trail append-only: no line is changed or removed', function (): void {
    asStaff(staffWithTwoFactor(), '/workspaces')->assertOk();
    $id = (int) StaffAuditEntry::query()->value('id');

    // Each in its own savepoint: a failed statement would abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('staff_audit')->where('id', $id)->update(['action' => 'nothing'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('staff_audit')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(StaffAuditEntry::query()->value('action'))->toBe('view');
});
