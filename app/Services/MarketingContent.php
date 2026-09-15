<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class MarketingContent
{
    public function rules(): array
    {
        return [
            'locale' => ['required', Rule::in(['fr', 'en'])],
            'sender_email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n{}]/'],
            'sender_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n{}]/'],
            'reply_to' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n{}]/'],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n{}]/'],
            'preheader' => ['nullable', 'string', 'max:200', 'not_regex:/[{}]/'],
            'headline' => ['required', 'string', 'max:200', 'not_regex:/[{}]/'],
            'body' => ['required', 'string', 'max:15000', 'not_regex:/[{}]/'],
            'signature' => ['required', 'string', 'max:1000', 'not_regex:/[{}]/'],
            'postal_address' => ['nullable', 'string', 'max:500', 'not_regex:/[{}]/'],
            'layout' => ['nullable', Rule::in(['classic', 'studio', 'letter'])],
            'eyebrow' => ['nullable', 'string', 'max:80', 'not_regex:/[{}]/'],
            'highlight_title' => ['nullable', 'string', 'max:120', 'not_regex:/[{}]/'],
            'highlight_body' => ['nullable', 'string', 'max:1500', 'not_regex:/[{}]/'],
            'logo_url' => ['nullable', 'url:https', 'max:1000', 'not_regex:/[{}]/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'cta_text' => ['nullable', 'required_with:cta_url', 'string', 'max:80', 'not_regex:/[{}]/'],
            'cta_url' => ['nullable', 'required_with:cta_text', 'url:https', 'max:1000', 'not_regex:/[{}]/'],
        ];
    }

    /** No user HTML or template expressions are evaluated. Brevo alone fills its unsubscribe URL. */
    public function html(array $content, bool $preview = false, ?int $campaignId = null): string
    {
        $c = array_map(fn ($v) => e((string) ($v ?? '')), $content);
        $unsubscribe = $preview ? '#' : '{{ unsubscribe }}';
        $label = ($content['locale'] ?? 'en') === 'fr' ? 'Se désinscrire' : 'Unsubscribe';
        $why = ($content['locale'] ?? 'en') === 'fr' ? 'Vous recevez cet e-mail car vous êtes abonné(e) aux actualités de' : 'You received this email because you subscribed to updates from';
        $c['postal_address'] ??= '';
        $logo = empty($c['logo_url']) ? '' : '<img src="'.$c['logo_url'].'" alt="'.$c['sender_name'].'" width="160" style="max-width:160px;max-height:80px;object-fit:contain;margin-bottom:24px">';
        $ctaUrl = $content['cta_url'] ?? '';
        if ($campaignId && $ctaUrl !== '') {
            // Preserve destination parameters and fragments; only campaign attribution is appended.
            $fragment = str_contains($ctaUrl, '#') ? '#'.explode('#', $ctaUrl, 2)[1] : '';
            $ctaUrl = explode('#', $ctaUrl, 2)[0];
            $ctaUrl .= (str_contains($ctaUrl, '?') ? '&' : '?').http_build_query(['utm_source' => 'wphub', 'utm_medium' => 'email', 'utm_campaign' => 'campaign_'.$campaignId]).$fragment;
        }
        if (in_array($content['layout'] ?? '', ['studio', 'letter'], true)) {
            $accent = $content['accent_color'];
            $rgb = array_map('hexdec', str_split(substr($accent, 1), 2));
            $tint = '#'.implode('', array_map(fn ($v) => sprintf('%02x', (int) round($v * .08 + 255 * .92)), $rgb));
            $luminance = array_sum(array_map(function ($v, $weight) {
                $v /= 255;

                return ($v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4) * $weight;
            }, $rgb, [.2126, .7152, .0722]));
            $buttonText = $luminance > .179 ? '#101828' : '#ffffff';

            return view('emails.marketing-branded', [
                'content' => $content, 'ctaUrl' => $ctaUrl, 'unsubscribe' => $unsubscribe,
                'label' => $label, 'why' => $why, 'tint' => $tint, 'buttonText' => $buttonText,
            ])->render();
        }
        $cta = $ctaUrl === '' ? '' : '<p style="margin:28px 0"><a href="'.e($ctaUrl).'" style="display:inline-block;background:'.$c['accent_color'].';color:#ffffff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold">'.$c['cta_text'].'</a></p>';

        $html = '<!doctype html><html lang="'.$c['locale'].'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#18212f"><div style="display:none;max-height:0;overflow:hidden">'.($c['preheader'] ?? '').'</div><div style="max-width:600px;margin:24px auto;background:white;color:#18212f;font-family:Arial,sans-serif;border-top:5px solid '.$c['accent_color'].';border-radius:12px;padding:32px">'.$logo.'<h1 style="font-size:26px;line-height:1.3;margin:0 0 24px">'.$c['headline'].'</h1><div style="font-size:16px;line-height:1.7;overflow-wrap:anywhere">'.nl2br($c['body']).'</div>'.$cta.'<div style="margin-top:28px;font-size:14px;line-height:1.6">'.nl2br($c['signature']).'</div><hr style="border:0;border-top:1px solid #e5e7eb;margin:28px 0"><div style="font-size:12px;color:#637083;line-height:1.6">'.$why.' '.$c['sender_name'].'.<br>'.nl2br($c['postal_address']).'<br><a href="'.$unsubscribe.'" style="color:#475569">'.$label.'</a></div></div></body></html>';

        if ($preview) {
            return preg_replace('/^.*?<body[^>]*>|<\/body>.*$/s', '', $html);
        }

        return $html;
    }
}
