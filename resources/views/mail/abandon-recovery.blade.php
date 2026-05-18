<!DOCTYPE html>
<html lang="en" style="font-family: Arial, sans-serif; background-color: #f6f6f6; padding: 20px;">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex, nofollow">
    <title>Your cart at {{ $shop_name }} is waiting</title>
</head>
<body style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; color: #333;">

    <h2 style="color: #2c3e50; margin-top: 0;">Hi {{ $name }},</h2>

    <p>Thanks for chatting with us today at <strong>{{ $shop_name }}</strong>. You left a few things in your basket — here's a quick reminder so you can pick up where you left off.</p>

    @if(!empty($items))
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 10px; border-bottom: 1px solid #ddd;">Item</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 1px solid #ddd;">Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{{ $item['title'] ?? 'Item' }}</td>
                        <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0; text-align: right;">{{ $item['quantity'] ?? 1 }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if($total_price !== null)
                <tfoot>
                    <tr>
                        <th style="text-align: left; padding: 10px;">Total</th>
                        <th style="text-align: right; padding: 10px;">{{ $total_price }}</th>
                    </tr>
                </tfoot>
            @endif
        </table>
    @else
        <p>{{ $item_count }} item(s) in your basket are still waiting.</p>
    @endif

    <p style="text-align: center; margin: 30px 0;">
        <a href="{{ $cart_url }}"
           style="background: #2c3e50; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">
            Return to my basket
        </a>
    </p>

    @if(!empty($chat_excerpt))
        <h3 style="color: #2c3e50; margin-top: 30px;">From our chat earlier</h3>
        <div style="background: #f9f9f9; padding: 12px 16px; border-radius: 6px;">
            @foreach($chat_excerpt as $line)
                <p style="margin: 4px 0;">
                    <strong>{{ ucfirst($line['role']) }}:</strong> {{ $line['message'] }}
                </p>
            @endforeach
        </div>
    @endif

    <p style="margin-top: 30px; font-size: 12px; color: #999;">
        If you didn't intend to receive this, you can ignore the email — we won't follow up again.
    </p>

</body>
</html>
