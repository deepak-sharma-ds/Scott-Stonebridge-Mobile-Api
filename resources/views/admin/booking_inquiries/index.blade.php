@extends('admin.layouts.app')

@section('content')
    <style>
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive :is(td, th) {
            white-space: nowrap;

        }
    </style>
    <div class="container">
        @if ($errors->has('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ $errors->first('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>

            </div>
        @endif

        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Booking Inquiries</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Booking Inquiries List</li>
                </ol>
            </div>
        </div>

        <div class="row mb-5">
            <!-- Column starts -->
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Search</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.scheduled-meetings') }}" class="row g-2 align-items-end">
                            {{-- Search --}}
                            <div class="col-md-4 search_inputs">
                                <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                                    placeholder="Search by Name, Email">
                            </div>
                            {{-- <div class="col-md-3">
                                <input type="text" name="date_range" id="date_range" value="{{ request('date_range') }}"
                                    class="form-control" placeholder="Select date range" autocomplete="off" />
                            </div> --}}

                            {{-- Filter button --}}
                            <div class="col-md-1 d-grid">
                                <button type="submit" class="btn btn-primary">Filter</button>
                            </div>

                            {{-- Clear button --}}
                            <div class="col-md-1 d-grid ms-1">
                                <a href="{{ route('admin.scheduled-meetings') }}" class="btn btn-secondary">Clear</a>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Booking Inquiries</strong>
                    </div>
                    <div class="pe-4 ps-4 pt-2 pb-2">
                        <div class="table-responsive" style="overflow-x:auto;">
                            <table class="table table-bordered table-hover table-striped align-middle mb-0">
                                <thead class="text-secondary">
                                    <tr>
                                        <th>S.NO.</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Date & Time</th>
                                        <th>Status</th>
                                        <th class="last-sticky">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($booking_inquiries as $index => $inquiry)
                                        <tr>
                                            <td>{{$index+1}}</td>
                                            <td class="" style="max-width: 150px; word-wrap: break-word;">
                                                {{ $inquiry->name }}
                                            </td>

                                            <td>{{ $inquiry->email }}</td>
                                            <td>{{ $inquiry->phone }}</td>
                                            <td>
                                                {{ $inquiry->datetime->format(config('Reading.date_time_format')) }}
                                            </td>
                                            <td>{{ ucfirst($inquiry->status) }}</td>
                                            <td>
                                                <!-- View button -->
                                                <a href="{{ route('admin.booking.view', $inquiry->id) }}" class="btn btn-primary btn-sm" title="View">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>

                                                <!-- Reschedule button -->
                                                <button type="button" 
                                                        class="btn btn-warning btn-sm btn-reschedule" 
                                                        data-id="{{ $inquiry->id }}" 
                                                        data-datetime="{{ $inquiry->datetime->format('Y-m-d\TH:i') }}"
                                                        title="Reschedule">
                                                        <i class="fa fa-calendar" aria-hidden="true"></i></i>
                                                </button>

                                                <!-- Cancel form with confirmation -->
                                                <form action="{{ route('admin.booking.cancel') }}" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="booking_id" value="{{ $inquiry->id }}">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Cancel">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center">No booking inquiries found.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-1">
            {!! $booking_inquiries->appends(request()->query())->links('pagination::bootstrap-5') !!}
        </div>

        {{-- Model for reschedule meetings --}}
        <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <form id="rescheduleForm" method="POST" action="{{ route('admin.booking.reschedule') }}">
                @csrf
                @method('PUT')
                <div class="modal-content">
                    <div class="modal-header">
                    <h5 class="modal-title" id="rescheduleModalLabel">Reschedule Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="booking_id" id="booking_id" value="">

                        <div class="row">
                            <!-- Date Picker -->
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="availability_date" class="form-label">New Date</label>
                                    <input type="date" class="form-control available_input" name="availability_date" id="availability_date" required>
                                </div>
                            </div>
                            <!-- Time Slot Dropdown -->
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="time_slot_id" class="form-label">Available Time Slots</label>
                                    <select name="time_slot_id" id="time_slot_id" class="form-control available_input" required>
                                        <option value="">Select a time slot</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Booking</button>
                    </div>
                </div>
                </form>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize daterangepicker
                $('#date_range').daterangepicker({
                    autoUpdateInput: false,
                    locale: {
                        cancelLabel: 'Clear',
                        format: 'YYYY-MM-DD'
                    },
                    opens: 'left',
                    maxDate: moment(), // Optional: disallow future dates
                });

                $('#date_range').on('apply.daterangepicker', function(ev, picker) {
                    $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format(
                        'YYYY-MM-DD'));
                });

                $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
                    $(this).val('');
                });

                // If there is already a date_range value, set the picker accordingly
                @if (request('date_range'))
                    let dates = "{{ request('date_range') }}".split(' to ');
                    if (dates.length === 2) {
                        $('#date_range').data('daterangepicker').setStartDate(dates[0]);
                        $('#date_range').data('daterangepicker').setEndDate(dates[1]);
                        $('#date_range').val("{{ request('date_range') }}");
                    }
                @endif
            });
            document.addEventListener('DOMContentLoaded', function() {
                const dateInput = document.getElementById('availability_date');
                const today = new Date().toISOString().split('T')[0];  // Format: yyyy-mm-dd
                dateInput.setAttribute('min', today);
            });
        </script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
                document.querySelectorAll('.btn-reschedule').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var bookingId = this.getAttribute('data-id');
                        console.log(bookingId);

                        document.getElementById('booking_id').value = bookingId;

                        // Show the modal
                        rescheduleModal.show();
                    });
                });

                const dateInput = document.getElementById('availability_date');
                const timeSlotDropdown = document.getElementById('time_slot_id');

                dateInput.addEventListener('change', function () {
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
                                        option.textContent = `${slot.start_time} - ${slot.end_time}`;

                                        // Disable booked slots
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
                            });
                    }
                    });
                });
        </script>
    
    @endsection
