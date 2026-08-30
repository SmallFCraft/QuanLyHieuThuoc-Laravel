<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('messages.reminder.mail_subject', ['name' => $productName]) }}</title>
</head>
<body style="margin:0; padding:24px; background:#f9fafb; font-family:Arial, Helvetica, sans-serif; color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#059669; padding:20px 24px; color:#ffffff; font-size:18px; font-weight:bold;">
                Medicare Pharmacy
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 12px;">{{ __('messages.reminder.mail_greeting', ['name' => $userName]) }}</p>
                <p style="margin:0 0 16px;">{{ __('messages.reminder.mail_lead') }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d1fae5; border-radius:8px; background:#ecfdf5;">
                    <tr>
                        <td style="padding:16px;">
                            <p style="margin:0 0 8px; font-size:16px; font-weight:bold; color:#047857;">{{ $productName }}</p>
                            <p style="margin:0 0 4px; font-size:14px;">
                                <strong>{{ __('messages.reminder.mail_dosage_label') }}:</strong> {{ $reminder->dosage_text }}
                            </p>
                            <p style="margin:0; font-size:14px;">
                                <strong>{{ __('messages.reminder.mail_times_label') }}:</strong>
                                {{ __('messages.reminder.times_per_day', ['count' => $reminder->times_per_day]) }}
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:20px 0 8px; font-size:14px; color:#4b5563;">{{ __('messages.reminder.mail_cancel_lead') }}</p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $cancelUrl }}" style="color:#059669; font-weight:bold;">{{ __('messages.reminder.mail_cancel_link') }}</a>
                </p>

                <p style="margin:0; font-size:12px; color:#6b7280;">{{ __('messages.reminder.mail_footer') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
