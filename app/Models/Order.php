<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = ['user_id', 'symbol', 'side', 'price', 'amount', 'status', 'locked_volume'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
