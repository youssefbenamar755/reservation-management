<!doctype html>
<html lang="{{ $language }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $rendered['heading'] }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media screen and (max-width: 480px) {
            .email-outer { padding: 16px 10px !important; }
            .email-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .email-title { font-size: 27px !important; line-height: 34px !important; }
            .email-brand-name { font-size: 15px !important; }
            .email-logo { width: 104px !important; max-width: 104px !important; }
            .email-order-chip { font-size: 10px !important; }
            .email-review-button { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:{{ $c['background'] }};font-family:Arial,Helvetica,sans-serif;color:{{ $c['body'] }};">
    <div aria-hidden="true" style="display:none!important;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $rendered['preheader'] }}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $c['background'] }}" style="width:100%;background-color:{{ $c['background'] }};">
        <tr>
            <td class="email-outer" align="center" style="padding:32px 16px;">
                <!--[if mso]><table role="presentation" width="600" align="center" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e7ebee;border-radius:16px;overflow:hidden;">
                    <tr><td height="4" bgcolor="{{ $c['primary'] }}" style="height:4px;line-height:4px;font-size:1px;background-color:{{ $c['primary'] }};">&nbsp;</td></tr>
                    <tr>
                        <td class="email-pad" style="padding:24px 32px;border-bottom:1px solid #edf0f2;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;">
                                <tr>
                                    @if($logoUrl)
                                        <td width="140" valign="middle" style="width:140px;padding-right:20px;">
                                            <img class="email-logo" src="{{ $logoUrl }}" alt="{{ $vars['brand_name'] }}" width="120" style="display:block;width:120px;max-width:120px;max-height:54px;height:auto;border:0;">
                                        </td>
                                    @endif
                                    <td valign="middle" style="text-align:{{ $logoUrl ? 'right' : 'left' }};">
                                        <p class="email-brand-name" style="margin:0;font-size:17px;line-height:23px;font-weight:bold;letter-spacing:-0.3px;color:{{ $c['body'] }};word-wrap:break-word;overflow-wrap:anywhere;">{{ $vars['brand_name'] }}</p>
                                        @if(trim($vars['service_label']) !== '')
                                            <p style="margin:5px 0 0;font-size:11px;line-height:17px;color:#64717e;word-wrap:break-word;">{{ $vars['service_label'] }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" bgcolor="{{ $c['hero'] }}" style="padding:28px 32px 26px;background-color:{{ $c['hero'] }};">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td valign="top" style="padding:0 12px 18px 0;font-size:10px;line-height:17px;font-weight:bold;letter-spacing:1.1px;text-transform:uppercase;color:{{ $c['heading'] }};">{{ $statusLabel }}</td>
                                    @if(trim($vars['order_number']) !== '')
                                        <td align="right" valign="top" style="padding-bottom:18px;">
                                            <span class="email-order-chip" style="display:inline-block;max-width:100%;box-sizing:border-box;padding:4px 9px;border:1px solid #dfe5e9;border-radius:4px;background-color:#ffffff;font-size:11px;line-height:15px;color:#52616e;word-wrap:break-word;overflow-wrap:anywhere;word-break:break-word;">{{ $language === 'fr' ? 'Commande' : 'Order' }} #{{ $vars['order_number'] }}</span>
                                        </td>
                                    @endif
                                </tr>
                            </table>
                            <h1 class="email-title" style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:32px;line-height:39px;font-weight:bold;letter-spacing:-0.8px;color:{{ $c['heading'] }};word-wrap:break-word;">{{ $rendered['heading'] }}</h1>
                            <p style="margin:13px 0 0;font-size:13px;line-height:21px;color:#52616e;">{{ $attachmentLabel }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding:28px 32px 10px;">
                            @foreach($paragraphs as $paragraph)
                                <p style="margin:0 0 16px;font-size:15px;line-height:25px;color:{{ $c['body'] }};word-wrap:break-word;overflow-wrap:anywhere;">{!! nl2br(e($paragraph), false) !!}</p>
                            @endforeach
                        </td>
                    </tr>
                    @if($names !== [])
                        <tr>
                            <td class="email-pad" style="padding:8px 32px 24px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;border-top:1px solid #e7ebee;border-bottom:1px solid #e7ebee;">
                                    <colgroup><col width="54" style="width:54px;"><col></colgroup>
                                    <tr>
                                        <td colspan="2" style="padding:18px 0 4px;font-size:11px;line-height:17px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#64717e;">{{ $language === 'fr' ? 'Vos fichiers PDF' : 'Your PDF files' }}</td>
                                    </tr>
                                    @foreach($names as $name)
                                        <tr>
                                            <td width="54" valign="middle" style="width:54px;padding:12px 12px 12px 0;{{ $loop->last ? '' : 'border-bottom:1px solid #edf0f2;' }}">
                                                <table role="presentation" width="40" cellspacing="0" cellpadding="0" border="0" style="width:40px;">
                                                    <tr><td align="center" bgcolor="{{ $c['soft_accent'] }}" style="padding:12px 0;border:1px solid {{ $c['soft_accent'] }};border-radius:6px;background-color:{{ $c['soft_accent'] }};font-size:10px;line-height:14px;font-weight:bold;letter-spacing:0.6px;color:{{ $c['badge'] }};">PDF</td></tr>
                                                </table>
                                            </td>
                                            <td valign="middle" style="padding:12px 0;{{ $loop->last ? '' : 'border-bottom:1px solid #edf0f2;' }}">
                                                <p style="margin:0;font-size:14px;line-height:22px;font-weight:bold;color:{{ $c['body'] }};word-wrap:break-word;overflow-wrap:anywhere;word-break:break-word;">{{ $name }}</p>
                                                <p style="margin:3px 0 0;font-size:11px;line-height:17px;color:#64717e;">{{ $language === 'fr' ? 'Pièce jointe à cet e-mail' : 'Attached to this email' }}</p>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif
                    @if($optionalParagraphs !== [])
                        <tr><td class="email-pad" style="padding:0 32px 12px;">
                            @foreach($optionalParagraphs as $paragraph)
                                <p style="margin:0 0 14px;font-size:14px;line-height:23px;color:{{ $c['body'] }};word-wrap:break-word;overflow-wrap:anywhere;">{!! nl2br(e($paragraph), false) !!}</p>
                            @endforeach
                        </td></tr>
                    @endif
                    @if($review)
                        <tr>
                            <td class="email-pad" style="padding:0 32px 28px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f6f7f5" style="width:100%;background-color:#f6f7f5;border:1px solid #e9ece7;border-radius:10px;">
                                    <tr><td style="padding:24px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr><td style="padding:0 0 20px;">
                                                <img class="email-trustpilot-logo" src="https://emailsignature.trustpilot.com/brand/n/1/logo.png" alt="Trustpilot" width="163" height="40" style="display:block;width:163px;height:40px;border:0;font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:40px;font-weight:bold;color:#1b1a1b;">
                                            </td></tr>
                                        </table>
                                        <h2 style="margin:0 0 8px;font-size:21px;line-height:28px;font-weight:bold;letter-spacing:-0.4px;color:#23352a;">{{ $review['heading'] }}</h2>
                                        <p style="margin:0 0 18px;font-size:14px;line-height:23px;color:#526056;word-wrap:break-word;">{{ $review['body'] }}</p>
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr><td bgcolor="{{ $c['accent'] }}" style="background-color:{{ $c['accent'] }};border-radius:6px;mso-padding-alt:13px 19px;">
                                                <a class="email-review-button" data-email-review-button="true" href="{{ $review['url'] }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;background-color:{{ $c['accent'] }};border:1px solid {{ $c['accent'] }};border-radius:6px;padding:13px 19px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;font-weight:bold;text-align:center;text-decoration:none;color:{{ $c['button_text'] }};">{{ $review['button'] }}</a>
                                            </td></tr>
                                        </table>
                                    </td></tr>
                                </table>
                            </td>
                        </tr>
                    @endif
                    @if($footerParagraphs !== [] || $contacts !== [])
                        <tr>
                            <td class="email-pad" style="padding:22px 32px 24px;border-top:1px solid #edf0f2;">
                                @foreach($footerParagraphs as $paragraph)
                                    <p style="margin:0 0 10px;font-size:12px;line-height:20px;color:#64717e;word-wrap:break-word;overflow-wrap:anywhere;">{!! nl2br(e($paragraph), false) !!}</p>
                                @endforeach
                                @foreach($contacts as $contact)
                                    <p style="margin:0 0 3px;font-size:12px;line-height:20px;color:#64717e;word-wrap:break-word;overflow-wrap:anywhere;">
                                        @if($contact['url'])
                                            <a href="{{ $contact['url'] }}" style="color:#52616e;text-decoration:underline;">{{ $contact['text'] }}</a>
                                        @else
                                            {{ $contact['text'] }}
                                        @endif
                                    </p>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
