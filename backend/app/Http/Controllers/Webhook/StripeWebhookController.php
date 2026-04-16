<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use Laravel\Cashier\Http\Controllers\WebhookController;

class StripeWebhookController extends WebhookController
{
    /**
     * Handle incoming Stripe webhook events.
     *
     * Extends the default Cashier webhook controller.
     * Override specific handlers as needed, e.g.:
     *
     *   protected function handleCustomerSubscriptionUpdated(array $payload): Response
     *   {
     *       // Custom logic for subscription changes (update team plan, etc.)
     *       return parent::handleCustomerSubscriptionUpdated($payload);
     *   }
     */
}
