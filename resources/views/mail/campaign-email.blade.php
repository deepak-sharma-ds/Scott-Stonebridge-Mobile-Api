<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $delivery->campaignProduct?->product_title ?? 'A Special Message' }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f1ec; }
        img { max-width: 100%; }
        @media only screen and (max-width: 600px) {
            .ss-wrapper { padding: 16px !important; }
            .ss-hero { padding: 28px 20px !important; }
            .ss-hero-title { font-size: 22px !important; }
            .ss-body { padding: 24px 20px !important; }
            .ss-reading { padding: 18px 16px !important; font-size: 14px !important; }
            .ss-footer { padding: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f1ec;font-family:'Georgia','Times New Roman',serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        {{ $customerName }}, your reading from Scott Stonebridge has arrived.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ec;">
        <tr>
            <td align="center" class="ss-wrapper" style="padding:24px 12px;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 18px rgba(43,26,61,0.12);">

                    {{-- Hero --}}
                    <tr>
                        <td class="ss-hero" style="background:linear-gradient(135deg,#3a1b5e 0%,#6b3fa0 100%);padding:40px 32px;text-align:center;">
                            @if(config('Site.logo'))
                                <img src="{{ asset('storage/configuration-images/'.config('Site.logo')) }}" alt="Scott Stonebridge" style="max-height:64px;margin-bottom:14px;">
                            @endif
                            <div style="color:#e7d6ff;font-size:11px;letter-spacing:3px;text-transform:uppercase;margin-bottom:10px;">
                                Your Personal Reading
                            </div>
                            <div class="ss-hero-title" style="color:#ffffff;font-family:'Georgia',serif;font-size:26px;line-height:1.35;font-weight:normal;">
                                {{ $delivery->campaignProduct?->product_title ?? 'A Special Message from Scott Stonebridge' }}
                            </div>
                            <div style="height:3px;width:64px;background:#f5d97a;margin:18px auto 0;border-radius:2px;"></div>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="ss-body" style="padding:36px 32px;">
                            <p style="font-size:16px;color:#2b1a3d;margin:0 0 18px;">
                                Dear {{ $customerName }},
                            </p>

                            <p style="font-size:15px;color:#4a3a5e;line-height:1.6;margin:0 0 22px;">
                                Thank you for letting me connect with your energy. I have sat in quiet reflection with your reading, and I am pleased to share what came through.
                            </p>

                            <div class="ss-reading" style="background:#faf7f2;border-left:4px solid #f5d97a;border-radius:0 6px 6px 0;padding:26px 28px;color:#2b1a3d;font-size:15px;line-height:1.75;white-space:pre-line;">{{ $campaignBody }}</div>

                            <p style="font-size:15px;color:#4a3a5e;line-height:1.6;margin:28px 0 8px;">
                                With love and light,
                            </p>
                            <p style="font-family:'Georgia',serif;font-size:18px;color:#3a1b5e;margin:0 0 4px;">
                                Scott Stonebridge
                            </p>
                            <p style="font-size:12px;color:#8a7a9e;letter-spacing:1px;text-transform:uppercase;margin:0;">
                                Award-Winning UK Psychic Medium
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="ss-footer" style="background:#2b1a3d;color:#d6c8eb;padding:26px 32px;font-size:12px;line-height:1.6;text-align:center;">
                            <div style="margin-bottom:8px;">
                                <a href="https://scottstonebridge.com/" style="color:#f5d97a;text-decoration:none;">scottstonebridge.com</a>
                            </div>
                            <div style="color:#a290bf;margin-bottom:8px;">
                                This reading is provided for entertainment and spiritual guidance purposes only.
                            </div>
                            <div style="color:#7c6b96;">
                                &copy; {{ date('Y') }} Scott Stonebridge. All rights reserved.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
