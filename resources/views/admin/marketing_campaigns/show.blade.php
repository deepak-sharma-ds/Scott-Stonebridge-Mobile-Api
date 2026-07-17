@extends('admin.layouts.app')

@section('page-title', $campaign->name)

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => $campaign->name,
        'subtitle' => 'Key: ' . $campaign->campaign_key . ' — Status: ' . (\App\Models\MarketingCampaign::statusLabels()[$campaign->status] ?? $campaign->status),
        'action'   => '<a href="' . route('admin.marketing-campaigns.index') . '" class="btn btn-secondary">← Back</a> '
                    . '<a href="' . route('admin.marketing-campaigns.edit', $campaign) . '" class="btn btn-warning">Edit Campaign</a>',
    ])

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="card p-4" style="margin-bottom:1.5rem;">
        <h3 style="margin-top:0;">Link a product</h3>
        <form action="{{ route('admin.marketing-campaigns.products.store', $campaign) }}" method="POST">
            @csrf
            <div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:1rem;">
                <div class="mb-3">
                    <label for="shopify_product_id" class="form-label">Shopify Product ID</label>
                    <input type="number" name="shopify_product_id" id="shopify_product_id" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="shopify_variant_id" class="form-label">Shopify Variant ID <small style="color:var(--text-muted);">(for the campaign link)</small></label>
                    <input type="number" name="shopify_variant_id" id="shopify_variant_id" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="product_title" class="form-label">Product Title <small style="color:var(--text-muted);">(display only)</small></label>
                    <input type="text" name="product_title" id="product_title" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="email_subject" class="form-label">Email Subject</label>
                    <input type="text" name="email_subject" id="email_subject" class="form-control">
                </div>
            </div>
            <div class="mb-3">
                <label for="prompt_template" class="form-label">Prompt Template <small style="color:var(--text-muted);">(Blade template; @{{ $productTitle }} / @{{ $campaignName }} available)</small></label>
                <textarea name="prompt_template" id="prompt_template" class="form-control" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Link Product</button>
        </form>
    </div>

    <div class="card p-4">
        <h3 style="margin-top:0;">Linked products</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Shopify ID</th>
                        <th class="text-center">Response</th>
                        <th>Campaign Link</th>
                        <th class="text-end" style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaign->campaignProducts as $campaignProduct)
                        <tr>
                            <td style="font-weight:600;color:var(--text-primary);">{{ $campaignProduct->product_title ?: '—' }}</td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $campaignProduct->shopify_product_id }}</td>
                            <td class="text-center">
                                <x-admin.badge :type="$campaignProduct->response ? 'success' : 'secondary'">
                                    {{ $campaignProduct->response ? 'Generated' : 'Not generated' }}
                                </x-admin.badge>
                            </td>
                            <td style="max-width:220px;">
                                @if($campaignLinks[$campaignProduct->id] ?? null)
                                    <div x-data="{ copied: false }" style="display:flex;gap:0.5rem;align-items:center;">
                                        <input type="text" readonly value="{{ $campaignLinks[$campaignProduct->id] }}"
                                               class="form-control form-control-sm" style="font-size:0.75rem;"
                                               onclick="this.select()">
                                        <button type="button" class="btn btn-sm btn-secondary"
                                                x-on:click="navigator.clipboard.writeText('{{ $campaignLinks[$campaignProduct->id] }}'); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-show="!copied">Copy</span>
                                            <span x-show="copied" x-cloak>Copied!</span>
                                        </button>
                                    </div>
                                @else
                                    <small style="color:var(--text-muted);">Set a Shopify Variant ID to generate a link.</small>
                                @endif
                            </td>
                            <td class="text-end">
                                <div style="display:inline-flex;gap:0.5rem;">
                                    <form action="{{ route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            {{ $campaignProduct->response ? 'Regenerate' : 'Generate' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.marketing-campaigns.products.destroy', [$campaign, $campaignProduct]) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Unlink this product from the campaign?">Unlink</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @if($campaignProduct->response)
                            <tr>
                                <td colspan="5" style="background:var(--surface-muted, rgba(0,0,0,0.02));white-space:pre-wrap;font-size:0.875rem;color:var(--text-secondary);">
                                    {{ $campaignProduct->response->ai_response }}
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5">
                                @include('admin.components.empty-state', ['message' => 'No products linked to this campaign yet.'])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
