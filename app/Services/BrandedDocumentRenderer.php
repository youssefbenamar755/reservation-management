<?php

namespace App\Services;

use App\Models\WcOrder;
use App\Models\WebsiteDocumentProfile;
use App\Support\TrustpilotReviewLink;
use Illuminate\Validation\ValidationException;

class BrandedDocumentRenderer
{
    public const PLACEHOLDERS = ['brand_name', 'customer_first_name', 'order_number', 'document_count', 'document_list', 'support_email', 'website_url', 'service_label'];

    public static function defaults(): array
    {
        return [
            'acknowledgement' => [
                'fr' => ['subject' => '{{brand_name}} — Commande {{order_number}} reçue', 'heading' => 'Votre commande a été reçue', 'preheader' => 'Vos documents vous seront envoyés dans un e-mail séparé.',
                    'body' => "Bonjour {{customer_first_name}},\n\nCet e-mail confirme la réception de votre commande {{order_number}}. Il ne contient pas encore vos documents.\n\nAprès confirmation du paiement et préparation, vous recevrez un e-mail intitulé « {{brand_name}} — Vos documents PDF | Commande {{order_number}} ».\n\nUne question ? Répondez directement à cet e-mail.", 'optional_block' => ''],
                'en' => ['subject' => '{{brand_name}} — Order {{order_number}} received', 'heading' => 'We received your order', 'preheader' => 'Your documents will arrive in a separate email.',
                    'body' => "Hello {{customer_first_name}},\n\nThis email confirms receipt of order {{order_number}}. Your documents are not attached yet.\n\nAfter payment confirmation and preparation, you will receive an email titled “{{brand_name}} — Your PDF documents | Order {{order_number}}”.\n\nQuestions? Reply directly to this email.", 'optional_block' => ''],
            ],
            'delivery' => [
                'fr' => ['subject' => '{{brand_name}} — Vos documents PDF | Commande {{order_number}}', 'heading' => 'Vos documents sont prêts', 'preheader' => '{{document_count}} fichier(s) PDF pour votre commande {{order_number}}.',
                    'body' => "Bonjour {{customer_first_name}},\n\nVos documents de {{service_label}} sont prêts et joints à cet e-mail.\n\nOuvrez et enregistrez les fichiers PDF, puis vérifiez les noms, les dates et les informations de réservation.\n\nUne information à corriger ? Répondez directement à cet e-mail en précisant la modification souhaitée.\n\nMerci pour votre confiance,\nL’équipe {{brand_name}}", 'optional_block' => ''],
                'en' => ['subject' => '{{brand_name}} — Your PDF documents | Order {{order_number}}', 'heading' => 'Your documents are ready', 'preheader' => '{{document_count}} PDF file(s) for order {{order_number}}.',
                    'body' => "Hello {{customer_first_name}},\n\nYour {{service_label}} documents are ready and attached to this email.\n\nOpen and save the PDF files, then check the names, dates and reservation information.\n\nNeed a correction? Reply directly to this email and explain the change required.\n\nThank you,\nThe {{brand_name}} team", 'optional_block' => ''],
            ],
            'correction' => [
                'fr' => ['subject' => '{{brand_name}} — Documents corrigés | Commande {{order_number}}', 'heading' => 'Vos documents corrigés', 'preheader' => 'Utilisez les fichiers PDF joints à cet e-mail.',
                    'body' => "Bonjour {{customer_first_name}},\n\nVoici les documents de {{service_label}} corrigés pour votre commande {{order_number}}. Utilisez ces fichiers à la place de la version précédente.\n\nEnregistrez les PDF et vérifiez les informations. Si une correction reste nécessaire, répondez à cet e-mail.\n\nL’équipe {{brand_name}}", 'optional_block' => ''],
                'en' => ['subject' => '{{brand_name}} — Corrected documents | Order {{order_number}}', 'heading' => 'Your corrected documents', 'preheader' => 'Use the PDF files attached to this email.',
                    'body' => "Hello {{customer_first_name}},\n\nHere are your corrected {{service_label}} documents for order {{order_number}}. Use these files in place of the previous version.\n\nSave the PDFs and check the information. Reply to this email if another correction is needed.\n\nThe {{brand_name}} team", 'optional_block' => ''],
            ],
        ];
    }

    /** Literal substitutions only: neither Blade nor user HTML is executed. */
    public function validateTemplates(array $templates): void
    {
        if (array_diff(array_keys($templates), array_keys(self::defaults()))) {
            throw ValidationException::withMessages(['templates' => 'Use only acknowledgement, delivery and correction templates.']);
        }
        foreach (self::defaults() as $kind => $languages) {
            if (! is_array($templates[$kind] ?? null) || array_diff(array_keys($templates[$kind]), ['fr', 'en'])) {
                throw ValidationException::withMessages(["templates.$kind" => 'Use French and English template variants.']);
            }
            foreach ($languages as $language => $unused) {
                if (! is_array($templates[$kind][$language] ?? null) || array_diff(array_keys($templates[$kind][$language]), ['subject', 'heading', 'body', 'preheader', 'optional_block'])) {
                    throw ValidationException::withMessages(["templates.$kind.$language" => 'Use only the documented template fields.']);
                }
                foreach (['subject', 'heading', 'body', 'preheader', 'optional_block'] as $field) {
                    $value = $templates[$kind][$language][$field] ?? '';
                    $key = "templates.$kind.$language.$field";
                    if (! is_string($value) || (in_array($field, ['subject', 'heading', 'body']) && trim($value) === '') || mb_strlen($value) > ($field === 'body' || $field === 'optional_block' ? 10000 : 250)) {
                        throw ValidationException::withMessages([$key => 'Enter valid template text within the allowed length.']);
                    }
                    preg_match_all('/\{\{(.*?)\}\}/s', $value, $matches);
                    if (array_diff($matches[1], self::PLACEHOLDERS) || str_contains(preg_replace('/\{\{[a-z_]+\}\}/', '', $value), '{{') || str_contains(preg_replace('/\{\{[a-z_]+\}\}/', '', $value), '}}')) {
                        throw ValidationException::withMessages([$key => 'Use only the documented {{placeholder}} names.']);
                    }
                    if ($field === 'subject' && (preg_match('/[\r\n]/', $value) || ! str_contains($value, '{{brand_name}}') || ! str_contains($value, '{{order_number}}'))) {
                        throw ValidationException::withMessages([$key => 'The subject must contain {{brand_name}} and {{order_number}} and use one line.']);
                    }
                }
            }
        }
    }

    public function render(WebsiteDocumentProfile $profile, WcOrder|array $order, array $attachments, string $template = 'delivery', ?string $language = null): array
    {
        if ($order instanceof WcOrder && (int) $order->website_id !== (int) $profile->website_id) {
            throw ValidationException::withMessages(['website' => 'The order and brand must belong to the same website.']);
        }
        if (! in_array($template, array_keys(self::defaults()), true)) {
            throw ValidationException::withMessages(['template' => 'Select a supported document template.']);
        }
        $values = $order instanceof WcOrder ? $this->orderValues($order) : $order;
        $recordedLanguage = $this->locale($values['language'] ?? null);
        $language = $order instanceof WcOrder
            ? ($recordedLanguage ?? $this->locale($profile->default_language) ?? 'fr')
            : ($this->locale($language) ?? $recordedLanguage ?? $this->locale($profile->default_language) ?? 'fr');
        $templates = $profile->templates ?? self::defaults();
        $this->validateTemplates($templates);
        $copy = array_map(fn ($value) => (string) ($value ?? ''), array_replace(['preheader' => '', 'optional_block' => ''], $templates[$template][$language]));
        $names = $template === 'acknowledgement' ? [] : array_map(fn ($file) => self::filename((string) ($file['name'] ?? $file['filename'] ?? 'document.pdf')), $attachments);
        $vars = [
            'brand_name' => $profile->brand_name ?: ($language === 'fr' ? 'Marque à configurer' : 'Brand configuration required'),
            'customer_first_name' => (string) ($values['customer_first_name'] ?? ''), 'order_number' => (string) ($values['order_number'] ?? ''),
            'document_count' => (string) count($names), 'document_list' => implode("\n", $names),
            'support_email' => (string) $profile->support_email, 'website_url' => (string) $profile->website_url,
            'service_label' => (string) (($values['service_label'] ?? null) ?: $profile->service_label),
        ];
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{{'.$key.'}}'] = $value;
        }
        $fill = fn (string $value) => strtr($value, $map);
        $rendered = array_map($fill, $copy);
        $subject = mb_substr(preg_replace('/[\r\n\x00-\x1f\x7f]/', ' ', $rendered['subject']), 0, 250);
        $count = count($names);
        $attachmentLabel = $language === 'fr'
            ? $count.($count === 1 ? ' fichier PDF joint' : ' fichiers PDF joints')
            : $count.($count === 1 ? ' PDF attachment' : ' PDF attachments');
        $statusLabel = $language === 'fr'
            ? ($template === 'acknowledgement' ? 'Commande reçue' : ($template === 'correction' ? 'Version corrigée' : 'Documents prêts'))
            : ($template === 'acknowledgement' ? 'Order received' : ($template === 'correction' ? 'Updated documents' : 'Documents ready'));
        if ($template === 'acknowledgement') {
            $attachmentLabel = $language === 'fr' ? 'Documents envoyés séparément après préparation' : 'Documents sent separately after preparation';
        } elseif ($names === []) {
            // A zero-file draft is useful in settings, but must never look sendable.
            $attachmentLabel = $language === 'fr' ? 'Aucun PDF sélectionné — aperçu uniquement' : 'No PDF selected — preview only';
            $rendered['body'] = $language === 'fr' ? 'Sélectionnez des fichiers PDF pour prévisualiser le message de livraison.' : 'Select PDF files to preview the document delivery message.';
            $rendered['preheader'] = $attachmentLabel;
            $rendered['heading'] = $language === 'fr' ? 'Aperçu de vos documents' : 'Your document preview';
            $statusLabel = $language === 'fr' ? 'Aperçu uniquement' : 'Preview only';
        }
        $footer = $fill((string) $profile->footer);
        $color = fn (?string $value, string $fallback) => preg_match('/^#[0-9a-f]{6}$/i', $value ?? '') ? $value : $fallback;
        $c = ['primary' => $color($profile->primary_color, '#334155'), 'accent' => $color($profile->accent_color, '#334155'), 'background' => $color($profile->background_color, '#f3f4f6'), 'text' => $color($profile->text_color, '#18212f')];
        $c['hero'] = self::tint($c['primary'], 0.96);
        $c['soft_accent'] = self::tint($c['accent'], 0.92);
        $c['heading'] = self::contrastRatio($c['primary'], $c['hero']) >= 4.5 ? $c['primary'] : '#172b35';
        $c['body'] = self::contrastRatio($c['text'], '#ffffff') >= 4.5 ? $c['text'] : '#263747';
        $c['badge'] = self::contrastRatio($c['accent'], $c['soft_accent']) >= 4.5 ? $c['accent'] : '#263747';
        $c['button_text'] = self::contrastRatio($c['accent'], '#ffffff') >= self::contrastRatio($c['accent'], '#000000') ? '#ffffff' : '#000000';
        // Remote assets are never fetched by WP Hub. Only safe public HTTPS URLs are embedded.
        $logoUrl = self::safePublicUrl($profile->logo_url) ? $profile->logo_url : null;
        $contacts = self::footerContacts($profile, $footer);
        $paragraphs = self::paragraphs($rendered['body']);
        $optionalParagraphs = self::paragraphs($rendered['optional_block'] ?? '');
        $footerParagraphs = self::paragraphs($footer);
        // Invite feedback after document delivery, using only this website's saved
        // profile. The rendered HTML/text are frozen in the reviewed send snapshot.
        $reviewUrl = $template !== 'acknowledgement' && $names !== [] ? TrustpilotReviewLink::actionUrl($profile) : null;
        $review = null;
        $reviewText = '';
        if ($reviewUrl) {
            $reviewHeading = $language === 'fr' ? 'Votre expérience compte' : 'Your experience matters';
            $reviewBody = $language === 'fr'
                ? 'Partagez votre expérience avec '.$vars['brand_name'].' sur Trustpilot. Votre avis aide les autres voyageurs.'
                : 'Share your experience with '.$vars['brand_name'].' on Trustpilot. Your feedback helps other travellers.';
            $reviewButton = $language === 'fr' ? 'Donner mon avis sur Trustpilot' : 'Review us on Trustpilot';
            $review = ['heading' => $reviewHeading, 'body' => $reviewBody, 'button' => $reviewButton, 'url' => $reviewUrl];
            $reviewText = $reviewHeading."\n".$reviewBody."\n".$reviewButton.': '.$reviewUrl;
        }
        $html = view('emails.branded-document', compact('language', 'template', 'vars', 'rendered', 'names', 'attachmentLabel', 'statusLabel', 'c', 'logoUrl', 'paragraphs', 'optionalParagraphs', 'footerParagraphs', 'contacts', 'review'))->render();
        $text = implode("\n\n", array_filter([$vars['brand_name'], $rendered['heading'], ($language === 'fr' ? 'Commande ' : 'Order ').$vars['order_number'], $attachmentLabel, implode("\n", $names), $rendered['body'], $rendered['optional_block'] ?? '', $reviewText, $footer, implode("\n", array_column($contacts, 'text'))]));

        return compact('subject', 'html', 'text', 'language');
    }

    public static function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f<>"|:*?]/u', '', $name);

        return mb_substr(trim($name) ?: 'document.pdf', 0, 150);
    }

    private static function paragraphs(string $text): array
    {
        return array_values(array_filter(preg_split('/\R[\h\r\n]*\R/u', trim($text)) ?: [], fn ($paragraph) => trim($paragraph) !== ''));
    }

    /** Blend with white; the original brand accent remains unchanged on the CTA. */
    private static function tint(string $color, float $white): string
    {
        $channels = array_map(fn ($offset) => (int) round(hexdec(substr($color, $offset, 2)) * (1 - $white) + 255 * $white), [1, 3, 5]);

        return sprintf('#%02x%02x%02x', ...$channels);
    }

    /** Keep custom footer copy verbatim and append only contact details it lacks. */
    private static function footerContacts(WebsiteDocumentProfile $profile, string $footer): array
    {
        $contacts = [];
        $normalizedFooter = mb_strtolower($footer);
        $email = trim((string) $profile->support_email);
        if ($email !== '' && ! str_contains($normalizedFooter, mb_strtolower($email))) {
            $emailUrl = null;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! preg_match('/[\r\n]/', $email)) {
                $separator = strrpos($email, '@');
                $emailUrl = 'mailto:'.rawurlencode(substr($email, 0, $separator)).'@'.rawurlencode(substr($email, $separator + 1));
            }
            $contacts[] = ['text' => $email, 'url' => $emailUrl];
        }
        $phone = trim((string) $profile->support_phone);
        $phoneDigits = preg_replace('/\D/', '', $phone);
        preg_match_all('/\+?\d[\d\h().-]{4,}\d/u', $footer, $matches);
        $footerPhones = array_map(fn ($match) => preg_replace('/\D/', '', $match), $matches[0]);
        if ($phone !== '' && ! str_contains($normalizedFooter, mb_strtolower($phone)) && ($phoneDigits === '' || ! in_array($phoneDigits, $footerPhones, true))) {
            $contacts[] = ['text' => $phone, 'url' => null];
        }
        $website = trim((string) $profile->website_url);
        $host = preg_replace('/^www\./i', '', (string) parse_url($website, PHP_URL_HOST));
        if (self::safePublicUrl($website) && ! str_contains($normalizedFooter, mb_strtolower($website))
            && ($host === '' || ! preg_match('/(?<![a-z0-9.@-])(?:www\.)?'.preg_quote(mb_strtolower($host), '/').'(?![a-z0-9.-])/u', $normalizedFooter))) {
            $contacts[] = ['text' => $website, 'url' => $website];
        }

        return $contacts;
    }

    /** WCAG relative luminance; decorative accent colours need not be used for text. */
    public static function contrastRatio(string $first, string $second): float
    {
        $luminance = static function (string $color): float {
            $channels = array_map(static function (int $offset) use ($color): float {
                $channel = hexdec(substr($color, $offset, 2)) / 255;

                return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            }, [1, 3, 5]);

            return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
        };
        $a = $luminance($first);
        $b = $luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function safePublicUrl(?string $url): bool
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== 'localhost' && ! str_ends_with($host, '.local') && str_contains($host, '.')
            && (! filter_var($host, FILTER_VALIDATE_IP) || (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }

    private function locale(mixed $value): ?string
    {
        $locale = is_string($value) ? strtolower(substr($value, 0, 2)) : '';

        return in_array($locale, ['fr', 'en'], true) ? $locale : null;
    }

    private function orderValues(WcOrder $order): array
    {
        $payload = $order->payload ?? [];
        $language = $this->locale($payload['language'] ?? null) ?? $this->locale($payload['locale'] ?? null);
        foreach (['_wphub_language', '_order_locale', '_order_language', 'wpml_language', '_wpml_language', 'pll_language'] as $key) {
            foreach ($payload['meta_data'] ?? [] as $meta) {
                if (($meta['key'] ?? null) === $key) {
                    $language ??= $this->locale($meta['value'] ?? null);
                }
            }
        }

        return ['order_number' => (string) ($payload['number'] ?? $order->wp_order_id), 'customer_first_name' => (string) ($payload['billing']['first_name'] ?? ''), 'language' => $language];
    }
}
