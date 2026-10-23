<?php

declare(strict_types=1);

/*
 * Prepaid credits (ADR 0005, ADR 0016). The ledger counts integer millicredits; nothing in it is a
 * float. Customers see credits only, never euros of provider cost.
 */
return [
    // 1 credit = €0.0001, so 100,000 credits = €10.
    'credit_eur' => '0.0001',

    // An unsettled reservation is settled (consumed part charged, the rest released) after this.
    'reservation_ttl_minutes' => 60,
];
