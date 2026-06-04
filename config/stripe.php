<?php

return [

    /*
    | Webhook handling. `idempotent` (default true) enables the event-ledger
    | middleware that dedupes Stripe's retried/duplicate deliveries. `ip_allowlist`
    | (default false) additionally rejects requests not from Stripe's IP ranges.
    */

    'webhook' => [
        'idempotent' => env('STRIPE_WEBHOOK_IDEMPOTENT', true),
        'ip_allowlist' => env('STRIPE_WEBHOOK_IP_ALLOWLIST', false),
    ],

];
