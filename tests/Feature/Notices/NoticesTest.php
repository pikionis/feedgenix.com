<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Notices\Models\Notice;
use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\Notify;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Notices (ADR 0025): 80% of the allowance, products waiting for the next period, work waiting
 * for credits, plan pauses and held exports. Once each, in the app for everyone and by email to
 * the verified owner and admins, with the choices; never a cost, markup or provider price.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
});

/** @return list<array<string, mixed>> */
function noticesOf(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => Notice::query()->orderBy('id')->get()->map(fn (Notice $notice): array => $notice->only(['kind', 'key', 'level', 'title', 'body', 'action_path', 'emailed_at']))->all());
}

it('gives a heads-up at 80% of the allowance, once a period, by email', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 6]])->save();

    startTranslation($tenant, $feed);
    startTranslation($tenant, $feed);

    $notices = noticesOf($tenant);
    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toMatchArray(['kind' => 'usage', 'level' => 'warn', 'title' => "80% of this period's products used", 'action_path' => '/app/billing/plans'])
        ->and($notices[0]['body'])->toContain('used 5 of the 6 products its Free plan includes', 'new products wait')
        ->and($notices[0]['emailed_at'])->not->toBeNull();
    Notification::assertSentTo(owner($tenant), NoticeMail::class, fn (NoticeMail $mail): bool => $mail->title === "80% of this period's products used");
});

it('says nothing under 80% of the allowance', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 10]])->save();

    startTranslation($tenant, $feed);

    expect(noticesOf($tenant))->toBe([]);
});

it('says which products wait for the next period and what to do', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();

    startTranslation($tenant, $feed);

    $held = collect(noticesOf($tenant))->firstWhere('kind', 'allowance');
    expect($held)->toMatchArray(['level' => 'action', 'title' => 'Products wait for the next period'])
        ->and($held['body'])->toContain('2 products are over the 3 products your Free plan includes', 'The others are translated now; these start by themselves then, or at once when you upgrade');
});

it('shows on the plan, before a start, what it would count against the allowance', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();

    $allowance = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan.allowance');

    expect($allowance)->toMatchArray(['held' => true, 'new' => 5, 'over' => 2, 'allowance' => 3, 'used' => 0])
        ->and(noticesOf($tenant))->toBe([]);
});

it('tells once per run that translations wait for credits', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en'], credits: 1_000);

    startTranslation($tenant, $feed);
    $this->travel(6)->minutes();
    Artisan::call('ops:reconcile', [], new BufferedOutput);

    $held = collect(noticesOf($tenant))->where('kind', 'held')->values();
    expect($held)->toHaveCount(1)
        ->and($held[0])->toMatchArray(['title' => 'Translations wait for credits', 'action_path' => "/app/feeds/{$feed->id}/translation"])
        ->and($held[0]['body'])->toContain('Alpine shop', 'continue by themselves');
});

it('names what a plan paused, by email, and what it resumed, in the app', function (): void {
    $tenant = aiWorkspace();
    onPro($tenant);
    foreach (['Oldest', 'Newest'] as $name) {
        $this->travel(1)->minutes();
        $feed = profiledFeed($tenant);
        tenancy()->run($tenant->id, fn () => $feed->forceFill(['name' => $name])->save());
    }
    addLanguage($tenant, 'en');
    $this->travel(1)->minutes();
    addLanguage($tenant, 'fr');

    changePlan($tenant, 'free');
    changePlan($tenant, 'pro');

    [$paused, $resumed] = collect(noticesOf($tenant))->where('kind', 'plan')->values()->all();
    expect($paused['title'])->toBe('Your plan paused 1 feed and 1 language')
        ->and($paused['body'])->toContain('Paused: feeds Oldest; languages French', 'Nothing is deleted', 'keep serving their feed URLs')
        ->and($paused['emailed_at'])->not->toBeNull()
        ->and($resumed['title'])->toBe('Resumed 1 feed and 1 language')
        ->and($resumed['emailed_at'])->toBeNull();
});

it('tells when a new build of an export was held back', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant, manyProductsCsv(60));
    approveMapping($tenant, $feed);
    $profile = exportProfile($tenant, $feed, ['locales' => ['de']]);
    buildExport($tenant, $profile);
    tenancy()->run($tenant->id, fn () => Product::query()->where('feed_id', $feed->id)->where('sku', '>', 'AO-0020')->update(['status' => 'disappeared']));

    buildExport($tenant, $profile);

    $held = collect(noticesOf($tenant))->firstWhere('kind', 'export');
    expect($held['title'])->toBe('Main: the new build was held back')
        ->and($held['body'])->toContain('20 rows', 'keeps serving')
        ->and($held['action_path'])->toBe("/app/feeds/{$feed->id}/publish");
});

it('emails only verified owners and admins, and shows everyone in the workspace the same notices', function (): void {
    $tenant = aiWorkspace();
    $admin = memberOf($tenant, 'admin');
    $member = memberOf($tenant, 'member');
    $unverified = memberOf($tenant, 'admin');
    $unverified->forceFill(['email_verified_at' => null])->save();

    tenancy()->run($tenant->id, fn (): bool => Notify::send($tenant->id, 'test', 'test:1', 'action', 'Do something', 'Because.', 'Go', '/app', email: true));

    // The same key again: no second notice, no second email.
    expect(tenancy()->run($tenant->id, fn (): bool => Notify::send($tenant->id, 'test', 'test:1', 'action', 'Do something', 'Because.', 'Go', '/app', email: true)))->toBeFalse();

    Notification::assertSentToTimes(owner($tenant), NoticeMail::class, 1);
    Notification::assertSentTo([owner($tenant), $admin], NoticeMail::class);
    Notification::assertNotSentTo([$member, $unverified], NoticeMail::class);
    expect(test()->actingAs($member)->getJson('/api/v1/notices')->assertOk()->json('notices.0.title'))->toBe('Do something');
});

it('lists the newest first with the unread count, and marks some or all read', function (): void {
    $tenant = aiWorkspace();
    tenancy()->run($tenant->id, function () use ($tenant): void {
        foreach (range(1, 3) as $index) {
            Notify::send($tenant->id, 'test', "test:{$index}", 'info', "Notice {$index}", 'Body.');
        }
    });

    $list = test()->actingAs(owner($tenant))->getJson('/api/v1/notices')->assertOk()->json();
    expect(collect($list['notices'])->pluck('title')->all())->toBe(['Notice 3', 'Notice 2', 'Notice 1'])
        ->and($list['unread'])->toBe(3);

    $one = test()->actingAs(owner($tenant))->postJson('/api/v1/notices/read', ['ids' => [$list['notices'][0]['id']]])->assertOk()->json();
    expect($one['unread'])->toBe(2)->and($one['notices'][0]['read'])->toBeTrue();
    expect(test()->actingAs(owner($tenant))->postJson('/api/v1/notices/read')->assertOk()->json('unread'))->toBe(0);

    $other = aiWorkspace();
    expect(test()->actingAs(owner($other))->getJson('/api/v1/notices')->assertOk()->json())->toBe(['notices' => [], 'unread' => 0]);
});

it('never says a cost, a markup or a provider price', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en'], credits: 1_000);
    $tenant->forceFill(['plan' => 'pro', 'billing_interval' => 'month', 'plan_started_at' => now(), 'plan_ends_at' => now()->addMonth(), 'limits_override' => ['products_per_month' => 6]])->save();
    startTranslation($tenant, $feed);
    changePlan($tenant, 'free');

    $words = collect(noticesOf($tenant))->map(fn (array $notice): string => $notice['title'].' '.$notice['body'])->implode(' ');
    expect($words)->not->toBe('')
        ->and(strtolower($words))->not->toContain('€', 'eur', 'cost', 'markup', 'usd', 'price per', 'token');
    foreach (User::query()->get() as $user) {
        Notification::assertSentTo($user, NoticeMail::class, fn (NoticeMail $mail): bool => ! str_contains(strtolower($mail->body), 'cost'));
    }
});
