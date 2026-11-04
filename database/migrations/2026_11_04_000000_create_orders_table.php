<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('provider');                   // stripe | paypal
            $table->string('provider_ref')->nullable()->index(); // Stripe session id / PayPal order id
            $table->string('product');
            $table->unsignedInteger('amount');            // cents
            $table->string('currency', 3);
            $table->string('status')->default('pending'); // pending | paid | cancelled | failed
            $table->json('payload')->nullable();          // last raw response, for learning/debugging
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
