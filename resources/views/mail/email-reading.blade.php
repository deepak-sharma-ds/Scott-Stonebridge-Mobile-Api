<!DOCTYPE html>
<html lang="en" style="font-family: 'Georgia', 'Times New Roman', serif; background-color: #f4f1ec; padding: 20px;">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $product?->name ?? 'Your Personal Reading' }}</title>
</head>
<body style="max-width: 640px; margin: auto; background: #ffffff; padding: 0; border-radius: 6px; color: #2b1a3d; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">

    <div style="background: linear-gradient(135deg, #3a1b5e 0%, #6b3fa0 100%); padding: 30px 24px; text-align: center; border-radius: 6px 6px 0 0;">
        @if(config('Site.logo'))
            <img src="{{ asset('storage/configuration-images/'.config('Site.logo')) }}" alt="Scott Stonebridge" style="max-height: 70px; margin-bottom: 8px;">
        @endif
        <div style="color: #f5d97a; font-family: 'Georgia', serif; font-size: 22px; letter-spacing: 1px; margin-top: 6px;">Scott Stonebridge</div>
        <div style="color: #e7d6ff; font-size: 12px; letter-spacing: 3px; text-transform: uppercase; margin-top: 4px;">Psychic Medium</div>
    </div>

    <div style="padding: 32px 28px;">

        <h1 style="color: #3a1b5e; font-family: 'Georgia', serif; font-size: 22px; margin: 0 0 6px 0; font-weight: normal;">
            {{ $product?->name ?? 'Your Personal Reading' }}
        </h1>
        <div style="height: 2px; width: 60px; background: #f5d97a; margin: 10px 0 24px 0;"></div>

        <p style="font-size: 16px; color: #2b1a3d; margin: 0 0 18px 0;">
            Dear {{ $customerName }},
        </p>

        <p style="font-size: 15px; color: #4a3a5e; margin: 0 0 22px 0; line-height: 1.6;">
            Thank you for trusting me with your reading. I have sat in quiet meditation with the questions you shared, and I am pleased to bring you the messages I received.
        </p>

        <div style="background: #faf7f2; border-left: 4px solid #f5d97a; padding: 22px 24px; margin: 0 0 28px 0; color: #2b1a3d; font-size: 15px; line-height: 1.75; white-space: pre-line;">{{ $readingBody }}</div>

        <p style="font-size: 15px; color: #4a3a5e; margin: 0 0 8px 0; line-height: 1.6;">
            Sending you warm blessings and light on your journey.
        </p>

        <p style="font-family: 'Georgia', serif; font-size: 18px; color: #3a1b5e; margin: 22px 0 4px 0;">
            Scott Stonebridge
        </p>
        <p style="font-size: 12px; color: #8a7a9e; letter-spacing: 1px; text-transform: uppercase; margin: 0;">
            Award-Winning UK Psychic Medium
        </p>

    </div>

    <div style="background: #2b1a3d; color: #d6c8eb; padding: 22px 28px; font-size: 12px; line-height: 1.6; border-radius: 0 0 6px 6px; text-align: center;">
        <div style="margin-bottom: 6px;">
            <a href="https://scottstonebridge.com/" style="color: #f5d97a; text-decoration: none;">scottstonebridge.com</a>
        </div>
        <div style="color: #a290bf;">
            This reading is provided for entertainment and spiritual guidance purposes only.
        </div>
    </div>

</body>
</html>
