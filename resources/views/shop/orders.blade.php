@extends('shop.layout')

@section('content')
    <h2>Orders</h2>
    <table>
        <tr><th>#</th><th>Provider</th><th>Product</th><th>Amount</th><th>Status</th><th>Created</th></tr>
        @forelse($orders as $order)
            <tr>
                <td><a href="{{ route('orders.show', $order) }}">{{ $order->id }}</a></td>
                <td>{{ ucfirst($order->provider) }}</td>
                <td>{{ $order->product }}</td>
                <td>{{ $order->formattedAmount() }}</td>
                <td><span class="badge {{ $order->status }}">{{ $order->status }}</span></td>
                <td>{{ $order->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No orders yet — go buy something!</td></tr>
        @endforelse
    </table>
@endsection
