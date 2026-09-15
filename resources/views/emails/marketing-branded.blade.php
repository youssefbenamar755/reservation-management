<!doctype html>
<html lang="{{ $content['locale'] }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $content['subject'] }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table, td { mso-table-lspace:0; mso-table-rspace:0; }
        img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
        @media only screen and (max-width:620px) {
            .outer-pad { padding:16px 8px !important; }
            .section-pad { padding-left:24px !important; padding-right:24px !important; }
            .email-heading { font-size:30px !important; line-height:36px !important; }
            .email-logo { width:180px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#f2f4f7;color:#182230;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;font-size:1px;color:#f2f4f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $content['preheader'] ?? '' }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f2f4f7">
    <tr><td align="center" class="outer-pad" style="padding:32px 12px;">
        <!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
            <tr><td height="5" bgcolor="{{ $content['accent_color'] }}" style="height:5px;font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td class="section-pad" style="padding:30px 40px;border-bottom:1px solid #eaecf0;">
                @if(!empty($content['logo_url']))
                    <img class="email-logo" src="{{ $content['logo_url'] }}" alt="{{ $content['sender_name'] }}" width="210" style="display:block;width:210px;max-width:100%;height:auto;">
                @else
                    <p style="margin:0;font-size:20px;line-height:28px;font-weight:bold;color:#101828;">{{ $content['sender_name'] }}</p>
                @endif
            </td></tr>
            <tr><td class="section-pad" bgcolor="{{ $content['layout'] === 'studio' ? $tint : '#ffffff' }}" style="padding:36px 40px 32px;background-color:{{ $content['layout'] === 'studio' ? $tint : '#ffffff' }};">
                @if(!empty($content['eyebrow']))
                    <p style="margin:0 0 16px;font-size:11px;line-height:18px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#475467;">{{ $content['eyebrow'] }}</p>
                @endif
                <h1 class="email-heading" style="margin:0;font-size:36px;line-height:43px;letter-spacing:-1px;font-weight:bold;color:#101828;overflow-wrap:break-word;">{{ $content['headline'] }}</h1>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;"><tr><td width="48" height="4" bgcolor="{{ $content['accent_color'] }}" style="width:48px;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr></table>
            </td></tr>
            <tr><td class="section-pad" style="padding:28px 40px 36px;font-size:16px;line-height:27px;color:#344054;overflow-wrap:break-word;">
                @foreach(preg_split('/\R\s*\R/u', trim($content['body'])) as $paragraph)
                    <p style="margin:0 0 18px;font-size:16px;line-height:27px;color:#344054;">{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
                @if(!empty($content['highlight_body']))
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f8fafc" style="margin:26px 0;background-color:#f8fafc;border:1px solid #eaecf0;border-radius:10px;">
                        <tr><td style="padding:22px 24px;">
                            @if(!empty($content['highlight_title']))
                                <h2 style="margin:0 0 14px;font-size:16px;line-height:24px;color:#101828;">{{ $content['highlight_title'] }}</h2>
                            @endif
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                @foreach(array_values(array_filter(preg_split('/\R/u', $content['highlight_body']), fn ($line) => trim($line) !== '')) as $index => $line)
                                    <tr><td width="28" valign="top" style="width:28px;padding:5px 0;font-size:12px;font-weight:bold;line-height:23px;color:#667085;">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</td><td style="padding:5px 0;font-size:14px;line-height:23px;color:#344054;">{{ $line }}</td></tr>
                                @endforeach
                            </table>
                        </td></tr>
                    </table>
                @endif
                @if($ctaUrl !== '')
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;max-width:100%;"><tr><td align="center" bgcolor="{{ $content['accent_color'] }}" style="background-color:{{ $content['accent_color'] }};border-radius:8px;mso-padding-alt:16px 26px;">
                        <a href="{{ $ctaUrl }}" target="_blank" style="display:inline-block;border:1px solid {{ $content['accent_color'] }};border-radius:8px;padding:15px 25px;color:{{ $buttonText }};font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;font-weight:bold;text-decoration:none;text-align:center;mso-padding-alt:0;">{{ $content['cta_text'] }}</a>
                    </td></tr></table>
                @endif
                <p style="margin:28px 0 0;padding-top:24px;border-top:1px solid #eaecf0;font-size:14px;line-height:23px;color:#475467;">{!! nl2br(e($content['signature'])) !!}</p>
                <p style="margin:8px 0 0;font-size:13px;line-height:22px;"><a href="mailto:{{ $content['reply_to'] }}" style="color:#344054;text-decoration:underline;overflow-wrap:anywhere;">{{ $content['reply_to'] }}</a></p>
            </td></tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;">
            <tr><td align="center" style="padding:24px 16px;font-size:11px;line-height:19px;color:#667085;">
                <p style="margin:0 0 8px;font-weight:bold;color:#475467;">{{ $content['sender_name'] }}</p>
                <p style="margin:0 0 10px;">{{ $why }} {{ $content['sender_name'] }}.</p>
                @if(!empty($content['postal_address']))
                    <p style="margin:0 0 10px;">{!! nl2br(e($content['postal_address'])) !!}</p>
                @endif
                <a href="{{ $unsubscribe }}" style="color:#475467;text-decoration:underline;">{{ $label }}</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
