@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Edit Customer Entitlement</h2>
                <p class="text-muted mb-0">Add one or more customer emails to the same package entitlement.</p>
            </div>
            <a href="{{ route('admin.customer.entitlements.index') }}" class="btn btn-outline-primary">Back to list</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Oops!</strong> Please fix the following errors:
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card p-4 shadow-sm h-100">
                    <h5 class="mb-3">Current Entitlement</h5>

                    <div class="mb-3">
                        <label class="form-label text-muted">Package</label>
                        <div class="fw-semibold">{{ $package?->title ?: 'Package not found' }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted">Package Tag</label>
                        <div>
                            <span class="badge bg-primary">{{ $customerEntitlement->package_tag }}</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted">Current Emails</label>
                        <div class="fw-semibold">{{ $customerEntitlement->email ?: 'N/A' }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted">Shopify Customer ID</label>
                        <div class="fw-semibold">{{ $customerEntitlement->shopify_customer_id }}</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label text-muted">Download Allowed</label>
                        <div class="fw-semibold">
                            {{ $customerEntitlement->is_download_allowed ? 'Yes' : 'No' }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <form action="{{ route('admin.customer.entitlements.update', $customerEntitlement) }}" method="POST"
                    class="card p-4 shadow-sm">
                    @csrf
                    @method('PUT')

                    <h5 class="mb-3">Add More Emails</h5>
                    <p class="text-muted mb-4">
                        Enter one or more emails. They will be appended to this selected entitlement row only and tagged
                        in Shopify with <strong>{{ $customerEntitlement->package_tag }}</strong>.
                    </p>

                    <div class="mb-3">
                        <label for="emails" class="form-label">Customer Emails</label>
                        <textarea name="emails" id="emails" rows="10" class="form-control" placeholder="name@example.com&#10;second@example.com">{{ old('emails') }}</textarea>
                        <small class="text-muted">
                            One email per line is recommended. Saved values are stored in the entitlement row as a comma-separated list.
                        </small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">Save Emails</button>
                        <a href="{{ route('admin.customer.entitlements.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
