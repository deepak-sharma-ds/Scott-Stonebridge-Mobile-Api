<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $delivery->campaignProduct?->product_title ?? 'Your Psychic Email Reading' }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f1ec; }
        img { max-width: 100%; }
        @media only screen and (max-width: 600px) {
            .ss-wrapper { padding: 16px !important; }
            .ss-body { padding: 24px 20px !important; }
            .ss-reading { padding: 18px 16px !important; font-size: 14px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f1ec;font-family:'Georgia','Times New Roman',serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ec;">
        <tr>
            <td align="center" class="ss-wrapper" style="padding:24px 12px;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 18px rgba(43,26,61,0.12);">

                    {{-- Header banner (SMS-upsell copy is baked into the image itself) --}}
                    <tr>
                        <td style="padding:0;">
                            <img src="{{ $headerImageUrl }}" alt="{{ $delivery->campaignProduct?->product_title ?? 'Scott Stonebridge' }}" style="display:block;width:100%;max-width:640px;">
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="ss-body" style="padding:36px 32px;">
                            <h1 style="font-family:'Georgia',serif;font-size:22px;color:#3a1b5e;text-decoration:underline;margin:0 0 20px;">
                                Your Psychic Email Reading
                            </h1>

                            <p style="font-size:16px;color:#2b1a3d;margin:0 0 18px;">
                                Hello
                            </p>

                            <div style="font-size:15px;color:#4a3a5e;line-height:1.6;margin:0 0 22px;white-space:pre-line;">{!! $emailContent !!}</div>

                            <div class="ss-reading" style="background:#faf7f2;border-left:4px solid #f5d97a;border-radius:0 6px 6px 0;padding:26px 28px;color:#2b1a3d;font-size:15px;line-height:1.75;white-space:pre-line;">{{ $campaignBody }}</div>

                            <hr style="border:none;border-top:1px solid #e5ddef;margin:28px 0;">

                            <div style="font-size:15px;color:#4a3a5e;line-height:1.6;white-space:pre-line;">{!! $emailFooter !!}</div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
