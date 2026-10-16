@extends('admin.layouts.app')

@section('page-title', 'Email Readings')

@php
    $badgeFor = [
        'pending'   => 'warning',
        'generated' => 'info',
        'sending'   => 'info',
        'sent'      => 'success',
        'failed'    => 'danger',
        'cancelled' => 'secondary',
        'compliance_hold' => 'warning',
    ];

    $deliveryBadgeStyle = [
        'pending'   => 'background:#fffbeb;color:#b45309;border:1px solid #fde68a;',
        'generated' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
        'sending'   => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
        'sent'      => 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;',
        'failed'    => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
        'cancelled' => 'background:#f8fafc;color:#475569;border:1px solid #cbd5e1;',
        'compliance_hold' => 'background:#fffbeb;color:#b45309;border:1px solid #fde68a;',
    ];
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Email Readings',
        'subtitle' => 'Manage AI-generated reading deliveries and their status',
        'action'   => '<div style="display:inline-flex;gap:0.625rem;align-items:center;">'
            . '<a href="' . route('admin.email_readings.export', request()->query()) . '" class="btn" style="background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.45);color:#ffffff;font-weight:600;display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 0.95rem;border-radius:8px;backdrop-filter:blur(8px);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV</a>'
            . '<a href="' . route('admin.email_readings.create') . '" class="btn" style="background:#ffffff;border:1px solid #ffffff;color:#4f46e5;font-weight:700;display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1.05rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.14);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>New Reading</a>'
            . '</div>',
    ])

    {{-- Filters --}}
    <div class="card p-4" style="margin-bottom:1.25rem;">
        <form method="GET" action="{{ route('admin.email_readings.index') }}"
              style="display:flex;flex-wrap:wrap;gap:0.875rem;align-items:flex-end;">
            <div style="flex:1;min-width:230px;">
                <label class="form-label" style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:0.35rem;display:block;">Search</label>
                <div style="position:relative;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         style="position:absolute;left:0.875rem;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control" placeholder="Email, name or order ID"
                           style="height:40px;padding-left:2.35rem;font-size:0.84rem;border:1px solid #cbd5e1;border-radius:8px;background:#ffffff;">
                </div>
            </div>
            <div style="min-width:160px;">
                <label class="form-label" style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:0.35rem;display:block;">Status</label>
                <select name="status" class="form-control form-select"
                        style="height:40px;font-size:0.84rem;border:1px solid #cbd5e1;border-radius:8px;background:#ffffff;">
                    <option value="">All statuses</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:160px;">
                <label class="form-label" style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:0.35rem;display:block;">Engagement</label>
                <select name="engagement" class="form-control form-select"
                        style="height:40px;font-size:0.84rem;border:1px solid #cbd5e1;border-radius:8px;background:#ffffff;">
                    <option value="">All Engagement</option>
                    @foreach($engagements as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['engagement'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:145px;">
                <label class="form-label" style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:0.35rem;display:block;">From</label>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"
                       style="height:40px;font-size:0.8125rem;border:1px solid #cbd5e1;border-radius:8px;background:#ffffff;padding:0.375rem 0.65rem;color:#334155;">
            </div>
            <div style="min-width:145px;">
                <label class="form-label" style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:0.35rem;display:block;">To</label>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"
                       style="height:40px;font-size:0.8125rem;border:1px solid #cbd5e1;border-radius:8px;background:#ffffff;padding:0.375rem 0.65rem;color:#334155;">
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <button type="submit" class="btn btn-primary" style="height:40px;padding:0 1.25rem;font-weight:600;font-size:0.84rem;border-radius:8px;box-shadow:0 2px 4px rgba(99,102,241,0.25);">Filter</button>
                <a href="{{ route('admin.email_readings.index') }}" class="btn" style="height:40px;padding:0 1.15rem;font-weight:500;font-size:0.84rem;border-radius:8px;border:1px solid #cbd5e1;background:#ffffff;color:#475569;display:inline-flex;align-items:center;justify-content:center;">Reset</a>
            </div>
        </form>
    </div>

    {{-- Main Table Card --}}
    <div class="card p-4">
        <div class="table-responsive" style="overflow-y:visible;">
            <table class="table">
                <thead>
                    <tr>
                        <th style="min-width:210px;padding:0.75rem 1rem;">Customer</th>
                        <th style="min-width:170px;padding:0.75rem 1rem;">Product</th>
                        <th class="text-center" style="min-width:150px;padding:0.75rem 1rem;">Status</th>
                        <th style="min-width:110px;padding:0.75rem 1rem;">Model</th>
                        <th class="text-center" style="min-width:80px;padding:0.75rem 1rem;">Tokens</th>
                        <th style="min-width:130px;padding:0.75rem 1rem;">Scheduled</th>
                        <th style="min-width:130px;padding:0.75rem 1rem;">Sent</th>
                        <th class="text-end" style="min-width:120px;width:120px;padding:0.75rem 1rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td style="vertical-align:middle;padding:0.85rem 1rem;">
                                <div style="font-weight:600;color:#0f172a;font-size:0.875rem;line-height:1.3;">
                                    {{ $delivery->customer_name ?: '—' }}
                                </div>
                                <div style="font-size:0.8125rem;color:#64748b;line-height:1.3;margin-top:0.1875rem;">
                                    {{ $delivery->customer_email }}
                                </div>
                                <div style="margin-top:0.4rem;">
                                    @if($delivery->shopify_order_id)
                                        <a href="{{ route('admin.email_readings.show', $delivery) }}"
                                           style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.55rem;border-radius:6px;background:#f8fafc;border:1px solid #cbd5e1;font-size:0.75rem;font-weight:600;color:#1e293b;text-decoration:none;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:all 0.15s ease;"
                                           onmouseover="this.style.borderColor='var(--color-primary)';this.style.background='#ffffff';this.style.color='var(--color-primary)'"
                                           onmouseout="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';this.style.color='#1e293b'">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.6;">
                                                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                                                <line x1="3" y1="6" x2="21" y2="6"></line>
                                                <path d="M16 10a4 4 0 0 1-8 0"></path>
                                            </svg>
                                            <span>Order #{{ $delivery->shopify_order_id }}</span>
                                        </a>
                                    @else
                                        <span style="display:inline-flex;align-items:center;padding:0.15rem 0.5rem;border-radius:6px;background:#f1f5f9;border:1px solid #e2e8f0;font-size:0.6875rem;font-weight:600;color:#64748b;">
                                            Manual
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td style="vertical-align:middle;padding:0.85rem 1rem;color:#1e293b;font-weight:500;font-size:0.875rem;">
                                {{ $delivery->product?->name ?? '—' }}
                            </td>
                            <td class="text-center" style="vertical-align:middle;padding:0.85rem 1rem;">
                                <div style="display:inline-flex;flex-direction:column;align-items:center;gap:0.35rem;">
                                    <span class="badge badge-{{ $badgeFor[$delivery->status] ?? 'secondary' }}"
                                          style="{{ $deliveryBadgeStyle[$delivery->status] ?? 'background:#f8fafc;color:#475569;border:1px solid #cbd5e1;' }}border-radius:9999px;padding:0.22rem 0.65rem;font-size:0.75rem;font-weight:600;display:inline-flex;align-items:center;line-height:1.2;">
                                        {{ $statuses[$delivery->status] ?? ucfirst($delivery->status) }}
                                    </span>
                                    @include('admin.components.engagement-pill', ['delivery' => $delivery])
                                    @if($delivery->needsFulfillment())
                                        <span class="badge badge-warning"
                                              style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:9999px;padding:0.18rem 0.55rem;font-size:0.6875rem;font-weight:600;display:inline-flex;align-items:center;line-height:1.2;">
                                            Unfulfilled
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td style="vertical-align:middle;padding:0.85rem 1rem;font-size:0.8125rem;color:#475569;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;">
                                @if($delivery->model_used)
                                    {{ $delivery->model_used }}
                                @else
                                    <span style="color:#94a3b8;">—</span>
                                @endif
                            </td>
                            <td class="text-center" style="vertical-align:middle;padding:0.85rem 1rem;font-size:0.8125rem;color:#334155;font-weight:600;">
                                @if($delivery->completion_tokens)
                                    {{ number_format($delivery->completion_tokens) }}
                                @else
                                    <span style="color:#94a3b8;font-weight:400;">—</span>
                                @endif
                            </td>
                            <td style="vertical-align:middle;padding:0.85rem 1rem;font-size:0.8125rem;color:#475569;white-space:nowrap;">
                                @if($delivery->scheduled_at)
                                    {{ $delivery->scheduled_at->format('d M Y H:i') }}
                                @else
                                    <span style="color:#94a3b8;">—</span>
                                @endif
                            </td>
                            <td style="vertical-align:middle;padding:0.85rem 1rem;font-size:0.8125rem;color:#475569;white-space:nowrap;">
                                @if($delivery->sent_at)
                                    {{ $delivery->sent_at->format('d M Y H:i') }}
                                @else
                                    <span style="color:#94a3b8;">—</span>
                                @endif
                            </td>
                            <td class="text-end" style="vertical-align:middle;padding:0.85rem 1rem;position:relative;" x-data="{ open: false }" :style="open ? 'z-index: 30;' : ''">
                                <div style="display:inline-flex;align-items:center;justify-content:flex-end;gap:0.375rem;">
                                    <a href="{{ route('admin.email_readings.show', $delivery) }}"
                                       class="btn btn-sm btn-primary"
                                       style="font-weight:600;padding:0.375rem 0.8rem;border-radius:6px;box-shadow:0 1px 3px rgba(99,102,241,0.25);">View</a>

                                    <div class="admin-dropdown" style="display:inline-block;position:relative;">
                                        <button type="button"
                                                class="btn btn-sm"
                                                @click="open = !open"
                                                @click.outside="open = false"
                                                aria-label="Actions"
                                                title="More actions"
                                                style="padding:0.375rem 0.55rem;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;color:#475569;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s ease;"
                                                onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'"
                                                onmouseout="this.style.background='#ffffff';this.style.borderColor='#cbd5e1'">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                                <circle cx="5" cy="12" r="2"/>
                                                <circle cx="12" cy="12" r="2"/>
                                                <circle cx="19" cy="12" r="2"/>
                                            </svg>
                                        </button>

                                        <div class="admin-dropdown-menu"
                                             x-show="open"
                                             x-cloak
                                             @click.outside="open = false"
                                             style="min-width:160px;right:0;text-align:left;">
                                            <a href="{{ route('admin.email_readings.edit', $delivery) }}" class="admin-dropdown-item">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-warning);"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                                <span>Edit Reading</span>
                                            </a>

                                            @if(! in_array($delivery->status, ['sending', 'cancelled', 'compliance_hold']))
                                                <form action="{{ route('admin.email_readings.send', $delivery) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="admin-dropdown-item"
                                                            data-confirm="{{ $delivery->status === 'sent' ? 'Re-send this reading to the customer?' : 'Send this reading now (bypassing the schedule)?' }}">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-success);"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                                                        <span>{{ $delivery->status === 'sent' ? 'Resend Email' : 'Send Now' }}</span>
                                                    </button>
                                                </form>
                                            @endif

                                            @if(! in_array($delivery->status, ['sent', 'cancelled', 'sending']))
                                                <form action="{{ route('admin.email_readings.cancel', $delivery) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="admin-dropdown-item"
                                                            data-confirm="Cancel this reading? No email will be sent.">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                                                        <span>Cancel</span>
                                                    </button>
                                                </form>
                                            @endif

                                            <div class="admin-dropdown-divider"></div>

                                            <form action="{{ route('admin.email_readings.destroy', $delivery) }}" method="POST" style="margin:0;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="admin-dropdown-item danger"
                                                        data-confirm="Delete this reading permanently? This cannot be undone.">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding:2rem 1rem;text-align:center;">
                                @include('admin.components.empty-state', ['message' => 'No readings found for these filters.'])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-top:1.25rem;">
            <div style="font-size:0.8125rem;color:#64748b;font-weight:500;">
                @if($deliveries->total() > 0)
                    Showing {{ $deliveries->firstItem() }} to {{ $deliveries->lastItem() }} of {{ $deliveries->total() }} results
                @else
                    0 results
                @endif
            </div>
            @if($deliveries->hasPages())
                <div>
                    {!! $deliveries->appends(request()->query())->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
