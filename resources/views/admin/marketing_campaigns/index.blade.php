@extends('admin.layouts.app')

@section('page-title', 'Marketing Campaigns')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Marketing Campaigns',
        'subtitle' => 'Campaign Email Automation — attribute purchases from unlisted-product campaign links',
        'action'   => '<a href="' . route('admin.marketing-campaigns.create') . '" class="btn btn-primary">+ New Campaign</a>',
    ])

    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Key</th>
                        <th>Klaviyo ID</th>
                        <th class="text-center">Products</th>
                        <th class="text-center">Status</th>
                        <th class="text-end" style="width:200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $campaign)
                        <tr>
                            <td style="font-weight:600;color:var(--text-primary);">{{ $campaign->name }}</td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $campaign->campaign_key }}</td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $campaign->klaviyo_campaign_id ?: '—' }}</td>
                            <td class="text-center">
                                <x-admin.badge type="secondary">{{ $campaign->campaign_products_count }}</x-admin.badge>
                            </td>
                            <td class="text-center">
                                <x-admin.badge :type="$campaign->status === 'active' ? 'success' : 'secondary'">
                                    {{ \App\Models\MarketingCampaign::statusLabels()[$campaign->status] ?? $campaign->status }}
                                </x-admin.badge>
                            </td>
                            <td class="text-end">
                                <div style="display:inline-flex;gap:0.5rem;">
                                    <a href="{{ route('admin.marketing-campaigns.show', $campaign) }}" class="btn btn-sm btn-secondary">Manage</a>
                                    <a href="{{ route('admin.marketing-campaigns.edit', $campaign) }}" class="btn btn-sm btn-warning">Edit</a>
                                    <form action="{{ route('admin.marketing-campaigns.destroy', $campaign) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Delete '{{ $campaign->name }}'? This removes all linked products and responses.">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @include('admin.components.empty-state', ['message' => 'No marketing campaigns yet.'])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($campaigns->hasPages())
            <div style="margin-top:1.25rem;">
                {!! $campaigns->links('pagination::bootstrap-5') !!}
            </div>
        @endif
    </div>

</div>
@endsection
