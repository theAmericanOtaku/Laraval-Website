<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['provider', 'provider_ref', 'product', 'amount', 'currency', 'status', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function formattedAmount(): string
    {
        return number_format($this->amount / 100, 2).' '.strtoupper($this->currency);
    }
}
