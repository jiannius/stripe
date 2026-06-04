<?php

namespace Jiannius\Stripe\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
