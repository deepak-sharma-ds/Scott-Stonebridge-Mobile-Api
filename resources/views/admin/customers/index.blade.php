@extends('admin.layouts.app')

@section('content')
    <div class="container">

        <div class="d-flex justify-content-between mb-3">
            <h4>Customers</h4>
            <a href="{{ route('admin.customers.export') }}" class="btn btn-success" target="_blank">Export CSV</a>
        </div>

        <form method="GET" class="card p-3 mb-3">
            <div class="row g-2">
                <div class="col-md-3">
                    <select name="filter" class="form-select">
                        <option value="" {{ $request->filter === null ? 'selected' : '' }}>All Customers</option>
                        <option value="active" {{ $request->filter === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ $request->filter === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="top_buyers" {{ $request->filter === 'top_buyers' ? 'selected' : '' }}>Top Buyers
                        </option>
                    </select>
                </div>

                <div class="col-md-6">
                    <input type="text" name="search" value="{{ $request->search }}"
                        placeholder="Search name, email, phone" class="form-control">
                </div>

                <div class="col-md-3">
                    <button class="btn btn-primary w-100">Search</button>
                </div>
            </div>
        </form>

        <div class="card p-3">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Total Spent</th>
                        <th>Orders</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($customers as $c)
                        <tr>
                            <td>{{ $c['firstName'] . ' ' . $c['lastName'] }}</td>
                            <td>{{ $c['email'] }}</td>
                            <td>${{ $c['amountSpent']['amount'] }} ({{ $c['amountSpent']['currencyCode'] }})</td>
                            <td>{{ $c['numberOfOrders'] }}</td>
                            <td>
                                <a href="{{ route('admin.customers.show', last(explode('/', $c['id']))) }}"
                                    class="btn btn-sm btn-info">Details</a>
                                {{-- <a href="{{ route('admin.customers.show', $c['id']) }}"
                                    class="btn btn-sm btn-info">Details</a> --}}
                            </td>
                        </tr>
                    @endforeach
                </tbody>

            </table>

            @if ($hasNextPage)
                <a href="?after={{ $nextCursor }}" class="btn btn-light w-100">
                    Load More
                </a>
            @endif

        </div>

    </div>
@endsection
