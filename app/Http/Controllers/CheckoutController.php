<?php

namespace App\Http\Controllers;

use App\Models\Order;

class CheckoutController extends Controller
{
    public function cancel(Order $order)
    {
        if ($order->status === 'pending') {
            $order->update(['status' => 'cancelled']);
        }

        return redirect()->route('orders.show', $order);
    }
}
