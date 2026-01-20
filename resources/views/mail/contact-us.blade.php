<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Contact Us Enquiry</title>
</head>

<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:30px 0;">
        <tr>
            <td align="center">

                <!-- Email Container -->
                <table width="600" cellpadding="0" cellspacing="0"
                    style="background-color:#ffffff; border-radius:6px; overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#1f2937; padding:20px; text-align:center;">
                            <h1 style="margin:0; font-size:20px; color:#ffffff;">
                                Contact Us Enquiry
                            </h1>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:25px; color:#333333; font-size:14px; line-height:1.6;">
                            <p style="margin-top:0;">Hi Scott,</p>

                            <p>
                                You have received a new <strong>Contact Us</strong> enquiry with the following details:
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="margin:15px 0; border-collapse:collapse;">
                                <tr>
                                    <td
                                        style="padding:8px; background-color:#f9fafb; border:1px solid #e5e7eb; width:120px;">
                                        <strong>Name</strong>
                                    </td>
                                    <td style="padding:8px; border:1px solid #e5e7eb;">
                                        {{ $patient_name }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px; background-color:#f9fafb; border:1px solid #e5e7eb;">
                                        <strong>Email</strong>
                                    </td>
                                    <td style="padding:8px; border:1px solid #e5e7eb;">
                                        {{ $email }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px; background-color:#f9fafb; border:1px solid #e5e7eb;">
                                        <strong>Phone</strong>
                                    </td>
                                    <td style="padding:8px; border:1px solid #e5e7eb;">
                                        {{ $phone }}
                                    </td>
                                </tr>
                            </table>

                            <p style="margin-bottom:5px;">
                                <strong>Message:</strong>
                            </p>
                            <div
                                style="padding:12px; background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:4px;">
                                {{ $custom_text }}
                            </div>

                            <p style="margin-top:20px;">
                                Please review the message and respond to the customer at the earliest.
                            </p>

                            <p style="margin-bottom:0;">
                                Thank you,<br>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td
                            style="background-color:#f9fafb; padding:15px; text-align:center; font-size:12px; color:#6b7280;">
                            This email was generated automatically from the website contact form.<br>
                            © {{ date('Y') }} Scott Stonebridge Physic Medium. All rights reserved.
                        </td>
                    </tr>

                </table>
                <!-- End Container -->

            </td>
        </tr>
    </table>

</body>

</html>
