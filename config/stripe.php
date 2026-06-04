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
        // Stripe's published webhook source IPs (https://docs.stripe.com/ips).
        'ips' => [
            '3.18.12.63', '3.130.192.231', '13.235.14.237', '13.235.122.149',
            '18.211.135.69', '35.154.171.200', '52.15.183.38', '54.88.130.119',
            '54.88.130.237', '54.187.174.169', '54.187.205.235', '54.187.216.72',
        ],
    ],

];
