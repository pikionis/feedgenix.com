<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Notices\Models\Notice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Tells a workspace something, once per key (ADR 0025): in the app for everyone in it, and by
 * email to its owner and admins with a verified address when it needs them to act. The caller is
 * in the workspace's context.
 */
final class Notify
{
    /** @return bool whether it was new (a notice with the same key is never sent twice) */
    public static function send(int $tenantId, string $kind, string $key, string $level, string $title, string $body, ?string $actionLabel = null, ?string $actionPath = null, bool $email = false): bool
    {
        $created = DB::table('notices')->insertOrIgnore([
            'tenant_id' => $tenantId, 'kind' => $kind, 'key' => $key, 'level' => $level, 'title' => $title, 'body' => $body,
            'action_label' => $actionLabel, 'action_path' => $actionPath, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($created === 0 || ! $email) {
            return $created > 0;
        }

        $notice = Notice::query()->where('key', $key)->firstOrFail();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $managers = User::query()->whereNotNull('email_verified_at')
            ->whereIn('id', DB::table('tenant_user')->where('tenant_id', $tenantId)->whereIn('role', ['owner', 'admin'])->select('user_id'))
            ->get();
        if ($managers->isNotEmpty()) {
            Notification::send($managers, NoticeMail::of($notice, $tenant->name));
            $notice->forceFill(['emailed_at' => now()])->save();
        }

        return true;
    }
}
