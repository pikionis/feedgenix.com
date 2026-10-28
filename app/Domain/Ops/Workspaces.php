<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\AI\Models\EngineCall;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Credits\Millicredits;
use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Credits\Models\CreditWallet;
use App\Domain\Notices\Models\Notice;
use App\Domain\Ops\Models\StaffAuditEntry;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff view of the workspaces (ADR 0026): every workspace with its plan and usage, and one
 * workspace in full. AI spend is shown in credits and tokens only: the provider cost never leaves
 * the database, not even for staff (CLAUDE.md, rule 6).
 *
 * @phpstan-type Row array{id: int, name: string, slug: string, plan: string, plan_name: string, interval: string, internal: bool, frozen: bool, ai_paused: bool, owner: array{name: string, email: string, verified: bool}|null, feeds: int, feeds_paused: int, languages: int, products_this_period: int, credits_available: int, ai_credits_30d: int, last_import_at: string|null, created_at: string|null}
 */
final readonly class Workspaces
{
    public const int PER_PAGE = 25;

    private const int RECENT = 15;

    public function __construct(private TenantContext $context) {}

    /**
     * The workspaces, newest first; the search matches a name, a slug, the owner's email or an id.
     *
     * @return array{rows: list<Row>, page: int, pages: int, total: int}
     */
    public function list(string $search, int $page): array
    {
        return $this->context->runAsSystem('staff: the workspaces list', function () use ($search, $page): array {
            $query = Tenant::query()->with('owner');
            $search = trim($search);
            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(static function (Builder $where) use ($like, $search): void {
                    $where->where('name', 'ilike', $like)
                        ->orWhere('slug', 'ilike', $like)
                        ->orWhereHas('owner', static fn (Builder $owner) => $owner->where('email', 'ilike', $like));
                    if (ctype_digit($search) && strlen($search) < 19) {
                        $where->orWhere('id', (int) $search);
                    }
                });
            }
            $total = $query->count();
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, $page), $pages);

            $tenants = self::withFigures($query)
                ->orderByDesc('tenants.id')
                ->forPage($page, self::PER_PAGE)
                ->get();

            return [
                'rows' => array_values($tenants->map(fn (Tenant $tenant): array => $this->row($tenant))->all()),
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
            ];
        });
    }

    /**
     * One workspace in full, read in its own context (row-level security keeps it to its rows).
     *
     * @return array{
     *     workspace: Row,
     *     plan: array{started_at: string|null, ends_at: string|null, status: string, overrides: array<string, int|null>|null, period_start: string, period_end: string, limits: array{feeds: int|null, languages: int|null, products: int|null}},
     *     members: list<array{id: int, name: string, email: string, role: string, verified: bool, staff: bool}>,
     *     languages: list<array{code: string, paused: bool}>,
     *     held: list<array{reason: string, units: int, feeds: int}>,
     *     credits: array{available: int, reserved: int, transactions: list<array{id: int, kind: string, credits: int, balance_after: int, note: string|null, created_at: string}>},
     *     ai_usage_30d: list<array{purpose: string, calls: int, failed: int, input_tokens: int, output_tokens: int, thinking_tokens: int, credits: int}>,
     *     notices: list<array{id: int, level: string, title: string, read: bool, created_at: string|null}>,
     *     audit: list<array{id: int, user_email: string, action: string, method: string, path: string, status: int, created_at: string|null}>
     * }
     */
    public function detail(Tenant $tenant): array
    {
        $figures = $this->context->runAsSystem('staff: one workspace', static fn (): ?Tenant => self::withFigures(Tenant::query()->with('owner')->whereKey($tenant->id))->first());
        $entitlements = Entitlements::for($tenant);
        $period = $entitlements->period();
        $members = $tenant->users()->orderBy('users.id')->get();

        $inside = $this->context->run($tenant->id, static function (): array {
            $wallet = CreditWallet::query()->first();

            return [
                'languages' => array_values(TenantLanguage::query()->orderBy('id')->get()->map(static fn (TenantLanguage $language): array => [
                    'code' => $language->language_code,
                    'paused' => $language->plan_paused_at !== null,
                ])->all()),
                'held' => array_values(WorkUnit::query()->where('status', 'held')
                    ->selectRaw('coalesce(hold_reason, ?) AS reason, count(*) AS units, count(DISTINCT feed_id) AS feeds', ['unknown'])
                    ->groupBy('reason')->orderBy('reason')->toBase()->get()
                    ->map(static fn (object $held): array => [
                        'reason' => (string) $held->reason, 'units' => (int) $held->units, 'feeds' => (int) $held->feeds,
                    ])->all()),
                'credits' => [
                    'available' => intdiv($wallet?->available() ?? 0, Millicredits::PER_CREDIT),
                    'reserved' => intdiv($wallet->reserved ?? 0, Millicredits::PER_CREDIT),
                    'transactions' => array_values(CreditTransaction::query()->orderByDesc('id')->limit(self::RECENT)->get()
                        ->map(static fn (CreditTransaction $transaction): array => [
                            'id' => $transaction->id,
                            'kind' => $transaction->kind,
                            'credits' => intdiv($transaction->amount, Millicredits::PER_CREDIT),
                            'balance_after' => intdiv($transaction->balance_after, Millicredits::PER_CREDIT),
                            'note' => $transaction->note,
                            'created_at' => $transaction->created_at->toIso8601String(),
                        ])->all()),
                ],
                // Tokens and credits only: cost_usd is never selected.
                'ai_usage_30d' => array_values(EngineCall::query()->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
                    ->selectRaw(<<<'SQL'
                        purpose, count(*) AS calls, count(*) FILTER (WHERE status <> 'ok') AS failed,
                        sum(input_tokens) AS input_tokens, sum(output_tokens) AS output_tokens,
                        sum(thinking_tokens) AS thinking_tokens, sum(millicredits) AS millicredits
                        SQL)
                    ->groupBy('purpose')->orderBy('purpose')->toBase()->get()
                    ->map(static fn (object $usage): array => [
                        'purpose' => (string) $usage->purpose,
                        'calls' => (int) $usage->calls,
                        'failed' => (int) $usage->failed,
                        'input_tokens' => (int) $usage->input_tokens,
                        'output_tokens' => (int) $usage->output_tokens,
                        'thinking_tokens' => (int) $usage->thinking_tokens,
                        'credits' => intdiv((int) $usage->millicredits, Millicredits::PER_CREDIT),
                    ])->all()),
                'notices' => array_values(Notice::query()->orderByDesc('id')->limit(10)->get()->map(static fn (Notice $notice): array => [
                    'id' => $notice->id,
                    'level' => $notice->level,
                    'title' => $notice->title,
                    'read' => $notice->read_at !== null,
                    'created_at' => $notice->created_at?->toIso8601String(),
                ])->all()),
            ];
        });

        return [
            'workspace' => $this->row($figures ?? $tenant),
            'plan' => [
                'started_at' => $tenant->plan_started_at?->toIso8601String(),
                'ends_at' => $tenant->plan_ends_at?->toIso8601String(),
                'status' => $tenant->plan_status,
                'overrides' => $tenant->limits_override,
                'period_start' => $period['start']->toIso8601String(),
                'period_end' => $period['end']->toIso8601String(),
                'limits' => [
                    'feeds' => $entitlements->limit('feeds'),
                    'languages' => $entitlements->limit('languages'),
                    'products' => $entitlements->productAllowance(),
                ],
            ],
            'members' => array_values($members->map(static function (User $member): array {
                /** @var object{role: string}|null $pivot */
                $pivot = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => (string) ($pivot->role ?? 'member'),
                    'verified' => $member->email_verified_at !== null,
                    'staff' => $member->is_staff,
                ];
            })->all()),
            ...$inside,
            'audit' => array_values(StaffAuditEntry::query()->where('subject_tenant_id', $tenant->id)->orderByDesc('id')->limit(10)->get()
                ->map(static fn (StaffAuditEntry $entry): array => self::auditLine($entry))->all()),
        ];
    }

    /**
     * The latest lines of the staff trail, newest first.
     *
     * @return list<array{id: int, user_email: string, action: string, method: string, path: string, status: int, subject_tenant_id: int|null, details: array<string, mixed>|null, created_at: string|null}>
     */
    public function audit(int $limit = 100): array
    {
        return array_values(StaffAuditEntry::query()->orderByDesc('id')->limit($limit)->get()
            ->map(static fn (StaffAuditEntry $entry): array => [
                ...self::auditLine($entry),
                'subject_tenant_id' => $entry->subject_tenant_id,
                'details' => $entry->details,
            ])->all());
    }

    /** @return array{id: int, user_email: string, action: string, method: string, path: string, status: int, created_at: string|null} */
    private static function auditLine(StaffAuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'user_email' => $entry->user_email,
            'action' => $entry->action,
            'method' => $entry->method,
            'path' => $entry->path,
            'status' => $entry->status,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /**
     * The workspace with the figures of its row, counted in the database (one query for a page).
     *
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    private static function withFigures(Builder $query): Builder
    {
        return $query->select('tenants.*')->selectRaw(<<<'SQL'
            (SELECT count(*) FROM feeds f WHERE f.tenant_id = tenants.id) AS feeds_count,
            (SELECT count(*) FROM feeds f WHERE f.tenant_id = tenants.id AND f.plan_paused_at IS NOT NULL) AS feeds_paused_count,
            (SELECT count(*) FROM tenant_languages l WHERE l.tenant_id = tenants.id) AS languages_count,
            (SELECT coalesce(max(w.balance - w.reserved), 0) FROM credit_wallets w WHERE w.tenant_id = tenants.id) AS available_millicredits,
            (SELECT coalesce(sum(e.millicredits), 0) FROM engine_calls e WHERE e.tenant_id = tenants.id AND e.created_at >= now() - interval '30 days') AS ai_millicredits_30d,
            (SELECT max(i.created_at) FROM imports i WHERE i.tenant_id = tenants.id) AS last_import_at
            SQL);
    }

    /** @return Row */
    private function row(Tenant $tenant): array
    {
        $entitlements = Entitlements::for($tenant);
        $plan = $entitlements->plan();
        $owner = $tenant->owner;
        $lastImport = $tenant->getAttribute('last_import_at');

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            // The plan in force: a lapsed paid term counts as Free.
            'plan' => $plan,
            'plan_name' => PlanCatalog::name($plan),
            'interval' => $entitlements->interval(),
            'internal' => $tenant->is_internal,
            'frozen' => $tenant->frozen_at !== null,
            'ai_paused' => $tenant->ai_paused_at !== null,
            'owner' => $owner instanceof User ? [
                'name' => $owner->name,
                'email' => $owner->email,
                'verified' => $owner->email_verified_at !== null,
            ] : null,
            'feeds' => (int) $tenant->getAttribute('feeds_count'),
            'feeds_paused' => (int) $tenant->getAttribute('feeds_paused_count'),
            'languages' => (int) $tenant->getAttribute('languages_count'),
            'products_this_period' => $entitlements->used('products'),
            'credits_available' => intdiv((int) $tenant->getAttribute('available_millicredits'), Millicredits::PER_CREDIT),
            'ai_credits_30d' => intdiv((int) $tenant->getAttribute('ai_millicredits_30d'), Millicredits::PER_CREDIT),
            'last_import_at' => $lastImport === null ? null : CarbonImmutable::parse((string) $lastImport)->toIso8601String(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
