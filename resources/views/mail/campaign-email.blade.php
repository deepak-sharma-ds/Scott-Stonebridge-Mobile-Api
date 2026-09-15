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

        /* Rich-text (CKEditor) content — header/footer copy is real HTML now,
           not plain text, so it needs its own block/list/table styling. */
        .ss-body p { margin: 0 0 14px; }
        .ss-body p:last-child { margin-bottom: 0; }
        .ss-body ul, .ss-body ol { margin: 0 0 14px; padding-left: 22px; }
        .ss-body blockquote { margin: 0 0 14px; padding: 6px 16px; border-left: 3px solid #d8cbe8; color: #6b5b7d; font-style: italic; }
        .ss-body table { border-collapse: collapse; width: 100%; margin: 0 0 14px; }
        .ss-body table td, .ss-body table th { border: 1px solid #e5ddef; padding: 8px; }
        .ss-body a { color: #6c3fa1; }
        .ss-body h1, .ss-body h2, .ss-body h3, .ss-body h4 { margin: 0 0 12px; color: #2b1a3d; }
        .ss-body hr { border: none; border-top: 1px solid #e5ddef; margin: 20px 0; }

        @media only screen and (max-width: 600px) {
            .ss-wrapper { padding: 16px !important; }
            .ss-body { padding: 24px 20px !important; }
            .ss-reading { padding: 18px 16px !important; font-size: 14px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f1ec;font-family:'Arial','Times New Roman',serif;">
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
                            <div style="font-size:15px;color:#4a3a5e;line-height:1.5;margin:0 0 22px;">{!! $emailContent !!}</div>

                            <div class="ss-reading" style="color:#2b1a3d;font-size:15px;line-height:1.5;">{!! $campaignBody !!}</div>

                            <hr style="border:none;border-top:1px solid #e5ddef;margin:28px 0;">

                            <div style="font-size:15px;color:#4a3a5e;line-height:1.5;">{!! $emailFooter !!}</div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
