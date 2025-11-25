@extends('admin.layouts.app')

@section('content')
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />

    <style>
        /* small visual improvements */
        #calendar {
            max-width: 100%;
            margin: 0 auto;
        }

        .slot-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: .5rem;
        }

        .slot-badge {
            background: #f1f5f9;
            padding: .35rem .6rem;
            border-radius: .375rem;
            display: flex;
            gap: .5rem;
            align-items: center;
        }

        .slot-delete-btn {
            background: transparent;
            border: none;
            color: #d9534f;
            cursor: pointer;
            font-size: 1rem
        }

        /* Smooth fade animations */
        .fc-daygrid-day,
        .fc-event {
            transition: 0.25s ease-in-out;
        }

        .fc-daygrid-day:hover {
            transform: scale(1.02);
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border-radius: 8px;
        }

        /* Event badge styling */
        .fc-event {
            background: linear-gradient(135deg, #0B27C6 0%, #F24FD8 100%);
            border: none !important;
            color: #fff !important;
            padding: 6px 8px;
            font-weight: 600;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        /* 3D Glow Hover */
        .fc-event:hover {
            transform: scale(1.04);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.30);
        }

        /* Offcanvas premium look */
        .offcanvas {
            background: #f8f9ff;
            backdrop-filter: blur(10px);
            box-shadow: -10px 0px 25px rgba(0, 0, 0, 0.15);
            border-left: 1px solid rgba(255, 255, 255, 0.4);
        }

        /* Strong date label */
        #selectedDateLabel {
            font-size: 1.3rem;
            font-weight: 700;
            color: #4e5fee;
        }

        /* Slot item */
        .slot-row {
            padding: 10px;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05);
            transition: 0.2s ease;
        }

        .slot-row:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 14px rgba(0, 0, 0, 0.12);
        }

        /* Add Slot Button premium */
        #addNewSlotBtn {
            background: linear-gradient(135deg, #4e73df, #1cc88a);
            border: none;
            font-weight: 600;
            color: #fff;
            transition: 0.2s ease;
        }

        #addNewSlotBtn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.20);
        }

        /* Save button */
        #saveSlotsBtn {
            background: linear-gradient(135deg, #1cc88a, #36b9cc);
            border: none;
            font-weight: bold;
            color: #fff;
        }

        #saveSlotsBtn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
        }

        /* Delete button */
        #deleteDateBtn {
            background: linear-gradient(135deg, #e74a3b, #be261a);
            border: none;
            font-weight: bold;
            color: #fff;
        }

        #deleteDateBtn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(255, 0, 0, 0.25);
        }

        .offcanvas.show {
            /* animation: slideIn 0.4s ease forwards; */
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>

    <div class="container">

        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Availability Calendar</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Availability Calendar</li>
                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="container-fluid py-4">
                    <div class="card shadow-lg border-0 p-4"
                        style="border-radius: 20px; background: linear-gradient(145deg,#ffffff,#eef2ff);">
                        <div class="row">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Offcanvas Sidebar for editing a date -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="availabilityOffcanvas"
        aria-labelledby="availabilityOffcanvasLabel">
        <div class="offcanvas-header" style="background: linear-gradient(135deg,#4e73df,#1cc88a); color: #fff;">
            <h5 id="availabilityOffcanvasLabel" class="fw-bold">Availability</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div id="offcanvasContent">
                <div class="mb-2">
                    <strong id="selectedDateLabel"></strong>
                </div>

                <div id="existingSlots" class="mb-3">
                    <!-- existing slots will be injected here -->
                </div>

                <div class="mb-2">
                    <label class="form-label">Add New Slot</label>
                    <div class="d-flex gap-2">
                        <input type="time" id="new_slot_start" class="form-control" />
                        <input type="time" id="new_slot_end" class="form-control" />
                        <button id="addNewSlotBtn" class="btn btn-primary">Add</button>
                    </div>
                </div>

                <div class="mt-3">
                    <button id="saveSlotsBtn" class="btn btn-success">Save</button>
                    <button id="deleteDateBtn" class="btn btn-danger">Delete Date</button>
                    <button class="btn btn-secondary" data-bs-dismiss="offcanvas">Close</button>
                </div>

                <div id="offcanvasAlerts" class="mt-3"></div>
            </div>
        </div>
    </div>

    <meta name="csrf-token" content="{{ csrf_token() }}">
@endsection

@section('custom_js_scripts')
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            let selectedDate = null;
            let availabilityExists = false; // whether date already exists in DB
            let opening = false;

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                dayMaxEventRows: 3,
                events: {
                    url: "{{ route('admin.availability.calendar.events') }}",
                    method: 'GET'
                },
                dateClick: function(info) {

                    // If the click originated within an event → skip dateClick
                    if (info.jsEvent.target.closest('.fc-event')) {
                        return;
                    }

                    if (opening) return;

                    selectedDate = info.dateStr;
                    openOffcanvasForDate(selectedDate);
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    info.jsEvent.stopPropagation();

                    if (opening) return;

                    selectedDate = info.event.extendedProps.date;
                    openOffcanvasForDate(selectedDate);
                }
            });

            calendar.render();

            // Offcanvas element & bootstrap control
            const offcanvasEl = document.getElementById('availabilityOffcanvas');
            const bootstrapOffcanvas = new bootstrap.Offcanvas(offcanvasEl);

            function showAlert(message, type = 'success') {
                const container = document.getElementById('offcanvasAlerts');
                const el = document.createElement('div');
                el.className = `alert alert-${type}`;
                el.innerText = message;
                container.prepend(el);
                setTimeout(() => el.remove(), 4000);
            }

            async function openOffcanvasForDate(date) {
                if (opening) return; // block second trigger
                opening = true;
                console.log('Opening offcanvas for date:', date);

                document.getElementById('selectedDateLabel').innerText = (new Date(date)).toDateString();
                document.getElementById('existingSlots').innerHTML = '<p>Loading...</p>';
                document.getElementById('new_slot_start').value = '';
                document.getElementById('new_slot_end').value = '';
                document.getElementById('offcanvasAlerts').innerHTML = '';

                bootstrapOffcanvas.show();
                setTimeout(() => opening = false, 500);

                try {
                    const res = await fetch(`{{ url('admin/availability/calendar/day') }}/${date}`, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const json = await res.json();

                    if (!json.success) {
                        document.getElementById('existingSlots').innerHTML =
                            '<div class="text-muted">Invalid date or error.</div>';
                        availabilityExists = false;
                        return;
                    }

                    availabilityExists = json.exists;

                    renderSlots(json.slots);
                } catch (e) {
                    document.getElementById('existingSlots').innerHTML =
                        '<div class="text-danger">Failed to load slots.</div>';
                    availabilityExists = false;
                }
            }

            function renderSlots(slots) {
                const container = document.getElementById('existingSlots');
                container.innerHTML = '';
                if (!slots || slots.length === 0) {
                    container.innerHTML = '<div class="text-muted">No slots yet</div>';
                    return;
                }
                slots.forEach(s => {
                    const row = document.createElement('div');
                    row.className = 'slot-row';
                    row.dataset.slotId = s.id;
                    row.innerHTML = `
                        <div class="slot-badge">
                            <strong>${s.start_time}</strong>
                            <span>—</span>
                            <strong>${s.end_time}</strong>
                        </div>
                        <button class="slot-delete-btn btn-delete-slot" data-id="${s.id}" title="Delete slot">&times;</button>
                    `;
                    container.appendChild(row);
                });
            }

            // Add new slot client-side only
            document.getElementById('addNewSlotBtn').addEventListener('click', function() {
                const start = document.getElementById('new_slot_start').value;
                const end = document.getElementById('new_slot_end').value;

                if (!start || !end) {
                    showAlert('Please choose start and end time', 'warning');
                    return;
                }

                if (start >= end) {
                    showAlert('End time must be after start time', 'warning');
                    return;
                }

                // Add to existingSlots UI as unsaved slot (we'll mark data-new="1")
                const container = document.getElementById('existingSlots');
                const row = document.createElement('div');
                row.className = 'slot-row';
                row.dataset.new = '1';
                row.innerHTML = `
                    <div class="slot-badge">
                        <strong>${start}</strong>
                        <span>—</span>
                        <strong>${end}</strong>
                    </div>
                    <button class="slot-delete-btn btn-remove-new-slot" title="Remove">&times;</button>
                `;
                container.appendChild(row);

                // clear inputs
                document.getElementById('new_slot_start').value = '';
                document.getElementById('new_slot_end').value = '';
            });

            // Delete existing slot (server call)
            document.getElementById('existingSlots').addEventListener('click', async function(e) {
                if (e.target.matches('.btn-delete-slot')) {
                    const id = e.target.dataset.id;
                    if (!confirm('Are you sure you want to delete this slot?')) return;
                    try {
                        const res = await fetch(`{{ url('admin/availability/calendar/slot') }}/${id}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            }
                        });
                        const json = await res.json();
                        if (!res.ok) {
                            showAlert(json.message || 'Failed to delete', 'danger');
                            return;
                        }
                        showAlert(json.message || 'Deleted', 'success');
                        // remove from UI
                        e.target.closest('.slot-row').remove();
                        refreshCalendarEvents();
                    } catch (err) {
                        showAlert('Error deleting slot', 'danger');
                    }
                }

                // Remove a client-side added slot (not saved yet)
                if (e.target.matches('.btn-remove-new-slot')) {
                    e.target.closest('.slot-row').remove();
                }
            });

            // Save slots (create date if necessary)
            document.getElementById('saveSlotsBtn').addEventListener('click', async function() {
                // Gather all displayed slots:
                const container = document.getElementById('existingSlots');
                const rows = Array.from(container.querySelectorAll('.slot-row'));

                const payloadSlots = [];

                // include both existing saved slots (we will keep them) and newly added ones
                rows.forEach(r => {
                    if (r.dataset.slotId) {
                        // saved existing slot — include its times by reading text
                        const times = r.querySelector('.slot-badge').innerText.trim().split('—')
                            .map(s => s.trim());
                        payloadSlots.push({
                            start_time: times[0],
                            end_time: times[1]
                        });
                    } else if (r.dataset.new) {
                        const times = r.querySelector('.slot-badge').innerText.trim().split('—')
                            .map(s => s.trim());
                        payloadSlots.push({
                            start_time: times[0],
                            end_time: times[1]
                        });
                    }
                });

                if (payloadSlots.length === 0) {
                    showAlert('Add at least one slot before saving', 'warning');
                    return;
                }

                try {
                    const res = await fetch(
                        `{{ url('admin/availability/calendar/day') }}/${selectedDate}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                time_slots: payloadSlots
                            })
                        });
                    const json = await res.json();

                    if (!res.ok) {
                        if (json.errors) {
                            // validation errors
                            const messages = [];
                            Object.values(json.errors).forEach(v => {
                                if (Array.isArray(v)) messages.push(...v);
                            });
                            showAlert(messages.join(', '), 'danger');
                        } else {
                            showAlert(json.message || 'Failed to save', 'danger');
                        }
                        return;
                    }

                    showAlert(json.message || 'Saved', 'success');
                    bootstrapOffcanvas.hide();
                    refreshCalendarEvents();
                } catch (err) {
                    showAlert('Error saving slots', 'danger');
                }
            });

            // delete entire date
            document.getElementById('deleteDateBtn').addEventListener('click', async function() {
                if (!confirm(
                        'Delete entire date? This will remove all slots for this date (only allowed if no bookings).'
                    )) return;
                try {
                    const res = await fetch(
                        `{{ url('admin/availability/calendar/day') }}/${selectedDate}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            }
                        });
                    const json = await res.json();
                    if (!res.ok) {
                        showAlert(json.message || 'Failed to delete date', 'danger');
                        return;
                    }
                    showAlert(json.message || 'Date deleted', 'success');
                    bootstrapOffcanvas.hide();
                    refreshCalendarEvents();
                } catch (err) {
                    showAlert('Error deleting date', 'danger');
                }
            });

            function refreshCalendarEvents() {
                calendar.refetchEvents();
            }
        });
    </script>
@endsection
