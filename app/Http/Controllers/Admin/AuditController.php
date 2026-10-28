<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\Workspaces;
use Illuminate\Http\JsonResponse;

/** The staff trail (ADR 0026): the latest 100 lines, newest first. */
final readonly class AuditController
{
    public function __construct(private Workspaces $workspaces) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            /** @var list<array{id: int, user_email: string, action: string, method: string, path: string, status: int, subject_tenant_id: int|null, details: array<string, mixed>|null, created_at: string|null}> */
            'audit' => $this->workspaces->audit(),
        ]);
    }
}
