@extends('shop.layout')

@if(request()->boolean('confirming') && $order->status === 'pending')
    {{-- Just back from Stripe: reload every 3 seconds until the webhook marks the order. --}}
    @push('head')<meta http-equiv="refresh" content="3">@endpush
@endif

@section('content')
    @if(session('notice'))
        <div class="flash">{{ session('notice') }}</div>
    @endif
    @if(request()->boolean('confirming') && $order->status === 'pending')
        <p class="muted">Waiting for Stripe's webhook to confirm this payment… this page refreshes itself.</p>
    @endif
    <div class="card">
        <h2>Order #{{ $order->id }} <span class="badge {{ $order->status }}">{{ $order->status }}</span></h2>
        <p>{{ ucfirst($order->provider) }} · {{ $order->product }} · {{ $order->formattedAmount() }}</p>
        <p class="muted">Provider reference: <code>{{ $order->provider_ref }}</code></p>
        <h3>Last response from {{ ucfirst($order->provider) }}</h3>
        <p class="muted">This is the raw data the API returned — handy for learning what's in it.</p>
        <pre>{{ json_encode($order->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        <a href="{{ route('shop') }}">← Back to shop</a>
    </div>
@endsection
