@extends('admin.layouts.app')

@section('page-title', 'New Marketing Campaign')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'New Marketing Campaign',
        'subtitle' => 'Register a Klaviyo campaign for attributed purchase tracking',
        'action'   => '<a href="' . route('admin.marketing-campaigns.index') . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.marketing-campaigns.store') }}" method="POST" class="card p-4">
        @include('admin.marketing_campaigns._form')
    </form>

</div>
@endsection
