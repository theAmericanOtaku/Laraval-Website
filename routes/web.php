<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StripeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShopController::class, 'index'])->name('shop');
Route::get('/orders', [ShopController::class, 'orders'])->name('orders');
Route::get('/orders/{order}', [ShopController::class, 'show'])->name('orders.show');

Route::post('/checkout/stripe/{product}', [StripeController::class, 'checkout'])->name('stripe.checkout');
Route::get('/checkout/stripe/success', [StripeController::class, 'success'])->name('stripe.success');
Route::post('/webhooks/stripe', [StripeController::class, 'webhook'])->name('stripe.webhook');

Route::post('/checkout/paypal/{product}', [PayPalController::class, 'checkout'])->name('paypal.checkout');
Route::get('/checkout/paypal/return', [PayPalController::class, 'return'])->name('paypal.return');

Route::get('/checkout/cancel/{order}', [CheckoutController::class, 'cancel'])->name('checkout.cancel');
