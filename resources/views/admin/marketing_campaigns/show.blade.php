@extends('admin.layouts.app')

@section('page-title', $campaign->name)

@section('content')
    <div class="container-fluid">

        @include('admin.components.page-header', [
            'title' => $campaign->name,
            'subtitle' =>
                'Key: ' .
                $campaign->campaign_key .
                ' — Status: ' .
                (\App\Models\MarketingCampaign::statusLabels()[$campaign->status] ?? $campaign->status),
            'action' =>
                '<a href="' .
                route('admin.marketing-campaigns.index') .
                '" class="btn btn-secondary">← Back</a> ' .
                '<a href="' .
                route('admin.marketing-campaigns.edit', $campaign) .
                '" class="btn btn-warning">Edit Campaign</a>',
        ])

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul style="margin:0;padding-left:1.25rem;">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card p-4" x-data="{ source: 'manual' }" style="margin-bottom:1.5rem;">
            <h3 style="margin-top:0;">Link a product</h3>
            <form action="{{ route('admin.marketing-campaigns.products.store', $campaign) }}" method="POST"
                enctype="multipart/form-data"
                x-data="productPicker(@js(route('admin.marketing-campaigns.products.available', $campaign)))" x-init="load()">
                @csrf
                <div class="row" style="display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:1rem;">
                    <div class="mb-3 product-picker" @click.outside="open = false">
                        <label class="form-label">Unlisted Shopify Product</label>

                        <template x-if="!selected">
                            <div style="position:relative;">
                                <input type="text" class="form-control" placeholder="Search unlisted products…"
                                    x-model="query" @focus="open = true" @input="open = true"
                                    :disabled="loading || !!loadError">
                                <div x-show="loading"
                                    style="font-size:0.75rem;color:var(--text-muted);margin-top:0.375rem;">Loading products…
                                </div>
                                <div x-show="loadError" x-text="loadError"
                                    style="font-size:0.75rem;color:var(--color-danger);margin-top:0.375rem;"></div>

                                <div x-show="open && !loading" x-cloak class="product-picker-panel">
                                    <template x-for="product in filtered()" :key="product.id">
                                        <div class="product-picker-row" @click="pick(product)">
                                            <div class="product-picker-thumb">
                                                <img x-show="product.image_url" :src="product.image_url"
                                                    :alt="product.title">
                                                <span x-show="!product.image_url">🛍</span>
                                            </div>
                                            <div style="min-width:0;">
                                                <div style="font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                    x-text="product.title"></div>
                                                <div style="font-size:0.75rem;color:var(--text-muted);"
                                                    x-text="variantSummary(product)"></div>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!loading && filtered().length === 0"
                                        style="padding:0.75rem;font-size:0.8125rem;color:var(--text-muted);">
                                        No matching unlisted products.
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-if="selected">
                            <div>
                                <div class="product-picker-chip">
                                    <div class="product-picker-thumb">
                                        <img x-show="selected.image_url" :src="selected.image_url" :alt="selected.title">
                                        <span x-show="!selected.image_url">🛍</span>
                                    </div>
                                    <div style="min-width:0;flex:1;">
                                        <div style="font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                            x-text="selected.title"></div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);"
                                            x-text="variantSummary(selected)"></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-secondary" @click="clear()">Change</button>
                                </div>

                                <div x-show="selected.variants.length > 1" x-cloak class="product-picker-variants">
                                    <template x-for="variant in selected.variants" :key="variant.id">
                                        <button type="button" class="product-picker-variant-pill"
                                            :class="{ 'is-active': variantId === variant.id }"
                                            @click="variantId = variant.id">
                                            <span x-text="variant.title"></span>
                                            <span style="opacity:0.65;" x-text="'£' + variant.price"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <input type="hidden" name="shopify_product_id" :value="selected ? selected.id : ''">
                        <input type="hidden" name="shopify_variant_id" :value="variantId ?? ''">
                    </div>
                    <div class="mb-3">
                        <label for="product_title" class="form-label">Product Title <small
                                style="color:var(--text-muted);">(display only)</small></label>
                        <input type="text" name="product_title" id="product_title" class="form-control" x-model="title">
                    </div>
                    <div class="mb-3">
                        <label for="email_subject" class="form-label">Email Subject</label>
                        <input type="text" name="email_subject" id="email_subject" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="header_image" class="form-label">Header Banner Image <small
                            style="color:var(--text-muted);">(shown at the top of the email; falls back to the site
                            logo if left blank)</small></label>
                    <input type="file" name="header_image" id="header_image" class="form-control" accept="image/*">
                </div>
                <div class="mb-3">
                    <label for="email_content" class="form-label">Email Content <small
                            style="color:var(--text-muted);">(shown above the reading; @{{ $productTitle }} /
                            @{{ $campaignName }} available; leave blank to use the default copy)</small></label>
                    <textarea name="email_content" id="email_content" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label for="email_footer" class="form-label">Email Footer <small
                            style="color:var(--text-muted);">(shown below the reading; @{{ $productTitle }} /
                            @{{ $campaignName }} available; leave blank to use the default copy)</small></label>
                    <textarea name="email_footer" id="email_footer" class="form-control" rows="5"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Response Source</label>
                    <div style="display:flex;gap:1.5rem;">
                        <label><input type="radio" name="source" value="ai" x-model="source"> Generate with
                            AI</label>
                        <label><input type="radio" name="source" value="manual" x-model="source" checked> Paste manual
                            response</label>
                    </div>
                </div>
                <div class="mb-3" x-show="source === 'ai'" x-cloak>
                    <label for="prompt_template" class="form-label">Prompt Template <small
                            style="color:var(--text-muted);">(Blade template; @{{ $productTitle }} /
                            @{{ $campaignName }} available)</small></label>
                    <textarea name="prompt_template" id="prompt_template" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3" x-show="source === 'manual'" x-cloak>
                    <label for="body" class="form-label">Response Body <small
                            style="color:var(--text-muted);">(optional — leave blank and add it later)</small></label>
                    <textarea name="body" id="body" class="form-control" rows="4"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" :disabled="!selected || !variantId">Link Product</button>
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
                    @forelse($campaign->campaignProducts as $campaignProduct)
                        <tbody x-data="{ formOpen: false, source: '{{ $campaignProduct->response->source ?? 'manual' }}' }">
                            <tr>
                                <td style="font-weight:600;color:var(--text-primary);">
                                    {{ $campaignProduct->product_title ?: '—' }}</td>
                                <td style="font-size:0.8125rem;color:var(--text-secondary);">
                                    {{ $campaignProduct->shopify_product_id }}</td>
                                <td class="text-center">
                                    <x-admin.badge :type="$campaignProduct->response ? ($campaignProduct->response->source === 'ai' ? 'success' : 'info') : 'secondary'">
                                        {{ $campaignProduct->response ? \App\Models\CampaignProductResponse::sourceLabels()[$campaignProduct->response->source] : 'Not generated' }}
                                    </x-admin.badge>
                                </td>
                                <td style="max-width:220px;">
                                    @if ($campaignLinks[$campaignProduct->id] ?? null)
                                        <div x-data="{ copied: false }" style="display:flex;gap:0.5rem;align-items:center;">
                                            <input type="text" readonly
                                                value="{{ $campaignLinks[$campaignProduct->id] }}"
                                                class="form-control form-control-sm" style="font-size:0.75rem;"
                                                onclick="this.select()">
                                            <button type="button" class="btn btn-sm btn-secondary"
                                                x-on:click="copyCampaignLink('{{ $campaignLinks[$campaignProduct->id] }}'); copied = true; setTimeout(() => copied = false, 1500)">
                                                <span x-show="!copied">Copy</span>
                                                <span x-show="copied" x-cloak>Copied!</span>
                                            </button>
                                        </div>
                                    @else
                                        <small style="color:var(--text-muted);">Set a Shopify Variant ID to generate a
                                            link.</small>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div style="display:inline-flex;gap:0.5rem;">
                                        <button type="button" class="btn btn-sm btn-primary"
                                            x-on:click="formOpen = !formOpen">
                                            {{ $campaignProduct->response ? 'Edit Response' : 'Add Response' }}
                                        </button>
                                        <form
                                            action="{{ route('admin.marketing-campaigns.products.destroy', [$campaign, $campaignProduct]) }}"
                                            method="POST" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Unlink this product from the campaign?">Unlink</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @if ($campaignProduct->response)
                                <tr>
                                    <td colspan="5"
                                        style="background:var(--surface-muted, rgba(0,0,0,0.02));white-space:pre-wrap;font-size:0.875rem;color:var(--text-secondary);">
                                        {{ $campaignProduct->response->body }}
                                    </td>
                                </tr>
                            @endif
                            <tr x-show="formOpen" x-cloak>
                                <td colspan="5" style="background:var(--surface-muted, rgba(0,0,0,0.02));">
                                    <form
                                        action="{{ route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]) }}"
                                        method="POST" enctype="multipart/form-data">
                                        @csrf
                                        <div class="mb-3">
                                            <label class="form-label">Header Banner Image <small
                                                    style="color:var(--text-muted);">(leave blank to keep the
                                                    current one)</small></label>
                                            @if ($campaignProduct->header_image)
                                                <div style="margin-bottom:0.5rem;">
                                                    <img src="{{ asset('storage/campaign-header-images/'.$campaignProduct->header_image) }}"
                                                        alt="" style="max-height:60px;border-radius:6px;">
                                                </div>
                                            @endif
                                            <input type="file" name="header_image" class="form-control" accept="image/*">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Email Content <small
                                                    style="color:var(--text-muted);">(shown above the reading;
                                                    @{{ $productTitle }} / @{{ $campaignName }} available)</small></label>
                                            <textarea name="email_content" class="form-control" rows="3">{{ $campaignProduct->email_content }}</textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Email Footer <small
                                                    style="color:var(--text-muted);">(shown below the reading;
                                                    @{{ $productTitle }} / @{{ $campaignName }} available)</small></label>
                                            <textarea name="email_footer" class="form-control" rows="5">{{ $campaignProduct->email_footer }}</textarea>
                                        </div>
                                        <div style="display:flex;gap:1.5rem;margin-bottom:0.75rem;">
                                            <label><input type="radio" name="source" value="ai" x-model="source">
                                                Generate with AI</label>
                                            <label><input type="radio" name="source" value="manual" x-model="source">
                                                Paste manual response</label>
                                        </div>
                                        <div x-show="source === 'ai'" x-cloak>
                                            <label class="form-label">Prompt Template <small
                                                    style="color:var(--text-muted);">(Blade template;
                                                    @{{ $productTitle }} / @{{ $campaignName }}
                                                    available)</small></label>
                                            <textarea name="prompt_template" class="form-control" rows="3">{{ $campaignProduct->prompt_template }}</textarea>
                                        </div>
                                        <div x-show="source === 'manual'" x-cloak>
                                            <label class="form-label">Response Body</label>
                                            <textarea name="body" class="form-control" rows="5"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary"
                                            style="margin-top:0.75rem;">Save Response</button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody>
                            <tr>
                                <td colspan="5">
                                    @include('admin.components.empty-state', [
                                        'message' => 'No products linked to this campaign yet.',
                                    ])
                                </td>
                            </tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
        </div>

    </div>
@endsection

<script>
    function copyCampaignLink(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(() => copyCampaignLinkFallback(text));
        } else {
            copyCampaignLinkFallback(text);
        }
    }

    function copyCampaignLinkFallback(text) {
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // no-op: nothing more we can do without the Clipboard API
        }
        document.body.removeChild(el);
    }

    function productPicker(availableUrl) {
        return {
            availableUrl: availableUrl,
            products: [],
            loading: false,
            loadError: '',
            query: '',
            open: false,
            selected: null,
            variantId: null,
            title: '',
            async load() {
                this.loading = true;
                this.loadError = '';
                try {
                    const res = await fetch(this.availableUrl, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    if (!res.ok) {
                        throw new Error('Request failed');
                    }
                    const data = await res.json();
                    this.products = data.products || [];
                } catch (e) {
                    this.loadError = 'Failed to load products. Reload the page to try again.';
                } finally {
                    this.loading = false;
                }
            },
            filtered() {
                const q = this.query.trim().toLowerCase();
                if (!q) {
                    return this.products;
                }
                return this.products.filter(p => p.title.toLowerCase().includes(q));
            },
            variantSummary(product) {
                if (!product.variants.length) {
                    return '';
                }
                if (product.variants.length === 1) {
                    return '£' + product.variants[0].price;
                }
                return product.variants.length + ' variants';
            },
            pick(product) {
                this.selected = product;
                this.variantId = product.variants.length === 1 ? product.variants[0].id : null;
                this.title = product.title;
                this.open = false;
                this.query = '';
            },
            clear() {
                this.selected = null;
                this.variantId = null;
            },
        };
    }
</script>

<style>
    .product-picker-panel {
        position: absolute;
        z-index: 20;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        max-height: 320px;
        overflow-y: auto;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 10px;
        box-shadow: var(--shadow-lg);
    }

    .product-picker-row {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        padding: 0.5rem 0.75rem;
        cursor: pointer;
        transition: background var(--t-fast);
    }

    .product-picker-row:hover {
        background: var(--color-primary-muted);
    }

    .product-picker-thumb {
        width: 36px;
        height: 36px;
        flex: none;
        border-radius: 8px;
        overflow: hidden;
        background: var(--content-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }

    .product-picker-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .product-picker-chip {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        border: 1px solid var(--card-border);
        border-radius: 10px;
        padding: 0.5rem 0.75rem;
    }

    .product-picker-variants {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.625rem;
    }

    .product-picker-variant-pill {
        display: flex;
        gap: 0.375rem;
        align-items: center;
        border: 1px solid var(--card-border);
        border-radius: 999px;
        padding: 0.3rem 0.75rem;
        font-size: 0.8125rem;
        background: var(--card-bg);
        cursor: pointer;
        transition: all var(--t-fast);
    }

    .product-picker-variant-pill:hover {
        border-color: var(--color-primary-light);
    }

    .product-picker-variant-pill.is-active {
        background: var(--color-primary-muted);
        border-color: var(--color-primary);
        color: var(--color-primary-dark);
        font-weight: 600;
    }
</style>
