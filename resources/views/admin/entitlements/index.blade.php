@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">
        <style>
            .customer-entitlements-table {
                table-layout: fixed;
                width: 100%;
            }

            .customer-entitlements-table .email-column {
                width: 30%;
                min-width: 260px;
            }

            .customer-entitlements-table .email-summary {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                flex-wrap: wrap;
            }

            .customer-entitlements-table .email-primary {
                max-width: 220px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-weight: 500;
                color: #1e293b;
            }

            .customer-entitlements-table .email-more-btn {
                border: 1px solid rgba(99, 102, 241, 0.25);
                background: rgba(99, 102, 241, 0.08);
                color: #4f46e5;
                border-radius: 999px;
                padding: 0.2rem 0.65rem;
                font-size: 0.75rem;
                font-weight: 700;
            }

            .customer-entitlements-table .email-more-btn:hover {
                background: rgba(99, 102, 241, 0.16);
                color: #4338ca;
            }

            .entitlement-email-list {
                margin: 0;
                padding-left: 1.1rem;
            }

            .entitlement-email-list li {
                word-break: break-word;
                overflow-wrap: anywhere;
                margin-bottom: 0.5rem;
            }

            .entitlement-emails-modal {
                z-index: 2001 !important;
                position: fixed;
            }

            .entitlement-emails-modal .modal-dialog {
                max-width: 560px;
                margin: 2rem auto;
            }

            .entitlement-emails-modal .modal-content {
                background: #ffffff;
                opacity: 1 !important;
                border: 0;
                border-radius: 18px;
                box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
                overflow: hidden;
            }

            .entitlement-emails-modal .modal-header {
                background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%);
                border-bottom: 1px solid rgba(148, 163, 184, 0.18);
                padding: 1rem 1.25rem;
            }

            .entitlement-emails-modal .modal-title {
                color: #0f172a;
                font-weight: 700;
            }

            .entitlement-emails-modal .modal-body {
                background: #ffffff;
                color: #334155;
                padding: 1.25rem;
                max-height: 65vh;
                overflow-y: auto;
            }

            .entitlement-emails-modal .package-tag-pill {
                display: inline-flex;
                align-items: center;
                background: rgba(99, 102, 241, 0.1);
                color: #4f46e5;
                border-radius: 999px;
                padding: 0.35rem 0.75rem;
                font-size: 0.875rem;
                font-weight: 700;
            }

            .modal-backdrop.show {
                z-index: 2000 !important;
            }

            .customer-entitlements-table .customer-id-column {
                width: 18%;
            }

            .customer-entitlements-table .package-column {
                width: 16%;
            }

            .customer-entitlements-table .created-column {
                width: 14%;
            }

            .customer-entitlements-table .action-column {
                width: 10%;
                white-space: nowrap;
            }
        </style>

        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem; font-weight: 900; color: #ffffff; margin: 0;">Customer Entitlements</h1>
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 1rem; margin-top: 0.5rem;">Manage purchased audio
                        access records</p>
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.customer.entitlements.index') }}" class="card p-4 mb-4"
            style="position: relative; overflow: visible;">
            <div class="row g-3">
                <div class="col-md-10">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">Search</label>
                    <input type="text" name="search" value="{{ $request->search }}"
                        placeholder="Search by email, Shopify customer id, or package tag" class="form-control">
                </div>

                <div class="col-md-2">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: transparent; margin-bottom: 0.5rem; display: block;">Action</label>
                    <button type="submit" class="btn btn-primary w-100" style="height: 48px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        Search
                    </button>
                </div>
            </div>
        </form>

        <div class="card p-4">
            <div class="table-responsive">
                <table class="table customer-entitlements-table">
                    <thead>
                        <tr>
                            <th class="text-start">ID</th>
                            <th class="email-column text-center">Email</th>
                            <th class="customer-id-column text-center">Shopify Customer ID</th>
                            <th class="package-column text-center">Package Tag</th>
                            {{-- <th>Download Allowed</th> --}}
                            <th class="created-column text-center">Created</th>
                            <th class="action-column text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entitlements as $entitlement)
                            @php
                                $emails = $entitlement->email
                                    ? array_values(array_filter(preg_split('/\s*,\s*/', $entitlement->email)))
                                    : [];
                                $primaryEmail = $emails[0] ?? null;
                                $additionalEmailCount = max(count($emails) - 1, 0);
                                $modalId = 'entitlementEmailsModal' . $entitlement->id;
                            @endphp
                            <tr>
                                <td class="text-start">{{ $entitlement->id }}</td>
                                <td class="email-column text-center">
                                    @if ($primaryEmail)
                                        <div class="email-summary">
                                            <span class="email-primary" title="{{ $primaryEmail }}">
                                                {{ $primaryEmail }}
                                            </span>

                                            @if ($additionalEmailCount > 0)
                                                <button type="button" class="email-more-btn" data-bs-toggle="modal"
                                                    data-bs-target="#{{ $modalId }}">
                                                    +{{ $additionalEmailCount }} more
                                                </button>
                                            @endif
                                        </div>
                                    @else
                                        <span style="color: #94a3b8;">N/A</span>
                                    @endif
                                </td>
                                <td class="customer-id-column text-center">{{ $entitlement->shopify_customer_id }}</td>
                                <td class="package-column text-center">
                                    <span
                                        style="background: rgba(102, 126, 234, 0.1); color: var(--color-primary); padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                        {{ $entitlement->package_tag }}
                                    </span>
                                </td>
                                {{-- <td>
                                    @if ($entitlement->is_download_allowed)
                                        <span
                                            style="background: rgba(16, 185, 129, 0.12); color: #059669; padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                            Yes
                                        </span>
                                    @else
                                        <span
                                            style="background: rgba(239, 68, 68, 0.12); color: #dc2626; padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                            No
                                        </span>
                                    @endif
                                </td> --}}
                                <td class="created-column text-center">
                                    @if ($entitlement->created_at)
                                        <div style="font-weight: 600; color: #1e293b;">
                                            {{ $entitlement->created_at->format('d M Y') }}
                                        </div>
                                        <div style="font-size: 0.875rem; color: #64748b;">
                                            {{ $entitlement->created_at->format('h:i A') }}
                                        </div>
                                    @else
                                        <span style="color: #94a3b8;">N/A</span>
                                    @endif
                                </td>
                                <td class="action-column text-center">
                                    <a href="{{ route('admin.customer.entitlements.edit', $entitlement) }}"
                                        class="btn btn-sm btn-outline-primary">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 3rem; color: #94a3b8;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                        <path d="M3 7h18"></path>
                                        <path d="M6 3h12"></path>
                                        <path d="M6 12h12"></path>
                                        <path d="M8 17h8"></path>
                                    </svg>
                                    <p style="margin: 0;">No customer entitlements found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($entitlements->hasPages())
                <div style="margin-top: 1.5rem;">
                    {!! $entitlements->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>

        @foreach ($entitlements as $entitlement)
            @php
                $emails = $entitlement->email
                    ? array_values(array_filter(preg_split('/\s*,\s*/', $entitlement->email)))
                    : [];
                $modalId = 'entitlementEmailsModal' . $entitlement->id;
            @endphp

            @if (count($emails) > 1)
                <div class="modal fade entitlement-emails-modal" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Entitlement Emails</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <div class="text-muted small mb-2">Package Tag</div>
                                    <span class="package-tag-pill">{{ $entitlement->package_tag }}</span>
                                </div>

                                <div class="text-muted small mb-2">Email List</div>
                                <ul class="entitlement-email-list">
                                    @foreach ($emails as $email)
                                        <li>{{ $email }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endsection

@section('custom_js_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.entitlement-emails-modal').forEach(function(modal) {
                document.body.appendChild(modal);
            });
        });
    </script>
@endsection
