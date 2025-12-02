@extends('admin.layouts.app')

@section('content')
    <div class="container">

        <h4>Customer Details</h4>

        <div class="card p-3 mb-3">
            <h5>{{ $customer['firstName'] . ' ' . $customer['lastName'] }}</h5>
            <p>Email: {{ $customer['email'] }}</p>
            <p>Phone: {{ $customer['phone'] }}</p>
            <p>Total Orders: {{ $customer['numberOfOrders'] }}</p>
            <p>Total Spent: ${{ $customer['amountSpent']['amount'] }} ({{ $customer['amountSpent']['currencyCode'] }})</p>
        </div>

        <div class="d-flex gap-2">

            @php
                $isSuspended =
                    collect($customer['metafields']['edges'] ?? [])->firstWhere('node.key', 'suspended')['node'][
                        'value'
                    ] ?? '0';
            @endphp

            @if ($isSuspended === '1')
                <form method="POST" action="{{ route('admin.customers.unsuspend', $customer['id']) }}">
                    @csrf
                    <button class="btn btn-warning">Unsuspend</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.customers.suspend', $customer['id']) }}">
                    @csrf
                    <button class="btn btn-danger">Suspend</button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.customers.destroy', $customer['id']) }}">
                @csrf @method('DELETE')
                <button class="btn btn-dark">Delete Customer</button>
            </form>

        </div>

    </div>
@endsection
