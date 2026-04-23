<?php

namespace App\Providers;

use App\Models\Team;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Subscriptions belong to the workspace (Team), not to an individual
        // user. Pointing Cashier at the Team model makes every internal
        // helper — findBillable, subscription(), webhook handlers — resolve
        // against `teams.stripe_id` instead of `users.stripe_id`.
        Cashier::useCustomerModel(Team::class);
    }
}
