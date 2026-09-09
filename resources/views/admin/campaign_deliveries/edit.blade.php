@extends('admin.layouts.app')

@section('page-title', 'Edit Campaign Delivery')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Edit Delivery #' . $delivery->id,
        'subtitle' => $delivery->campaignProduct?->product_title ?? 'Campaign delivery',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.campaign_deliveries.update', $delivery) }}" method="POST" class="card p-4">
        @include('admin.campaign_deliveries._form', ['delivery' => $delivery, 'statuses' => $statuses, 'campaignProducts' => $campaignProducts])
    </form>

</div>
@endsection
