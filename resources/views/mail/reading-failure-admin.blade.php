<!DOCTYPE html>
<html lang="en" style="font-family: Arial, sans-serif; background-color: #f6f6f6; padding: 20px;">
<head>
    <meta charset="UTF-8" />
    <title>Email Reading Failure</title>
</head>
<body style="max-width: 640px; margin: auto; background: #ffffff; padding: 24px; border-radius: 6px; color: #333;">

    <h2 style="color: #b00020; margin: 0 0 16px 0;">Email Reading Delivery Failed</h2>

    <p style="color: #555;">A reading delivery could not be completed. Details below for manual follow-up with the customer.</p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 14px;">
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa; width: 38%;"><strong>Order ID</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->shopify_order_id }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Line Item ID</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->shopify_line_item_id }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Product</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $product?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Customer Email</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->customer_email }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Customer Name</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->customer_name ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Status</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->status }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Attempts</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0;">{{ $delivery->attempts }}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e0e0e0; background:#fafafa;"><strong>Reason</strong></td>
            <td style="padding: 10px; border: 1px solid #e0e0e0; color: #b00020;">{{ $reason }}</td>
        </tr>
    </table>

    @if(!empty($delivery->questions))
        <h3 style="margin-top: 24px; color: #333;">Captured Questions</h3>
        <pre style="background: #f6f6f6; padding: 12px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere;">{{ json_encode($delivery->questions, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    @endif

    <p style="font-size: 12px; color: #999; margin-top: 24px;">
        Delivery ID: {{ $delivery->id }} · Generated {{ $delivery->created_at?->toDateTimeString() }}
    </p>

</body>
</html>
