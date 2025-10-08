@extends('admin.layouts.app')

@section('content')

<div class="container">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-
            text">
                <h4>Availability</h4>
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
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
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
                    <h4 class="card-title">Create Availability Slots</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.availability.store') }}" method="POST">
                        @csrf
                        <div class="form-row">
                            <!-- Left Half: Calendar -->
                            <div class="col-md-6 calendar_first_half">
                                <div class="form-group">
                                    <!-- Calendar Navigation -->
                                    <div class="calendar-navigation">
                                        <button type="button" id="prev-month" class="btn btn-outline-primary"><i class="fa-solid fa-less-than"></i></button>
                                        <button type="button" id="today-button" class="btn btn-outline-primary">Today</button>
                                        <button type="button" id="next-month" class="btn btn-outline-primary"><i class="fa-solid fa-greater-than"></i></button>
                                    </div>

                                    <!-- Calendar Grid -->
                                    <div id="calendar" class="calendar">
                                        <!-- Calendar will be dynamically generated here -->
                                    </div>

                                    <input type="hidden" name="date" id="selected-date">
                                </div>
                            </div>

                            <!-- Right Half: Time Slots -->
                            <div class="col-md-6 calendar_second_half">
                                <div class="form-group">
                                    <label for="time_slots">Time Slots</label>
                                    <div id="time-slots">
                                        <div class="time-slot">
                                            <input type="time" name="time_slots[0][start_time]" required class="form-control">
                                            <input type="time" name="time_slots[0][end_time]" required class="form-control">
                                        </div>
                                    </div>
                                    <button type="button" id="add-time-slot" class="btn btn-primary">Add Time Slot</button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
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

        await generateCalendar(month, year);

        todayButton.addEventListener('click', async function() {
            currentDate = new Date();
            month = currentDate.getMonth();
            year = currentDate.getFullYear();
            await generateCalendar(month, year);
        });

        nextMonthButton.addEventListener('click', async function() {
            if (month === 11) {
                month = 0;
                year++;
            } else {
                month++;
            }
            await generateCalendar(month, year);
        });

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
                '<span class="month-name">' + firstDay.toLocaleString('default', { month: 'long' }) + ' ' + year + '</span>' +
                '</div><div class="calendar-grid">';

            for (let i = 0; i < startingDay; i++) {
                calendarHTML += '<div class="calendar-day empty"></div>';
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const currentDay = new Date(year, month, day);
                const dateStr =  `${year}-${(month + 1).toString().padStart(2, '0')}-${(day).toString().padStart(2, '0')}`; // Format: YYYY-MM-DD

                const today = new Date();
                today.setHours(0, 0, 0, 0);

                const isPastDate = currentDay < today;
                const isHoliday = holidays.includes(dateStr);
                const isDisabled = isPastDate || isHoliday;
                const disabledAttr = isDisabled ? 'disabled' : '';
                const disabledClass = isDisabled ? 'disabled' : '';

                calendarHTML += `
                    <div class="calendar-day ${disabledClass}" data-date="${dateStr}">
                        <label class="date-label">
                            <input type="radio" name="calendar-date" class="calendar-radio"
                                value="${dateStr}"
                                style="display:none;"
                                ${disabledAttr}>
                            <span class="date-text">${day}</span>
                        </label>
                    </div>
                `;
            }

            calendarHTML += '</div>';
            calendarContainer.innerHTML = calendarHTML;

            const dateElements = document.querySelectorAll('.calendar-day');
            dateElements.forEach((dayElement) => {
                if (dayElement.classList.contains('disabled')) return;

                dayElement.addEventListener('click', function () {
                    document.querySelectorAll('.calendar-day').forEach((el) => el.classList.remove('selected'));
                    dayElement.classList.add('selected');
                    selectedDateInput.value = dayElement.dataset.date;
                });
            });
        }
    });

    let slotCount = 1;
    document.getElementById('add-time-slot').addEventListener('click', function () {
        let slotHtml = `
            <div class="time-slot" id="time-slot-${slotCount}">
                <input type="time" name="time_slots[${slotCount}][start_time]" required class="form-control">
                <input type="time" name="time_slots[${slotCount}][end_time]" required class="form-control">
                <button type="button" class="btn btn-danger remove-time-slot" data-slot-id="${slotCount}">&times;</button>
            </div>
        `;
        document.getElementById('time-slots').insertAdjacentHTML('beforeend', slotHtml);
        slotCount++;
    });

    document.getElementById('time-slots').addEventListener('click', function(event) {
        if (event.target.classList.contains('remove-time-slot')) {
            const slotId = event.target.getAttribute('data-slot-id');
            document.getElementById(`time-slot-${slotId}`).remove();
        }
    });
</script>

<style>
   
</style>

@endsection
