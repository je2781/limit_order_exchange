<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    // Trade.php
    protected $fillable = ['buy_order_id', 'sell_order_id', 'symbol', 'price', 'amount'];
}
