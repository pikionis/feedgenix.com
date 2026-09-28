<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

// Legacy defect D21: plan and owner fields were mass-assignable.

it('never mass-assigns platform or ownership fields on a workspace', function (string $field, mixed $value): void {
    expect(fn (): Tenant => new Tenant([$field => $value]))->toThrow(MassAssignmentException::class);
})->with([
    'plan' => ['plan', 'custom'],
    'owner' => ['owner_id', 1],
    'slug' => ['slug', 'taken'],
    'internal flag' => ['is_internal', true],
    'frozen' => ['frozen_at', now()],
    'limits' => ['limits_override', ['feeds' => null]],
    'period' => ['plan_ends_at', now()->addYears(10)],
]);

it('never mass-assigns staff access or the active workspace on a user', function (string $field, mixed $value): void {
    expect(fn (): User => new User([$field => $value]))->toThrow(MassAssignmentException::class);
})->with([
    'staff' => ['is_staff', true],
    'workspace' => ['current_tenant_id', 1],
    'google id' => ['google_id', '123'],
    'verified' => ['email_verified_at', now()],
]);

it('still fills the fields a person may change', function (): void {
    $user = new User(['name' => 'N', 'email' => 'n@example.com', 'locale' => 'de']);
    $tenant = new Tenant(['name' => 'Shop']);

    expect($user->name)->toBe('N')->and($user->locale)->toBe('de')->and($tenant->name)->toBe('Shop');
});
