<?php

declare(strict_types=1);

use App\Support\TypedEnv;

return [
    /*
     * Who may create an account: "open", "invite" (the private beta: a staff-issued code is
     * required) or "closed". Email and Google sign-up follow the same rule.
     */
    'registration' => TypedEnv::string('FEEDGENIX_REGISTRATION', 'invite'),

    // Where the SPA lives (the same origin as the API in production); used in email links.
    'frontend_url' => TypedEnv::string('FRONTEND_URL', TypedEnv::string('APP_URL', 'http://localhost')),

    // Days an invite code stays valid unless the command says otherwise.
    'invite_days' => 14,
];
