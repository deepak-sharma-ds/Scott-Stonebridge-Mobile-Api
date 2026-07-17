@extends('admin.layouts.app')

@section('page-title', 'Edit Campaign')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Edit: ' . $campaign->name,
        'subtitle' => 'Update campaign details',
        'action'   => '<a href="' . route('admin.marketing-campaigns.show', $campaign) . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.marketing-campaigns.update', $campaign) }}" method="POST" class="card p-4">
        @include('admin.marketing_campaigns._form', ['campaign' => $campaign])
    </form>

</div>
@endsection
