@extends('shop.layout')

@section('content')
    @unless($stripeReady && $paypalReady)
        <div class="notice">
            @unless($stripeReady) Stripe is not configured — add <code>STRIPE_KEY</code>/<code>STRIPE_SECRET</code> to <code>.env</code>.<br>@endunless
            @unless($paypalReady) PayPal is not configured — add <code>PAYPAL_CLIENT_ID</code>/<code>PAYPAL_SECRET</code> to <code>.env</code>.@endunless
        </div>
    @endunless

    <div class="grid">
        @foreach($products as $key => $product)
            <div class="card">
                <div class="emoji">{{ $product['emoji'] }}</div>
                <h3>{{ $product['name'] }}</h3>
                <p class="muted">{{ $product['description'] }}</p>
                <div class="price">${{ number_format($product['price'] / 100, 2) }}</div>

                <form method="POST" action="{{ route('stripe.checkout', $key) }}">
                    @csrf
                    <button class="btn-stripe" @disabled(! $stripeReady)>Pay with Stripe</button>
                </form>
                <form method="POST" action="{{ route('paypal.checkout', $key) }}">
                    @csrf
                    <button class="btn-paypal" @disabled(! $paypalReady)>Pay with PayPal</button>
                </form>
            </div>
        @endforeach
    </div>

    <p class="muted" style="margin-top:24px">
        Stripe test card: <code>4242 4242 4242 4242</code>, any future expiry, any CVC.
        Decline: <code>4000 0000 0000 0002</code>.
        PayPal: log in with a sandbox <em>personal</em> account from your PayPal developer dashboard.
    </p>
@endsection
