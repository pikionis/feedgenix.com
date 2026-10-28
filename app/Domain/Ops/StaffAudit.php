<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Ops\Models\StaffAuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The staff trail (ADR 0026). The staff middleware writes one line per request once it has its
 * answer; a change names itself and its details first with describe(), so nothing a member of staff
 * does goes unrecorded, and nothing is recorded twice.
 */
final class StaffAudit
{
    private const string ACTION = 'staff_audit.action';

    private const string DETAILS = 'staff_audit.details';

    /**
     * Name what this request does ("grant_credits") and what it changed.
     *
     * @param  array<string, mixed>  $details
     */
    public static function describe(Request $request, string $action, array $details = []): void
    {
        $request->attributes->set(self::ACTION, $action);
        $request->attributes->set(self::DETAILS, $details);
    }

    public function record(User $staff, Request $request, int $status): StaffAuditEntry
    {
        $action = $request->attributes->get(self::ACTION);
        $details = $request->attributes->get(self::DETAILS);

        return StaffAuditEntry::query()->forceCreate([
            'user_id' => $staff->id,
            'user_email' => $staff->email,
            // An undescribed change is still a change: it is recorded as one.
            'action' => is_string($action) ? $action : ($request->isMethodSafe() ? 'view' : 'change'),
            'method' => $request->method(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'status' => $status,
            'subject_tenant_id' => $this->subject($request),
            'details' => is_array($details) && $details !== [] ? $details : null,
            'ip' => $request->ip(),
        ]);
    }

    /** The workspace in the route: bound, or the raw id when binding failed (a 404 is recorded too). */
    private function subject(Request $request): ?int
    {
        $workspace = $request->route('workspace');

        return match (true) {
            $workspace instanceof Tenant => $workspace->id,
            is_string($workspace) && ctype_digit($workspace) && strlen($workspace) < 19 => (int) $workspace,
            default => null,
        };
    }
}
