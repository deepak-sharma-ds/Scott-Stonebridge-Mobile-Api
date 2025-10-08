<!DOCTYPE html>
<html lang="en" style="font-family: Arial, sans-serif; background-color: #f6f6f6; padding: 20px;">
<head>
    <meta charset="UTF-8" />
    <title>Booking Confirmation</title>
</head>
<body style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; color: #333;">

    <!-- Logo -->
    <div style="text-align: center; margin-bottom: 30px;">
        <img src="{{ asset('storage/configuration-images/'.config('Site.logo')) }}" alt="Company Logo" style="max-height: 80px;">
    </div>

    <h2 style="color: #2c3e50;">Booking Confirmation</h2>

    <p>Hi <strong>{{ $booking->name }}</strong>,</p>

    <p>Thank you for booking with us! Here are your booking details:</p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;">Booking Date</td>
            <td style="padding: 10px; border: 1px solid #ddd;">{{ \Carbon\Carbon::parse($booking->availability_date)->format('F j, Y') }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;">Time Slot</td>
            <td style="padding: 10px; border: 1px solid #ddd;">{{ $booking->timeSlot->start_time }} - {{ $booking->timeSlot->end_time }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;">Email</td>
            <td style="padding: 10px; border: 1px solid #ddd;">{{ $booking->email }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;">Phone</td>
            <td style="padding: 10px; border: 1px solid #ddd;">{{ $booking->phone }}</td>
        </tr>
    </table>

    <p style="margin-top: 30px;">If you have any questions or need to reschedule, please contact us.</p>

    <p>Best regards,<br> <strong>Scottstonebridge</strong></p>

</body>
</html>