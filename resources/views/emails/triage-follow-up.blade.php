<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('messages.reminder.follow_up_subject') }}</title>
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
                <p style="margin:0 0 12px;">{{ __('messages.reminder.follow_up_greeting', ['name' => $userName]) }}</p>
                <p style="margin:0 0 20px; font-size:16px;">{{ __('messages.reminder.follow_up_question') }}</p>

                @if (!empty($session->advice_given))
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d1fae5; border-radius:8px; background:#ecfdf5; margin-bottom:20px;">
                        <tr>
                            <td style="padding:16px; font-size:14px;">
                                <p style="margin:0 0 6px; font-weight:bold; color:#047857;">{{ __('messages.reminder.follow_up_advice_label') }}</p>
                                <p style="margin:0; color:#374151;">{{ $session->advice_given }}</p>
                            </td>
                        </tr>
                    </table>
                @endif

                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                    <tr>
                        <td style="padding-right:12px;">
                            <a href="{{ $improvedUrl }}"
                               style="display:inline-block; padding:12px 24px; background:#059669; color:#ffffff; text-decoration:none; border-radius:8px; font-weight:bold;">
                                {{ __('messages.reminder.follow_up_btn_improved') }}
                            </a>
                        </td>
                        <td>
                            <a href="{{ $notImprovedUrl }}"
                               style="display:inline-block; padding:12px 24px; background:#ffffff; color:#b45309; text-decoration:none; border:2px solid #b45309; border-radius:8px; font-weight:bold;">
                                {{ __('messages.reminder.follow_up_btn_not_improved') }}
                            </a>
                        </td>
                    </tr>
                </table>

                @if (!empty($session->safety_net))
                    <p style="margin:0 0 6px; font-size:14px; font-weight:bold; color:#b45309;">{{ __('messages.health.safety_net_title') }}</p>
                    <p style="margin:0 0 20px; font-size:14px; color:#374151;">{{ $session->safety_net }}</p>
                @endif

                <p style="margin:0; font-size:12px; color:#6b7280;">{{ __('messages.reminder.follow_up_footer') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
