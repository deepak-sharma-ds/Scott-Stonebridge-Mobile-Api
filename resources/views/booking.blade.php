<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Booking</title>

    <!-- ✅ Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5" style="max-width: 400px;">
        <h2 class="text-center mb-4">Book a Meeting</h2>
    
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @error('api_error')
            <div class="alert alert-danger">{{ $message }}</div>
        @enderror
    
        <form method="POST" action="/apps/booking/store">
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text"
                       name="name"
                       id="name"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}"
                       required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
    
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email"
                       name="email"
                       id="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}"
                       required>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
    
            <div class="mb-3">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel"
                       name="phone"
                       id="phone"
                       class="form-control @error('phone') is-invalid @enderror"
                       value="{{ old('phone') }}"
                       required>
                @error('phone')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="availability_date" class="form-label">Select Date</label>
                <input type="date"
                    name="availability_date"
                    id="availability_date"
                    class="form-control @error('availability_date') is-invalid @enderror"
                    value="{{ old('availability_date') }}"
                    required>
                @error('availability_date')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="time_slot_id" class="form-label">Select Time Slot</label>
                <select name="time_slot_id"
                        id="time_slot_id"
                        class="form-control @error('time_slot_id') is-invalid @enderror"
                        required>
                    <option value="">Select a time slot</option>
                </select>
                @error('time_slot_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary w-100">Book Meeting</button>
        </form>
    </div>

    <!-- ✅ Bootstrap 5 JS Bundle (with Popper) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#availability_date').on('change', function () {
            const selectedDate = $(this).val();
            if (selectedDate) {
                $.ajax({
                    url: '/apps/booking/get-time-slots',
                    type: 'GET',
                    data: { date: selectedDate },
                    success: function (response) {
                        $('#time_slot_id').empty().append('<option value="">Select a time slot</option>');
                        if (response.success) {
                            $.each(response.time_slots, function (index, slot) {
                                // if slot.booked = true, disable the option
                                let option = `<option value="${slot.id}" ${slot.booked ? 'disabled' : ''}>${slot.start_time} - ${slot.end_time}${slot.booked ? ' (Booked)' : ''}</option>`;
                                $('#time_slot_id').append(option);
                            });
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
