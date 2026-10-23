<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;

/**
 * First link of the gateway (ADR 0004): may this workspace make a paid call at all? The kill switch,
 * the call's own workspace context, a frozen or AI-paused workspace, an unverified owner and a
 * missing reservation all stop it before anything is spent.
 */
final readonly class PolicyGate implements AiClient
{
    public function __construct(
        private AiClient $next,
        private TenantContext $context,
        private bool $enabled,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        if (! $this->enabled) {
            throw AiDenied::because('ai_disabled', 'AI is switched off.');
        }
        // Explicit and ambient must agree: a call is recorded and charged as the workspace in context.
        if ($this->context->isSystem() || $this->context->tenantId() !== $request->tenantId) {
            throw AiDenied::because('tenant_mismatch', 'A model call must run in its own workspace context.');
        }

        $tenant = Tenant::query()->find($request->tenantId)
            ?? throw AiDenied::because('unknown_workspace', 'The workspace does not exist.');
        if ($tenant->isFrozen()) {
            throw AiDenied::because('workspace_frozen', 'The workspace is frozen.');
        }
        if ($tenant->ai_paused_at !== null) {
            throw AiDenied::because('ai_paused', 'AI is paused for this workspace.');
        }
        if (! User::query()->whereKey($tenant->owner_id)->whereNotNull('email_verified_at')->exists()) {
            throw AiDenied::because('owner_unverified', "The workspace owner's email address is not verified.");
        }
        if ($request->reservationId === null) {
            throw AiDenied::because('no_reservation', 'A model call needs a credit reservation.');
        }

        return $this->next->generate($request);
    }
}
