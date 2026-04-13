@extends('admin.layouts.app')

@section('page-title', 'Availability Calendar')

@section('custom_css_links')
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<style>
/* ── FullCalendar overrides ── */
.fc {
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 0.875rem;
}
.fc-toolbar-title {
    font-size: 1.125rem !important;
    font-weight: 700 !important;
    color: var(--text-primary) !important;
}
.fc-button {
    background: var(--gradient-primary) !important;
    border: none !important;
    border-radius: 7px !important;
    font-size: 0.8125rem !important;
    font-weight: 600 !important;
    padding: 0.375rem 0.875rem !important;
    color: #fff !important;
    box-shadow: none !important;
    transition: opacity 0.15s ease !important;
}
.fc-button:hover  { opacity: 0.88 !important; }
.fc-button:focus  { box-shadow: var(--shadow-glow) !important; }
.fc-button-active { opacity: 0.75 !important; }

.fc-daygrid-day {
    transition: background 0.15s ease;
    cursor: pointer;
}
.fc-daygrid-day:hover { background: rgba(99,102,241,0.04) !important; }

.fc-event {
    background: var(--gradient-primary) !important;
    border: none !important;
    color: #fff !important;
    padding: 3px 8px !important;
    font-weight: 600 !important;
    border-radius: 6px !important;
    box-shadow: 0 2px 6px rgba(99,102,241,0.25) !important;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease !important;
}
.fc-event:hover {
    transform: scale(1.03) !important;
    box-shadow: 0 4px 10px rgba(99,102,241,0.35) !important;
}
.fc-col-header-cell-cushion,
.fc-daygrid-day-number {
    color: var(--text-secondary) !important;
    font-weight: 600 !important;
    text-decoration: none !important;
}
.fc-day-today { background: rgba(99,102,241,0.04) !important; }

/* ── Slot row inside panel ── */
.slot-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 0.75rem;
    border-radius: 9px;
    background: #fff;
    border: 1px solid var(--card-border);
    margin-bottom: 0.5rem;
    box-shadow: var(--shadow-sm);
    transition: all 0.15s ease;
}
.slot-row:hover { transform: translateX(3px); box-shadow: var(--shadow-md); }

.slot-badge {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.625rem;
    border-radius: 20px;
    background: var(--color-primary-muted);
    color: var(--color-primary);
    font-weight: 700;
    font-size: 0.8125rem;
    flex: 1;
}

.slot-delete-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: none;
    background: var(--color-danger-muted);
    color: var(--color-danger);
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    transition: all 0.15s ease;
    flex-shrink: 0;
}
.slot-delete-btn:hover { background: var(--color-danger); color: #fff; }

/* ── Right slide panel ── */
.avail-panel {
    position: fixed;
    top: 0;
    right: 0;
    height: 100vh;
    width: 400px;
    max-width: 92vw;
    background: #fff;
    border-left: 1px solid var(--card-border);
    box-shadow: -10px 0 30px rgba(0,0,0,0.12);
    z-index: 9990;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
}
.avail-panel-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.4);
    backdrop-filter: blur(3px);
    z-index: 9989;
    transition: opacity 0.25s ease;
}
.avail-panel-header {
    background: var(--gradient-primary);
    padding: 1.125rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.avail-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
}
.avail-panel-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--card-border);
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}
</style>
@endsection

@section('content')
<div class="container-fluid"
     x-data="availabilityCalendar()"
     @keydown.escape.window="closePanel()">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Availability Calendar',
        'subtitle' => 'Click any date to manage slots. Click an event to edit.',
    ])

    {{-- Calendar Card --}}
    <div class="card p-4">
        <div id="calendar"></div>
    </div>

    {{-- ── Right Slide Panel (replaces Bootstrap Offcanvas) ── --}}

    {{-- Overlay --}}
    <div class="avail-panel-overlay"
         x-show="panelOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closePanel()">
    </div>

    {{-- Panel --}}
    <aside class="avail-panel"
           id="availabilityPanel"
           x-show="panelOpen"
           x-cloak
           x-transition:enter="transition ease-out duration-300"
           x-transition:enter-start="opacity-0 transform translate-x-full"
           x-transition:enter-end="opacity-100 transform translate-x-0"
           x-transition:leave="transition ease-in duration-200"
           x-transition:leave-start="opacity-100 transform translate-x-0"
           x-transition:leave-end="opacity-0 transform translate-x-full"
           role="dialog"
           aria-modal="true"
           aria-labelledby="panelTitle">

        {{-- Panel Header --}}
        <div class="avail-panel-header">
            <div>
                <div style="font-size:0.6875rem;font-weight:600;text-transform:uppercase;
                            letter-spacing:0.08em;color:rgba(255,255,255,0.65);margin-bottom:0.125rem;">
                    Availability
                </div>
                <h5 id="panelTitle"
                    style="margin:0;font-size:1rem;font-weight:700;color:#fff;"
                    x-text="selectedDateLabel">
                </h5>
            </div>
            <button @click="closePanel()"
                    style="border:none;background:rgba(255,255,255,0.15);
                           color:#fff;width:32px;height:32px;border-radius:8px;
                           cursor:pointer;display:flex;align-items:center;justify-content:center;
                           transition:background 0.15s ease;"
                    onmouseover="this.style.background='rgba(255,255,255,0.25)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.15)'"
                    aria-label="Close">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6"  y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        {{-- Panel Body --}}
        <div class="avail-panel-body">

            {{-- Existing slots --}}
            <div style="margin-bottom:1rem;">
                <div style="font-size:0.6875rem;font-weight:700;text-transform:uppercase;
                            letter-spacing:0.07em;color:var(--text-muted);margin-bottom:0.625rem;">
                    Existing Slots
                </div>
                <div id="existingSlots">
                    <div style="color:var(--text-muted);font-size:0.875rem;">Loading…</div>
                </div>
            </div>

            {{-- Divider --}}
            <div style="height:1px;background:var(--card-border);margin:1rem 0;"></div>

            {{-- Add new slot --}}
            <div>
                <label class="form-label">Add New Slot</label>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="time" id="new_slot_start" class="form-control" style="flex:1;">
                    <span style="color:var(--text-muted);font-weight:600;flex-shrink:0;">—</span>
                    <input type="time" id="new_slot_end" class="form-control" style="flex:1;">
                    <button id="addNewSlotBtn" class="btn btn-primary btn-sm" style="flex-shrink:0;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add
                    </button>
                </div>
            </div>

            {{-- Inline alerts --}}
            <div id="panelAlerts" style="margin-top:0.875rem;"></div>

        </div>

        {{-- Panel Footer --}}
        <div class="avail-panel-footer">
            <button id="saveSlotsBtn" class="btn btn-success" style="flex:1;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                </svg>
                Save Changes
            </button>
            <button id="deleteDateBtn" class="btn btn-danger">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
                Delete Date
            </button>
            <button @click="closePanel()" class="btn btn-secondary">Close</button>
        </div>

    </aside>

</div>
@endsection

@section('custom_js_scripts')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
/* ═══════════════════════════════════════════════════════════════
   Alpine Component: availabilityCalendar
   Manages the slide panel state.
   ALL AJAX logic is kept exactly as originally written.
═══════════════════════════════════════════════════════════════ */
function availabilityCalendar() {
    return {
        panelOpen:         false,
        selectedDateLabel: '',

        openPanel(dateLabel) {
            this.selectedDateLabel = dateLabel;
            this.panelOpen = true;
            document.body.style.overflow = 'hidden';
        },

        closePanel() {
            this.panelOpen = false;
            document.body.style.overflow = '';
        },
    };
}

/* ═══════════════════════════════════════════════════════════════
   Calendar + Panel Logic
   Uses window.alpinePanel to talk to the Alpine component.
═══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var calendarEl = document.getElementById('calendar');
    var csrf       = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var selectedDate       = null;
    var opening            = false;

    /* ── FullCalendar init ── */
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView:    'dayGridMonth',
        dayMaxEventRows: 3,
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,dayGridWeek',
        },
        events: {
            url:    '{{ route('admin.availability.calendar.events') }}',
            method: 'GET',
        },
        dateClick: function (info) {
            if (info.jsEvent.target.closest('.fc-event')) return;
            if (opening) return;
            selectedDate = info.dateStr;
            openPanelForDate(selectedDate);
        },
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            info.jsEvent.stopPropagation();
            if (opening) return;
            selectedDate = info.event.extendedProps.date;
            openPanelForDate(selectedDate);
        },
    });

    calendar.render();

    /* ── Alpine panel control ── */
    function getAlpine() {
        // Walk up from the panel el to find the x-data root
        var root = document.querySelector('[x-data="availabilityCalendar()"]');
        return root ? root._x_dataStack && root._x_dataStack[0] : null;
    }

    function showPanel(label)  {
        var el = document.querySelector('[x-data="availabilityCalendar()"]');
        if (el && window.Alpine) Alpine.$data(el).openPanel(label);
    }

    function hidePanel() {
        var el = document.querySelector('[x-data="availabilityCalendar()"]');
        if (el && window.Alpine) Alpine.$data(el).closePanel();
    }

    /* ── Open panel for a date ── */
    async function openPanelForDate(date) {
        if (opening) return;
        opening = true;

        var label = new Date(date + 'T00:00:00').toDateString();
        document.getElementById('existingSlots').innerHTML =
            '<div style="color:var(--text-muted);font-size:0.875rem;">Loading…</div>';
        document.getElementById('new_slot_start').value = '';
        document.getElementById('new_slot_end').value   = '';
        document.getElementById('panelAlerts').innerHTML = '';

        showPanel(label);

        setTimeout(function () { opening = false; }, 500);

        try {
            var res  = await fetch('{{ url('admin/availability/calendar/day') }}/' + date, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            var json = await res.json();

            if (!json.success) {
                document.getElementById('existingSlots').innerHTML =
                    '<div style="color:var(--text-muted);font-style:italic;">Invalid date or error.</div>';
                return;
            }

            renderSlots(json.slots);
        } catch (e) {
            document.getElementById('existingSlots').innerHTML =
                '<div style="color:var(--color-danger);font-size:0.875rem;">Failed to load slots.</div>';
        }
    }

    /* ── Render slot rows ── */
    function renderSlots(slots) {
        var container = document.getElementById('existingSlots');
        container.innerHTML = '';

        if (!slots || slots.length === 0) {
            container.innerHTML =
                '<div style="color:var(--text-muted);font-style:italic;font-size:0.875rem;">No slots yet.</div>';
            return;
        }

        slots.forEach(function (s) {
            var row           = document.createElement('div');
            row.className     = 'slot-row';
            row.dataset.slotId = s.id;
            row.innerHTML     =
                '<div class="slot-badge">' +
                '    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '        <circle cx="12" cy="12" r="10"></circle>' +
                '        <polyline points="12 6 12 12 16 14"></polyline>' +
                '    </svg>' +
                '    <strong>' + s.start_time + '</strong>' +
                '    <span style="opacity:0.5;">—</span>' +
                '    <strong>' + s.end_time + '</strong>' +
                '</div>' +
                '<button class="slot-delete-btn btn-delete-slot" data-id="' + s.id + '" title="Delete slot">' +
                '    &times;' +
                '</button>';
            container.appendChild(row);
        });
    }

    /* ── Panel alert helper ── */
    function showAlert(message, type) {
        type = type || 'success';
        var container = document.getElementById('panelAlerts');
        var el        = document.createElement('div');
        el.className  = 'alert alert-' + type;
        el.style.cssText = 'font-size:0.875rem;margin-bottom:0.5rem;';
        el.innerText  = message;
        container.prepend(el);
        setTimeout(function () { el.remove(); }, 4500);
    }

    /* ── Add slot (client-side) ── */
    document.getElementById('addNewSlotBtn').addEventListener('click', function () {
        var start = document.getElementById('new_slot_start').value;
        var end   = document.getElementById('new_slot_end').value;

        if (!start || !end) {
            showAlert('Please choose start and end time.', 'warning');
            return;
        }
        if (start >= end) {
            showAlert('End time must be after start time.', 'warning');
            return;
        }

        var container = document.getElementById('existingSlots');
        var row       = document.createElement('div');
        row.className     = 'slot-row';
        row.dataset.new   = '1';
        row.innerHTML     =
            '<div class="slot-badge">' +
            '    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '        <circle cx="12" cy="12" r="10"></circle>' +
            '        <polyline points="12 6 12 12 16 14"></polyline>' +
            '    </svg>' +
            '    <strong>' + start + '</strong>' +
            '    <span style="opacity:0.5;">—</span>' +
            '    <strong>' + end + '</strong>' +
            '</div>' +
            '<button class="slot-delete-btn btn-remove-new-slot" title="Remove">&times;</button>';
        container.appendChild(row);

        document.getElementById('new_slot_start').value = '';
        document.getElementById('new_slot_end').value   = '';
    });

    /* ── Delete existing slot (server) ── */
    document.getElementById('existingSlots').addEventListener('click', async function (e) {
        if (e.target.matches('.btn-delete-slot')) {
            var id = e.target.dataset.id;
            if (!confirm('Delete this slot?')) return;
            try {
                var res  = await fetch('{{ url('admin/availability/calendar/slot') }}/' + id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                });
                var json = await res.json();
                if (!res.ok) { showAlert(json.message || 'Failed to delete.', 'danger'); return; }
                showAlert(json.message || 'Slot deleted.', 'success');
                e.target.closest('.slot-row').remove();
                refreshCalendar();
            } catch (err) {
                showAlert('Error deleting slot.', 'danger');
            }
        }

        if (e.target.matches('.btn-remove-new-slot')) {
            e.target.closest('.slot-row').remove();
        }
    });

    /* ── Save all slots (server) ── */
    document.getElementById('saveSlotsBtn').addEventListener('click', async function () {
        var container = document.getElementById('existingSlots');
        var rows      = Array.from(container.querySelectorAll('.slot-row'));
        var payload   = [];

        rows.forEach(function (r) {
            var times = r.querySelector('.slot-badge').innerText
                .trim()
                .split('—')
                .map(function (s) { return s.trim(); });
            payload.push({ start_time: times[0], end_time: times[1] });
        });

        if (payload.length === 0) {
            showAlert('Add at least one slot before saving.', 'warning');
            return;
        }

        try {
            var res  = await fetch('{{ url('admin/availability/calendar/day') }}/' + selectedDate, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ time_slots: payload }),
            });
            var json = await res.json();

            if (!res.ok) {
                if (json.errors) {
                    var msgs = [];
                    Object.values(json.errors).forEach(function (v) {
                        if (Array.isArray(v)) msgs.push.apply(msgs, v);
                    });
                    showAlert(msgs.join(', '), 'danger');
                } else {
                    showAlert(json.message || 'Failed to save.', 'danger');
                }
                return;
            }

            showAlert(json.message || 'Saved!', 'success');
            hidePanel();
            refreshCalendar();
        } catch (err) {
            showAlert('Error saving slots.', 'danger');
        }
    });

    /* ── Delete entire date (server) ── */
    document.getElementById('deleteDateBtn').addEventListener('click', async function () {
        if (!confirm('Delete all slots for this date? This cannot be undone.')) return;
        try {
            var res  = await fetch('{{ url('admin/availability/calendar/day') }}/' + selectedDate, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
            });
            var json = await res.json();
            if (!res.ok) { showAlert(json.message || 'Failed to delete date.', 'danger'); return; }
            showAlert(json.message || 'Date deleted.', 'success');
            hidePanel();
            refreshCalendar();
        } catch (err) {
            showAlert('Error deleting date.', 'danger');
        }
    });

    function refreshCalendar() {
        calendar.refetchEvents();
    }
});
</script>
@endsection
