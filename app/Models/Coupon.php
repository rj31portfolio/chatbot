<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id', 'redemptions'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
