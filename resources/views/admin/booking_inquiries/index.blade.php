@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">

        {{-- Page Header --}}
        @include('admin.components.page-header', [
            'title' => 'Booking Inquiries',
            'subtitle' => 'Manage customer booking requests and schedules',
        ])

        {{-- Alert Messages --}}
        @if ($errors->has('error'))
            <div class="alert alert-danger alert-dismissible fade show card" role="alert"
                style="border-left: 4px solid #ef4444;">
                <strong>Error:</strong> {{ $errors->first('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Search Form --}}
        <form method="GET" action="{{ route('admin.scheduled-meetings') }}" class="card p-4 mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">
                        Search Customer
                    </label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                        placeholder="Search by Name, Email">
                </div>

                <div class="col-md-auto ms-auto">
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2"
                                style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.35-4.35"></path>
                            </svg>
                            Filter
                        </button>
                        <a href="{{ route('admin.scheduled-meetings') }}" class="btn btn-light"
                            style="border: 2px solid #e2e8f0;">
                            Clear
                        </a>
                    </div>
                </div>
            </div>
        </form>

        {{-- Booking Inquiries Table --}}
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">S.No</th>
                            <th>Customer Details</th>
                            <th>Contact</th>
                            <th>Date & Time</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: right; width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($booking_inquiries as $index => $inquiry)
                            <tr>
                                <td style="color: #94a3b8; font-weight: 600;">
                                    {{ $index + 1 }}
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 1rem;">
                                        {{ $inquiry->name }}
                                    </div>
                                    <div style="font-size: 0.875rem; color: #64748b; margin-top: 0.25rem;">
                                        {{ $inquiry->email }}
                                    </div>
                                </td>
                                <td style="color: #64748b; font-weight: 500;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" style="color: #94a3b8;">
                                            <path
                                                d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                                            </path>
                                        </svg>
                                        {{ $inquiry->phone }}
                                    </div>
                                </td>
                                <td>
                                    @if ($inquiry->status != 'needs_reschedule')
                                        <div style="font-weight: 600; color: #1e293b;">
                                            {{ $inquiry->datetime->format('d M Y') }}
                                        </div>
                                        <div style="font-size: 0.875rem; color: #64748b;">
                                            {{ $inquiry->datetime->format('h:i A') }}
                                        </div>
                                    @else
                                        <span style="color: #94a3b8;">N/A</span>
                                    @endif
                                </td>
                                <td style="text-align: center;">
                                    @php
                                        $statusColors = [
                                            'confirmed' => ['bg' => 'rgba(16, 185, 129, 0.1)', 'color' => '#10b981'],
                                            'pending' => ['bg' => 'rgba(245, 158, 11, 0.1)', 'color' => '#f59e0b'],
                                            'cancelled' => ['bg' => 'rgba(239, 68, 68, 0.1)', 'color' => '#ef4444'],
                                            'needs_reschedule' => [
                                                'bg' => 'rgba(102, 126, 234, 0.1)',
                                                'color' => '#667eea',
                                            ],
                                        ];
                                        $status = $statusColors[$inquiry->status] ?? [
                                            'bg' => 'rgba(148, 163, 184, 0.1)',
                                            'color' => '#94a3b8',
                                        ];
                                    @endphp
                                    <span
                                        style="background: {{ $status['bg'] }}; color: {{ $status['color'] }}; padding: 0.375rem 0.875rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem; text-transform: capitalize;">
                                        {{ ucfirst(str_replace('_', ' ', $inquiry->status)) }}
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div
                                        style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end;">
                                        @if ($inquiry->status != 'needs_reschedule')
                                            <a href="{{ route('admin.booking.view', $inquiry->id) }}"
                                                class="btn btn-sm btn-info" title="View">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2"
                                                    style="display: inline-block; vertical-align: middle;">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                </svg>
                                            </a>
                                        @endif

                                        <button type="button" class="btn btn-sm btn-reschedule"
                                            style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; font-weight: 600;"
                                            data-id="{{ $inquiry->id }}"
                                            data-datetime="{{ $inquiry->datetime->format('Y-m-d\TH:i') }}"
                                            title="Reschedule">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2"
                                                style="display: inline-block; vertical-align: middle;">
                                                <rect x="3" y="4" width="18" height="18" rx="2"
                                                    ry="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                        </button>

                                        <form action="{{ route('admin.booking.cancel') }}" method="POST"
                                            style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="booking_id" value="{{ $inquiry->id }}">
                                            <button type="submit" class="btn btn-sm"
                                                style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; font-weight: 600;"
                                                title="Cancel">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2"
                                                    style="display: inline-block; vertical-align: middle;">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <line x1="15" y1="9" x2="9" y2="15">
                                                    </line>
                                                    <line x1="9" y1="9" x2="15" y2="15">
                                                    </line>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    @include('admin.components.empty-state', [
                                        'message' => 'No booking inquiries found',
                                        'icon' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                                                    </svg>',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($booking_inquiries->hasPages())
                <div style="margin-top: 1.5rem;">
                    {!! $booking_inquiries->appends(request()->query())->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>

    </div>

    {{-- Reschedule Modal --}}
    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <form id="rescheduleForm" method="POST" action="{{ route('admin.booking.reschedule') }}">
                @csrf
                @method('PUT')
                <div class="modal-content" style="border: none; border-radius: 16px; box-shadow: var(--shadow-xl);">
                    <div class="modal-header" style="border-bottom: 1px solid #f1f5f9; padding: 1.5rem;">
                        <h5 class="modal-title" id="rescheduleModalLabel" style="font-weight: 700; color: #1e293b;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2"
                                style="display: inline-block; vertical-align: middle; margin-right: 0.5rem; color: var(--color-primary);">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Reschedule Booking
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body" style="padding: 1.5rem;">
                        <input type="hidden" name="booking_id" id="booking_id" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="availability_date" class="form-label"
                                    style="font-weight: 600; color: #475569; font-size: 0.875rem;">
                                    New Date
                                </label>
                                <input type="date" class="form-control" name="availability_date"
                                    id="availability_date" required>
                            </div>

                            <div class="col-md-6">
                                <label for="time_slot_id" class="form-label"
                                    style="font-weight: 600; color: #475569; font-size: 0.875rem;">
                                    Available Time Slots
                                </label>
                                <select name="time_slot_id" id="time_slot_id" class="form-control" required>
                                    <option value="">Select a time slot</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer" style="border-top: 1px solid #f1f5f9; padding: 1.5rem; gap: 0.5rem;">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal"
                            style="border: 2px solid #e2e8f0;">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2"
                                style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Update Booking
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Scripts --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Set minimum date for availability date input
            const dateInput = document.getElementById('availability_date');
            const today = new Date().toISOString().split('T')[0];
            dateInput.setAttribute('min', today);

            // Reschedule modal functionality
            var rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));

            document.querySelectorAll('.btn-reschedule').forEach(function(button) {
                button.addEventListener('click', function() {
                    var bookingId = this.getAttribute('data-id');
                    document.getElementById('booking_id').value = bookingId;
                    rescheduleModal.show();
                });
            });

            // Fetch time slots when date is selected
            const timeSlotDropdown = document.getElementById('time_slot_id');

            dateInput.addEventListener('change', function() {
                const selectedDate = this.value;

                if (selectedDate) {
                    fetch(`{{ route('admin.get.time-slots') }}?date=${selectedDate}`)
                        .then(response => response.json())
                        .then(data => {
                            timeSlotDropdown.innerHTML = '<option value="">Select a time slot</option>';

                            if (data.success && data.time_slots.length > 0) {
                                data.time_slots.forEach(slot => {
                                    const option = document.createElement('option');
                                    option.value = slot.id;
                                    option.textContent =
                                    `${slot.start_time} - ${slot.end_time}`;

                                    if (slot.booked) {
                                        option.disabled = true;
                                        option.textContent += ' (Booked)';
                                    }

                                    timeSlotDropdown.appendChild(option);
                                });
                            } else {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = 'No available time slots';
                                timeSlotDropdown.appendChild(option);
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching time slots:', error);
                            toastr.error('Failed to load time slots');
                        });
                }
            });
        });
    </script>
@endsection
