<?php

namespace App\Services\Mail;

/** Table layout + inline CSS work without JavaScript, web fonts, images or external CSS. */
class EmailTemplate
{
    public function render(string $title, string $intro, array $details = [], ?string $url = null, string $action = 'Open CarLink', string $note = ''): array
    {
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($url !== null) {
            $base = parse_url(config('app.frontend_url'));
            $target = parse_url($url);
            if (
                !$target
                || !in_array($target['scheme'] ?? '', ['https', 'http'], true)
                || isset($target['user'])
                || isset($target['pass'])
                || ($target['host'] ?? '') !== ($base['host'] ?? '')
                || ($target['scheme'] ?? '') !== ($base['scheme'] ?? '')
                || ($target['port'] ?? null) !== ($base['port'] ?? null)
            ) {
                throw new \InvalidArgumentException('Email links must use the configured frontend origin.');
            }
        }

        $titleLower = mb_strtolower($title);
        $actionLower = mb_strtolower($action);
        $isMagicLink = str_contains($titleLower, 'sign-in link')
            || str_contains($titleLower, 'sign in')
            || str_contains($actionLower, 'sign in')
            || str_contains($titleLower, 'confirm your organization');

        $category = $this->resolveCategory($titleLower, $isMagicLink);

        $rows = '';
        $text = $title."\n\n".$intro."\n";
        $totalDetails = count($details);
        if ($totalDetails > 0) {
            $text .= "\n";
        }

        $index = 0;
        foreach ($details as $label => $value) {
            $rawVal = $value === null ? 'Not available' : (string) $value;
            $text .= $label.': '.$rawVal."\n";

            $styledVal = $this->formatDetailValue($value, $rawVal, $escape);
            $borderBottom = $index < $totalDetails - 1 ? 'border-bottom:1px solid #f0f0f0;' : '';

            $rows .= '<tr>'
                .'<td class="desc-label" style="width:38%;padding:11px 14px;background:#fafafa;'.$borderBottom.'border-right:1px solid #f0f0f0;color:#595959;font-size:13px;font-weight:500;line-height:1.5;vertical-align:top;word-break:break-word;overflow-wrap:anywhere">'.$escape($label).'</td>'
                .'<td class="desc-val" style="width:62%;padding:11px 14px;background:#ffffff;'.$borderBottom.'color:#1f2328;font-size:13px;line-height:1.55;vertical-align:top;word-break:break-word;overflow-wrap:anywhere">'.$styledVal.'</td>'
                .'</tr>';
            $index++;
        }

        $detailsHtml = $rows !== ''
            ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;border:1px solid #f0f0f0;border-radius:8px;overflow:hidden;margin:0">'.$rows.'</table>'
            : '';

        $button = '';
        if ($url) {
            $safeUrl = $escape($url);
            $safeAction = $escape($action);
            if ($isMagicLink) {
                $button = '<div class="magic-panel" style="margin:24px 0 0;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">'
                    .'<div style="margin:0 0 14px">'
                    .'<span style="display:inline-block;padding:2px 8px;background:#e6f4ff;border:1px solid #91caff;border-radius:4px;color:#0958d9;font-size:11px;font-weight:600;line-height:18px">Single-use &middot; Expires in 10 minutes</span>'
                    .'</div>'
                    .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0"><tr><td align="left">'
                    .'<a href="'.$safeUrl.'" style="display:inline-block;max-width:100%;box-sizing:border-box;background:#0f172a;color:#ffffff;text-decoration:none;padding:13px 24px;border-radius:8px;font-size:14px;font-weight:600;letter-spacing:-0.1px;line-height:1.3">'.$safeAction.' &rarr;</a>'
                    .'</td></tr></table>'
                    .'<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #e2e8f0">'
                    .'<div style="margin:0 0 6px;font-size:12px;font-weight:600;color:#475467">Or copy and paste this link into your browser</div>'
                    .'<div style="padding:10px 12px;background:#ffffff;border:1px solid #e2e8f0;border-radius:6px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\'Liberation Mono\',\'Courier New\',monospace;font-size:11px;line-height:1.5;color:#0f172a;word-break:break-all;overflow-wrap:anywhere">'
                    .'<a href="'.$safeUrl.'" style="color:#1677ff;text-decoration:none;word-break:break-all;overflow-wrap:anywhere">'.$safeUrl.'</a>'
                    .'</div>'
                    .'</div>'
                    .'</div>';
            } else {
                $button = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0 0"><tr><td align="left">'
                    .'<a href="'.$safeUrl.'" style="display:inline-block;max-width:100%;box-sizing:border-box;background:#1677ff;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;line-height:1.3">'.$safeAction.' &rarr;</a>'
                    .'</td></tr></table>'
                    .'<p style="margin:12px 0 0;font-size:12px;line-height:1.6;color:#667085;word-break:break-word;overflow-wrap:anywhere">Button not working? <a href="'.$safeUrl.'" style="color:#1677ff;text-decoration:underline">Open the secure link</a>.</p>';
            }
            $text .= "\n".$action.': '.$url."\n";
        }

        $noteHtml = '';
        if ($note !== '') {
            $text .= "\n".$note."\n";
            $noteHtml = '<div style="margin:22px 0 0;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #1677ff;border-radius:8px;color:#475467;font-size:12px;line-height:1.65;word-break:break-word;overflow-wrap:anywhere">'.$escape($note).'</div>';
        }

        $text .= "\n--\nCarLink · Fleet Operations\n";

        $tagHtml = '<span style="display:inline-block;padding:2px 9px;background:'.$category['bg'].';border:1px solid '.$category['border'].';border-radius:4px;color:'.$category['color'].';font-size:11px;font-weight:600;line-height:18px;white-space:nowrap">'.$escape($category['label']).'</span>';

        $html = '<!doctype html>'
            .'<html lang="en"><head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="color-scheme" content="light">'
            .'<title>'.$escape($title).'</title>'
            .'<style>@media only screen and (max-width:600px){.outer{padding:16px 8px!important}.card{padding:24px 20px!important}.headline{font-size:22px!important}.desc-label,.desc-val{padding:9px 10px!important}.magic-panel{padding:16px!important}}</style>'
            .'</head>'
            .'<body style="margin:0;padding:0;background:#f6f8fb;color:#1f2328;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
            .'<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">'.$escape($intro).'</div>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f6f8fb">'
            .'<tr><td class="outer" align="center" style="padding:36px 16px">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;table-layout:fixed">'
            .'<tr><td style="padding:0 4px 18px">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%"><tr>'
            .'<td style="font-size:20px;font-weight:700;letter-spacing:-0.4px;color:#1f2328;vertical-align:middle">Car<span style="color:#1677ff">Link</span></td>'
            .'<td align="right" style="vertical-align:middle">'.$tagHtml.'</td>'
            .'</tr></table>'
            .'</td></tr>'
            .'<tr><td class="card" style="padding:36px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,0.04)">'
            .'<p style="margin:0 0 8px;color:#1677ff;font-size:11px;font-weight:600;letter-spacing:0.9px;text-transform:uppercase">Your CarLink account</p>'
            .'<h1 class="headline" style="margin:0 0 14px;color:#111827;font-size:24px;font-weight:600;line-height:1.3;letter-spacing:-0.4px;word-break:break-word;overflow-wrap:anywhere">'.$escape($title).'</h1>'
            .'<p style="margin:0 0 22px;color:#475467;font-size:14px;line-height:1.65;word-break:break-word;overflow-wrap:anywhere">'.$escape($intro).'</p>'
            .$detailsHtml
            .$button
            .$noteHtml
            .'<div style="margin:26px 0 0;padding-top:16px;border-top:1px solid #f0f0f0;color:#8c8c8c;font-size:11px;line-height:1.6">'
            .'CarLink &middot; Fleet Operations<br>'
            .'Automated notification &mdash; never share sign-in links or account credentials.'
            .'</div>'
            .'</td></tr>'
            .'</table>'
            .'</td></tr></table>'
            .'</body></html>';

        return ['html' => $html, 'body' => $text];
    }

    private function resolveCategory(string $titleLower, bool $isMagicLink): array
    {
        if ($isMagicLink) {
            return ['label' => 'Sign-In Link', 'bg' => '#f0f5ff', 'border' => '#adc6ff', 'color' => '#2f54eb'];
        }
        if (str_contains($titleLower, 'new sign-in') || str_contains($titleLower, 'security') || str_contains($titleLower, 'details') || str_contains($titleLower, 'photo') || str_contains($titleLower, 'account updated')) {
            return ['label' => 'Security Alert', 'bg' => '#fff2f0', 'border' => '#ffccc7', 'color' => '#cf1322'];
        }
        if (str_contains($titleLower, 'account access') || str_contains($titleLower, 'account status') || str_contains($titleLower, 'suspended') || str_contains($titleLower, 'activated')) {
            return ['label' => 'Account Status', 'bg' => '#f9f0ff', 'border' => '#d3adf7', 'color' => '#531dab'];
        }
        if (str_contains($titleLower, 'invoice') || str_contains($titleLower, 'subscription') || str_contains($titleLower, 'payment')) {
            return ['label' => 'Billing & Plan', 'bg' => '#e6f4ff', 'border' => '#91caff', 'color' => '#0958d9'];
        }
        if (str_contains($titleLower, 'compliance') || str_contains($titleLower, 'maintenance') || str_contains($titleLower, 'road tax') || str_contains($titleLower, 'threshold')) {
            return ['label' => 'Compliance & Maintenance', 'bg' => '#fffbe6', 'border' => '#ffe58f', 'color' => '#d46b08'];
        }
        if (str_contains($titleLower, 'driving') || str_contains($titleLower, 'summary') || str_contains($titleLower, 'score') || str_contains($titleLower, 'observations')) {
            return ['label' => 'Driving Analytics', 'bg' => '#f6ffed', 'border' => '#b7eb8f', 'color' => '#389e0d'];
        }
        if (str_contains($titleLower, 'appeal')) {
            return ['label' => 'Appeal Update', 'bg' => '#e6fffb', 'border' => '#87e8de', 'color' => '#08979c'];
        }

        return ['label' => 'Fleet Notification', 'bg' => '#fafafa', 'border' => '#d9d9d9', 'color' => '#595959'];
    }

    private function formatDetailValue(mixed $rawInput, string $rawVal, callable $escape): string
    {
        $valEsc = $escape($rawVal);
        if ($rawInput === null) {
            return '<span style="color:#8c8c8c">'.$valEsc.'</span>';
        }

        $lower = mb_strtolower(trim($rawVal));
        $tagBase = 'display:inline-block;padding:1px 8px;border-radius:4px;font-size:12px;font-weight:500;line-height:20px';

        if (in_array($lower, ['active', 'valid', 'approved', 'verified'], true)) {
            return '<span style="'.$tagBase.';background:#f6ffed;border:1px solid #b7eb8f;color:#389e0d">'.$valEsc.'</span>';
        }
        if (in_array($lower, ['suspended', 'expired', 'rejected', 'critical', 'failed'], true)) {
            return '<span style="'.$tagBase.';background:#fff2f0;border:1px solid #ffccc7;color:#cf1322">'.$valEsc.'</span>';
        }
        if (in_array($lower, ['due_soon', 'warning', 'pending', 'scheduled'], true)) {
            return '<span style="'.$tagBase.';background:#fffbe6;border:1px solid #ffe58f;color:#d46b08">'.$valEsc.'</span>';
        }
        if (in_array($lower, ['inactive', 'unknown'], true)) {
            return '<span style="'.$tagBase.';background:#fafafa;border:1px solid #d9d9d9;color:#595959">'.$valEsc.'</span>';
        }

        return $valEsc;
    }
}
