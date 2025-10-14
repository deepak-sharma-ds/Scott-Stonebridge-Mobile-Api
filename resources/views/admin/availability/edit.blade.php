@extends('admin.layouts.app')

@section('content')
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-
            text">
                <h4>Edit Availability</h4>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.availability.index') }}">Availability</a></li>
                <li class="breadcrumb-item active">View</li>
            </ol>
        </div>
    </div>
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @error('api_error')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror
    <!-- Show general form errors -->
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Edit Availability Slots</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.availability.update', $availabilityDate->id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="form-row">
                            <!-- Left Half: Calendar -->
                            <div class="col-md-6 calendar_first_half">
                                <div class="form-group">
                                    <!-- Calendar Navigation -->
                                    <div class="calendar-navigation">
                                        <button type="button" id="prev-month" class="btn btn-outline-primary"><i
                                                class="fa-solid fa-less-than"></i></button>
                                        <button type="button" id="today-button"
                                            class="btn btn-outline-primary">Today</button>
                                        <button type="button" id="next-month" class="btn btn-outline-primary"><i
                                                class="fa-solid fa-greater-than"></i></button>
                                    </div>

                                    <div id="calendar" class="calendar">
                                        <!-- Calendar will be generated dynamically -->
                                    </div>
                                    <input type="hidden" name="date" id="selected-date"
                                        value="{{ $availabilityDate->date }}">
                                </div>
                            </div>

                            <!-- Right Half: Time Slots -->
                            <div class="col-md-6 calendar_second_half">
                                <div class="form-group">
                                    <label for="time_slots">Time Slots</label>
                                    <div id="time-slots">
                                        @foreach ($availabilityDate->timeSlots as $index => $timeSlot)
                                            <div class="time-slot" id="time-slot-{{ $index }}">
                                                <input type="time" name="time_slots[{{ $index }}][start_time]"
                                                    value="{{ $timeSlot->start_time }}" required class="form-control">
                                                <input type="time" name="time_slots[{{ $index }}][end_time]"
                                                    value="{{ $timeSlot->end_time }}" required class="form-control">
                                                <button type="button" class="btn btn-danger remove-time-slot"
                                                    data-slot-id="{{ $timeSlot->id }}">&times;</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button" id="add-time-slot" class="btn btn-primary">Add Time Slot</button>

                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 text-right">
                                <button type="submit" class="btn btn-primary">Update</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function fetchUKBankHolidays(year) {
            const response = await fetch('https://www.gov.uk/bank-holidays.json');
            const data = await response.json();

            // Combine all holidays from England and Wales (adjust region if needed)
            const events = data['england-and-wales'].events;

            // Filter only holidays from the desired year
            const holidays = events
                .filter(event => new Date(event.date).getFullYear() === year)
                .map(event => event.date); // in 'YYYY-MM-DD' format

            return holidays;
        }
        document.addEventListener('DOMContentLoaded', async function() {
            const calendarContainer = document.getElementById('calendar');
            const selectedDateInput = document.getElementById('selected-date');
            const todayButton = document.getElementById('today-button');
            const prevMonthButton = document.getElementById('prev-month');
            const nextMonthButton = document.getElementById('next-month');

            let currentDate = new Date();
            let month = currentDate.getMonth();
            let year = currentDate.getFullYear();

            // Log the selected date to check if it's set correctly
            console.log('Selected Date:', selectedDateInput
            .value); // This should log "2025-09-12" or the value from the DB

            // Generate the calendar for the current month
            await generateCalendar(month, year);

            // Handle the 'Today' button click
            todayButton.addEventListener('click', async function() {
                currentDate = new Date(); // Set to today's date
                month = currentDate.getMonth();
                year = currentDate.getFullYear();
                await generateCalendar(month, year); // Re-generate the calendar
            });

            // Handle the 'Next' month button click
            nextMonthButton.addEventListener('click', async function() {
                if (month === 11) {
                    month = 0;
                    year++;
                } else {
                    month++;
                }
                await generateCalendar(month, year);
            });

            // Handle the 'Previous' month button click
            prevMonthButton.addEventListener('click', async function() {
                if (month === 0) {
                    month = 11;
                    year--;
                } else {
                    month--;
                }
                await generateCalendar(month, year);
            });

            async function generateCalendar(month, year) {
                const firstDay = new Date(year, month, 1);
                const lastDay = new Date(year, month + 1, 0);
                const daysInMonth = lastDay.getDate();
                const startingDay = firstDay.getDay();
                const holidays = await fetchUKBankHolidays(year);

                let calendarHTML = '<div class="calendar-header">' +
                    '<span class="month-name">' + firstDay.toLocaleString('default', {
                        month: 'long'
                    }) + ' ' + year + '</span>' +
                    '</div><div class="calendar-grid">';

                // Add empty cells before the first day
                for (let i = 0; i < startingDay; i++) {
                    calendarHTML += '<div class="calendar-day empty"></div>';
                }

                // Add day cells
                for (let day = 1; day <= daysInMonth; day++) {
                    const currentDay = new Date(year, month, day);
                    const dateStr = currentDay.toISOString().split('T')[0];
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const isPastDate = currentDay < today;

                    // Disable past dates by adding 'disabled' class
                    const isHoliday = holidays.includes(dateStr);
                    const isDisabled = isPastDate || isHoliday ? 'disabled' : '';

                    const currentDateString =
                        `${year}-${(month + 1).toString().padStart(2, '0')}-${(day).toString().padStart(2, '0')}`; // Format: YYYY-MM-DD

                    // Check if this date matches the preselected date
                    const isSelected = (currentDateString === selectedDateInput.value) ? 'selected' : '';

                    calendarHTML += `
                        <div class="calendar-day ${isDisabled} ${isSelected}" data-date="${currentDateString}">
                            <label class="date-label">
                                <input type="radio" name="calendar-date" class="calendar-radio" value="${currentDateString}" style="display:none;" ${isDisabled ? 'disabled' : ''}>
                                <span class="date-text">${day}</span>
                            </label>
                        </div>
                    `;
                }

                calendarHTML += '</div>';
                calendarContainer.innerHTML = calendarHTML;

                // Handle date selection
                const dateElements = document.querySelectorAll('.calendar-day');
                dateElements.forEach((dayElement) => {
                    dayElement.addEventListener('click', function() {
                        // Reset all dates
                        document.querySelectorAll('.calendar-day').forEach((el) => el
                            .classList.remove('selected'));

                        // Select this date
                        dayElement.classList.add('selected');
                        selectedDateInput.value = dayElement.dataset
                        .date; // Store selected date
                    });
                });

                // Set today's date as the default if no date is selected
                const todayString = new Date().toISOString().split('T')[0];
                if (!selectedDateInput.value) {
                    selectedDateInput.value = todayString;
                    document.querySelector(`.calendar-day[data-date="${todayString}"]`)?.classList.add(
                        'selected');
                } else {
                    // Preselect the date from DB
                    const selectedDateString = selectedDateInput.value.split(' ')[0]; // Normalize format
                    console.log('Preselecting:', selectedDateString); // Debug log
                    document.querySelector(`.calendar-day[data-date="${selectedDateString}"]`)?.classList
                        .add('selected');
                }
            }
        });
        let slotCount = {{ count($availabilityDate->timeSlots) }};
        let currentDate = new Date();
        let selectedDate = new Date("{{ $availabilityDate->date }}");
        let month = selectedDate.getMonth();
        let year = selectedDate.getFullYear();

        // Generate the calendar for the current month
        generateCalendar(month, year);

        // Handle the 'Today' button click
        document.getElementById('today-button').addEventListener('click', function() {
            currentDate = new Date(); // Set to today's date
            month = currentDate.getMonth();
            year = currentDate.getFullYear();
            generateCalendar(month, year); // Re-generate the calendar
        });

        // Handle the 'Next' month button click
        document.getElementById('next-month').addEventListener('click', function() {
            if (month === 11) {
                month = 0;
                year++;
            } else {
                month++;
            }
            generateCalendar(month, year);
        });

        // Handle the 'Previous' month button click
        document.getElementById('prev-month').addEventListener('click', function() {
            if (month === 0) {
                month = 11;
                year--;
            } else {
                month--;
            }
            generateCalendar(month, year);
        });

        // Generate calendar HTML dynamically
        function generateCalendar(month, year) {
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startingDay = firstDay.getDay();

            let calendarHTML = '<div class="calendar-header">' +
                '<span class="month-name">' + firstDay.toLocaleString('default', {
                    month: 'long'
                }) + ' ' + year + '</span>' +
                '</div><div class="calendar-grid">';

            // Add empty cells before the first day
            for (let i = 0; i < startingDay; i++) {
                calendarHTML += '<div class="calendar-day empty"></div>';
            }

            // Add day cells
            for (let day = 1; day <= daysInMonth; day++) {
                const currentDay = new Date(year, month, day);
                const isPastDate = currentDay < new Date(); // Check if the date is in the past

                // Disable past dates by adding 'disabled' class
                const isDisabled = isPastDate ? 'disabled' : '';

                const currentDateString = `${year}-${month + 1}-${day}`; // Format: YYYY-MM-DD

                // Check if this date matches the preselected date
                const isSelected = (currentDateString === document.getElementById('selected-date').value) ? 'selected' : '';

                calendarHTML += `
                    <div class="calendar-day ${isDisabled} ${isSelected}" data-date="${currentDateString}">
                        <label class="date-label">
                            <input type="radio" name="calendar-date" class="calendar-radio" value="${currentDateString}" style="display:none;" ${isDisabled ? 'disabled' : ''}>
                            <span class="date-text">${day}</span>
                        </label>
                    </div>
                `;
            }

            calendarHTML += '</div>';
            document.getElementById('calendar').innerHTML = calendarHTML;

            // Handle date selection
            const dateElements = document.querySelectorAll('.calendar-day');
            dateElements.forEach((dayElement) => {
                dayElement.addEventListener('click', function() {
                    // Reset all dates
                    document.querySelectorAll('.calendar-day').forEach((el) => el.classList.remove(
                        'selected'));

                    // Select this date
                    dayElement.classList.add('selected');
                    document.getElementById('selected-date').value = dayElement.dataset
                    .date; // Store selected date
                });
            });

            // Set today's date as the default if no date is selected
            const todayString = new Date().toISOString().split('T')[0];
            if (!document.getElementById('selected-date').value) {
                document.getElementById('selected-date').value = todayString;
                document.querySelector(`.calendar-day[data-date="${todayString}"]`)?.classList.add('selected');
            } else {
                // Preselect the date from DB
                const selectedDateString = document.getElementById('selected-date').value;
                document.querySelector(`.calendar-day[data-date="${selectedDateString}"]`)?.classList.add('selected');
            }
        }

        // Handle adding a new time slot
        document.getElementById('add-time-slot').addEventListener('click', function() {
            let slotHtml = `
                <div class="time-slot" id="time-slot-${slotCount}">
                    <input type="time" name="time_slots[${slotCount}][start_time]" required class="form-control">
                    <input type="time" name="time_slots[${slotCount}][end_time]" required class="form-control">
                    <button type="button" class="btn btn-danger remove-time-slot" data-slot-id="new-${slotCount}">&times;</button>
                </div>
            `;
            document.getElementById('time-slots').insertAdjacentHTML('beforeend', slotHtml);
            slotCount++;
        });

        // Handle removing a time slot (with confirmation)
        // document.getElementById('time-slots').addEventListener('click', function(event) {
        //     if (event.target.classList.contains('remove-time-slot')) {
        //         const slotId = event.target.getAttribute('data-slot-id');

        //         // Show confirmation dialog before deletion
        //         const confirmation = confirm('Are you sure you want to delete this time slot?');

        //         if (confirmation) {
        //             // If this is a new slot (not saved to DB), just remove the HTML element
        //             if (slotId.startsWith('new-')) {
        //                 document.getElementById(`time-slot-${slotId.split('-')[1]}`).remove();
        //             } else {
        //                 // Send AJAX request to delete the time slot from the database
        //                 deleteTimeSlot(slotId, event.target);
        //             }
        //         }
        //     }
        // });
        document.getElementById('time-slots').addEventListener('click', function(event) {
            if (event.target.classList.contains('remove-time-slot')) {
                const slotId = event.target.getAttribute('data-slot-id');

                // Use SweetAlert2 for confirmation
                Swal.fire({
                    title: 'Are you sure?',
                    text: "You want to delete this time slot?",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // If this is a new slot (not saved to DB), just remove the HTML element
                        if (slotId.startsWith('new-')) {
                            const el = document.getElementById(`time-slot-${slotId.split('-')[1]}`);
                            if (el) el.remove();

                            // Optional: show success notification
                            Swal.fire('Deleted!', 'Time slot removed successfully.', 'success');
                        } else {
                            // Send AJAX request to delete the time slot from the database
                            deleteTimeSlot(slotId, event.target);
                        }
                    }
                });
            }
        });


        // AJAX function to delete time slot from database
        function deleteTimeSlot(slotId, button) {
            showLoader();
            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch(`/admin/availability/time-slot/${slotId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token
                    },
                    body: JSON.stringify({
                        id: slotId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    hideLoader();
                    if (data.success) {
                        button.closest('.time-slot').remove(); // Remove the HTML element
                    } else {
                        // Use notify.js to show error message
                        $.notify(data.message || 'Failed to delete the time slot.', "error");
                    }
                })
                .catch(error => {
                    hideLoader();
                    $.notify('Error deleting time slot.', "error");
                    console.error(error);
                });
        }
    </script>
@endsection
