<?php

namespace App\Http\Controllers;

use App\Models\Order;

class ShopController extends Controller
{
    public function index()
    {
        return view('shop.index', [
            'products' => config('shop.products'),
            'currency' => config('shop.currency'),
            'stripeReady' => filled(config('services.stripe.secret')),
            'paypalReady' => filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret')),
        ]);
    }

    public function orders()
    {
        return view('shop.orders', ['orders' => Order::latest()->get()]);
    }

    public function show(Order $order)
    {
        return view('shop.show', ['order' => $order]);
    }
}
