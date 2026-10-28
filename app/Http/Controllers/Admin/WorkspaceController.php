<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ops\Workspaces;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\FeedResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The staff view of the workspaces (ADR 0026): credits and tokens, never a cost. */
final readonly class WorkspaceController
{
    public function __construct(private Workspaces $workspaces) {}

    /** Every workspace, newest first, 25 a page; `search` matches a name, a slug, the owner's email or an id. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $list = $this->workspaces->list((string) ($data['search'] ?? ''), (int) ($data['page'] ?? 1));

        return response()->json([
            /** @var list<array{id: int, name: string, slug: string, plan: string, plan_name: string, interval: string, internal: bool, frozen: bool, ai_paused: bool, owner: array{name: string, email: string, verified: bool}|null, feeds: int, feeds_paused: int, languages: int, products_this_period: int, credits_available: int, ai_credits_30d: int, last_import_at: string|null, created_at: string|null}> */
            'workspaces' => $list['rows'],
            'meta' => ['page' => $list['page'], 'pages' => $list['pages'], 'total' => $list['total']],
        ]);
    }

    /** One workspace in full: plan and usage, members, feeds, held work, credits, AI usage in credits and tokens, notices, and what staff did there. */
    public function show(string $workspace, TenantContext $context): JsonResponse
    {
        $workspace = $this->workspaces->find($workspace);
        $detail = $this->workspaces->detail($workspace);
        $feeds = $context->run($workspace->id, static fn () => Feed::query()->withSummary()->orderBy('id')->get());

        return response()->json([
            /** @var array{id: int, name: string, slug: string, plan: string, plan_name: string, interval: string, internal: bool, frozen: bool, ai_paused: bool, owner: array{name: string, email: string, verified: bool}|null, feeds: int, feeds_paused: int, languages: int, products_this_period: int, credits_available: int, ai_credits_30d: int, last_import_at: string|null, created_at: string|null} */
            'workspace' => $detail['workspace'],
            /** @var array{started_at: string|null, ends_at: string|null, status: string, overrides: array<string, int|null>|null, period_start: string, period_end: string, limits: array{feeds: int|null, languages: int|null, products: int|null}} */
            'plan' => $detail['plan'],
            /** @var list<array{id: int, name: string, email: string, role: string, verified: bool, staff: bool}> */
            'members' => $detail['members'],
            /** @var list<array{code: string, paused: bool}> */
            'languages' => $detail['languages'],
            /** The feeds as their workspace sees them. */
            'feeds' => FeedResource::collection($feeds),
            /**
             * Work that waits, by reason.
             *
             * @var list<array{reason: string, units: int, feeds: int}>
             */
            'held' => $detail['held'],
            /** @var array{available: int, reserved: int, transactions: list<array{id: int, kind: string, credits: int, balance_after: int, note: string|null, created_at: string}>} */
            'credits' => $detail['credits'],
            /** @var list<array{purpose: string, calls: int, failed: int, input_tokens: int, output_tokens: int, thinking_tokens: int, credits: int}> */
            'ai_usage_30d' => $detail['ai_usage_30d'],
            /** @var list<array{id: int, level: string, title: string, read: bool, created_at: string|null}> */
            'notices' => $detail['notices'],
            /** @var list<array{id: int, user_email: string, action: string, method: string, path: string, status: int, created_at: string|null}> */
            'audit' => $detail['audit'],
        ]);
    }
}
