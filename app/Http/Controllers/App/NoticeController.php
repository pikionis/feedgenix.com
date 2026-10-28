<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Notices\Models\Notice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoticeController
{
    /** The workspace's latest notices, newest first, with how many are unread. */
    public function index(): JsonResponse
    {
        return response()->json([
            /** @var list<array{id: int, kind: string, level: 'info'|'warn'|'action', title: string, body: string, action_label: string|null, action_path: string|null, read: bool, created_at: string|null}> */
            'notices' => $this->latest(),
            'unread' => Notice::query()->whereNull('read_at')->count(),
        ]);
    }

    /** Mark notices read: the ones listed, or all of them. Everyone in the workspace sees the same notices. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        Notice::query()->whereNull('read_at')
            ->when(isset($data['ids']), static fn ($notices) => $notices->whereKey(array_map(intval(...), (array) $data['ids'])))
            ->update(['read_at' => now()]);

        return response()->json([
            /** @var list<array{id: int, kind: string, level: 'info'|'warn'|'action', title: string, body: string, action_label: string|null, action_path: string|null, read: bool, created_at: string|null}> */
            'notices' => $this->latest(),
            'unread' => Notice::query()->whereNull('read_at')->count(),
        ]);
    }

    /** @return list<array{id: int, kind: string, level: string, title: string, body: string, action_label: string|null, action_path: string|null, read: bool, created_at: string|null}> */
    private function latest(): array
    {
        return array_values(Notice::query()->orderByDesc('id')->limit(30)->get()->map(static fn (Notice $notice): array => [
            'id' => $notice->id, 'kind' => $notice->kind, 'level' => $notice->level, 'title' => $notice->title, 'body' => $notice->body,
            'action_label' => $notice->action_label, 'action_path' => $notice->action_path,
            'read' => $notice->read_at !== null, 'created_at' => $notice->created_at?->toIso8601String(),
        ])->all());
    }
}
