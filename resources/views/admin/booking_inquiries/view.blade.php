@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">

        {{-- Page Header --}}
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin: 0;">View Booking Details</h1>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.375rem; font-size: 0.875rem; color: var(--text-muted);">
                    <a href="{{ route('admin.dashboard') }}" style="color: var(--text-muted); text-decoration: none;">Dashboard</a>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.4;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    <a href="{{ route('admin.scheduled-meetings') }}" style="color: var(--text-muted); text-decoration: none;">Inquiries</a>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.4;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    <span style="color: var(--text-primary); font-weight: 600;">View</span>
                </div>
            </div>
            <a href="{{ route('admin.scheduled-meetings') }}" class="btn btn-outline-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    style="display: inline-block; vertical-align: middle; margin-right: 0.375rem;">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
                Back to list
            </a>
        </div>

        <div class="card" style="max-width: 780px;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--card-border); display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(102, 126, 234, 0.12); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <div>
                    <div style="font-weight: 700; color: var(--text-primary); font-size: 1rem;">Booking Details</div>
                    <div style="font-size: 0.8125rem; color: var(--text-muted);">{{ $booking->name }}</div>
                </div>

                @php
                    $statusColors = [
                        'confirmed'        => ['bg' => 'rgba(16, 185, 129, 0.1)',  'color' => '#10b981'],
                        'pending'          => ['bg' => 'rgba(245, 158, 11, 0.1)',  'color' => '#f59e0b'],
                        'cancelled'        => ['bg' => 'rgba(239, 68, 68, 0.1)',   'color' => '#ef4444'],
                        'needs_reschedule' => ['bg' => 'rgba(102, 126, 234, 0.1)', 'color' => '#667eea'],
                    ];
                    $statusStyle = $statusColors[$booking->status] ?? ['bg' => 'rgba(148, 163, 184, 0.1)', 'color' => '#94a3b8'];
                @endphp
                <span style="margin-left: auto; background: {{ $statusStyle['bg'] }}; color: {{ $statusStyle['color'] }}; padding: 0.3rem 0.875rem; border-radius: 12px; font-weight: 700; font-size: 0.8125rem; text-transform: capitalize;">
                    {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table" style="margin: 0;">
                    <tbody>
                        <tr>
                            <td style="width: 180px; font-weight: 600; color: var(--text-muted); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; background: rgba(248, 250, 252, 0.6);">
                                Name
                            </td>
                            <td style="font-weight: 600; color: var(--text-primary);">
                                {{ $booking->name }}
                            </td>
                        </tr>
                        <tr>
                            <td style="width: 180px; font-weight: 600; color: var(--text-muted); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; background: rgba(248, 250, 252, 0.6);">
                                Email
                            </td>
                            <td>
                                <a href="mailto:{{ $booking->email }}" style="color: var(--color-primary); font-weight: 500; text-decoration: none;">
                                    {{ $booking->email }}
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-muted); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; background: rgba(248, 250, 252, 0.6);">
                                Phone Number
                            </td>
                            <td style="color: var(--text-primary); font-weight: 500;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #94a3b8; flex-shrink: 0;">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 12 19.79 19.79 0 0 1 1.04 3.37 2 2 0 0 1 3 1.04h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                    </svg>
                                    {{ $booking->phone }}
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-muted); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; background: rgba(248, 250, 252, 0.6);">
                                Date &amp; Time
                            </td>
                            <td>
                                @if ($booking->status != 'needs_reschedule' && $booking->datetime)
                                    <div style="font-weight: 600; color: var(--text-primary);">
                                        {{ $booking->datetime->format('d M Y') }}
                                    </div>
                                    <div style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.125rem;">
                                        {{ $booking->datetime->format('h:i A') }}
                                    </div>
                                @else
                                    <span style="color: #94a3b8;">N/A</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-muted); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; background: rgba(248, 250, 252, 0.6); border-bottom: none;">
                                Meet Link
                            </td>
                            <td style="border-bottom: none;">
                                @if ($booking->meeting_link)
                                    <a href="{{ $booking->meeting_link }}" target="_blank" rel="noopener"
                                        style="color: var(--color-primary); font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.375rem;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                            <polyline points="15 3 21 3 21 9"></polyline>
                                            <line x1="10" y1="14" x2="21" y2="3"></line>
                                        </svg>
                                        {{ $booking->meeting_link }}
                                    </a>
                                @else
                                    <span style="color: #94a3b8;">Not available</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
