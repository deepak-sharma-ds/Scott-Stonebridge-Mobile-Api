<!DOCTYPE html>
<html lang="en" style="font-family: Arial, sans-serif; background-color: #f6f6f6; padding: 20px;">
<head>
    <meta charset="UTF-8" />
    <title>Email Reading On Compliance Hold</title>
</head>
<body style="max-width: 640px; margin: auto; background: #ffffff; padding: 24px; border-radius: 6px; color: #333;">

    <h2 style="color: #b26a00; margin: 0 0 16px 0;">Reading Held for Compliance Review</h2>

    <p style="color: #555;">This reading was stopped before generation because the customer's question touches a sensitive topic. Nothing has been generated or emailed. Please review it and decide how to respond.</p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 14px;">
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa; width: 38%;"><strong>Order ID</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->shopify_order_id ?? 'Manual' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Product</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $product?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Customer</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->customer_name ?? '—' }} &lt;{{ $delivery->customer_email }}&gt;</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Flagged reason</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0; color: #b26a00;">{{ $reason }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ $reviewUrl }}" style="background:#6c3fa1;color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;">Review in admin</a>
    </p>

    <p style="font-size: 12px; color: #999; margin-top: 24px;">
        Delivery ID: {{ $delivery->id }} · Created {{ $delivery->created_at?->toDateTimeString() }}
    </p>

</body>
</html>
