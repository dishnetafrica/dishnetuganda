<?php
declare(strict_types=1);

/**
 * EmailTemplate — the one shell every DishNet customer email is built in.
 *
 * WHY THIS EXISTS
 * The email audit (docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md) found seven templates
 * with seven different footers, one mobile-responsive layout between them, and
 * a South Sudan phone number printed on every message a Uganda customer would
 * receive. One shell fixes the whole class of problem: change the footer once
 * and every email changes.
 *
 * SUDAN SAFETY
 * Every brand value defaults to what the Sudan install renders TODAY. An
 * install that configures nothing produces the same wording it produces now;
 * Uganda supplies its own values through kyc_config.json. There is no branch
 * on country anywhere in this file — only configuration.
 *
 * Config keys (all optional):
 *   email_company_name    'DishNet Africa Ltd.'
 *   email_locality        'Juba, South Sudan'
 *   email_website         'dishnetafrica.com'
 *   email_support_phone   '+211 921 443 009'      shown to the customer
 *   email_support_wa      '211921443009'          digits for the wa.me link
 *   email_reply_to        'info@dishnetafrica.com'
 *   email_legal_line      ''    e.g. 'TIN 1059140632 · Reg. No. 80046255496181'
 *   email_badge_line      ''    e.g. 'UCC Authorised Starlink Installer'
 *   email_accent          '#D41C1C'
 *
 * PHP 7.4 compatible. No external assets: no remote fonts, no tracking pixels,
 * no images — every mail client renders it identically offline.
 */
class EmailTemplate
{
    const DEFAULTS = [
        'email_company_name'  => 'DishNet Africa Ltd.',
        'email_locality'      => 'Juba, South Sudan',
        'email_website'       => 'dishnetafrica.com',
        'email_support_phone' => '+211 921 443 009',
        'email_support_wa'    => '211921443009',
        'email_reply_to'      => 'info@dishnetafrica.com',
        'email_legal_line'    => '',
        'email_badge_line'    => '',
        'email_accent'        => '#D41C1C',
    ];

    /** Resolved brand values: configured value, else the historical default. */
    public static function brand(array $config): array
    {
        $b = [];
        foreach (self::DEFAULTS as $k => $default) {
            $v = trim((string)($config[$k] ?? ''));
            $b[substr($k, 6)] = $v !== '' ? $v : $default;   // strip 'email_'
        }
        return $b;
    }

    public static function replyTo(array $config): string
    {
        return self::brand($config)['reply_to'];
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    // ── Building blocks ──────────────────────────────────────────────────

    /** Section heading inside the body. */
    public static function h1(string $text): string
    {
        return '<h1 style="margin:0 0 14px;font-family:Helvetica,Arial,sans-serif;font-size:21px;'
             . 'line-height:1.3;font-weight:800;color:#141414;">' . self::e($text) . '</h1>';
    }

    /** Body paragraph. $html is trusted markup built by the caller. */
    public static function p(string $html): string
    {
        return '<p style="margin:0 0 14px;font-family:Helvetica,Arial,sans-serif;font-size:15px;'
             . 'line-height:1.6;color:#333333;">' . $html . '</p>';
    }

    /** Bulletproof-ish call to action. */
    public static function button(array $config, string $label, string $url): string
    {
        $a = self::brand($config)['accent'];
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;">'
             . '<tr><td style="background:' . self::e($a) . ';border-radius:8px;">'
             . '<a href="' . self::e($url) . '" style="display:inline-block;padding:13px 28px;'
             . 'font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;'
             . 'text-decoration:none;border-radius:8px;">' . self::e($label) . '</a>'
             . '</td></tr></table>';
    }

    /**
     * The detail block — label/value rows. This is what answers "what exactly
     * did I buy, how much, and when", the questions the audit found unanswered.
     */
    public static function facts(array $rows): string
    {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
           . 'style="background:#f7f7f7;border-radius:8px;margin:0 0 18px;">';
        foreach ($rows as $label => $value) {
            if ($value === '' || $value === null) continue;
            $h .= '<tr>'
               . '<td style="padding:9px 14px;font-family:Helvetica,Arial,sans-serif;font-size:13px;'
               . 'color:#6b6b6b;white-space:nowrap;">' . self::e((string)$label) . '</td>'
               . '<td style="padding:9px 14px;font-family:Helvetica,Arial,sans-serif;font-size:14px;'
               . 'font-weight:700;color:#141414;text-align:right;">' . self::e((string)$value) . '</td>'
               . '</tr>';
        }
        return $h . '</table>';
    }

    /** Highlighted note. $tone: info | warn | good */
    public static function note(string $html, string $tone = 'info'): string
    {
        $map = [
            'info' => ['#f0f6ff', '#c9ddff', '#1b3a6b'],
            'warn' => ['#fffbeb', '#fde68a', '#78350f'],
            'good' => ['#f0fdf4', '#86efac', '#065f46'],
        ];
        list($bg, $bd, $fg) = $map[$tone] ?? $map['info'];
        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
             . 'style="background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:8px;margin:0 0 18px;">'
             . '<tr><td style="padding:12px 14px;font-family:Helvetica,Arial,sans-serif;font-size:13px;'
             . 'line-height:1.6;color:' . $fg . ';">' . $html . '</td></tr></table>';
    }

    /** Numbered "what happens next" list — the audit's biggest content gap. */
    public static function steps(array $items): string
    {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 18px;">';
        $n = 1;
        foreach ($items as $item) {
            $h .= '<tr>'
               . '<td width="26" valign="top" style="padding:0 0 10px;font-family:Helvetica,Arial,sans-serif;'
               . 'font-size:13px;font-weight:800;color:#141414;">' . $n . '.</td>'
               . '<td style="padding:0 0 10px;font-family:Helvetica,Arial,sans-serif;font-size:14px;'
               . 'line-height:1.55;color:#333333;">' . $item . '</td>'
               . '</tr>';
            $n++;
        }
        return $h . '</table>';
    }

    // ── The shell ────────────────────────────────────────────────────────

    /**
     * Wrap body markup in the full document: preheader, wordmark, accent rule,
     * content card, footer with contact + legal + badge lines.
     *
     * @param array $opts  ['preheader' => string]  the grey line inboxes preview
     */
    public static function wrap(array $config, string $title, string $bodyHtml, array $opts = []): string
    {
        $b    = self::brand($config);
        $year = date('Y');
        $pre  = self::e((string)($opts['preheader'] ?? ''));
        $acc  = self::e($b['accent']);
        $site = self::e($b['website']);
        $wa   = preg_replace('/\D+/', '', $b['support_wa']);

        $badge = $b['badge_line'] !== ''
            ? '<div style="margin-top:3px;font-size:11px;color:#8a8a8a;">' . self::e($b['badge_line']) . '</div>'
            : '';
        $legal = $b['legal_line'] !== ''
            ? '<br>' . self::e($b['legal_line'])
            : '';

        return '<!DOCTYPE html><html lang="en"><head>'
. '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
. '<title>' . self::e($title) . '</title>'
. '<style>'
. 'body{margin:0;padding:0;background:#f2f2f2;-webkit-text-size-adjust:100%;}'
. '@media only screen and (max-width:620px){'
  . '.dn-wrap{width:100%!important;}'
  . '.dn-pad{padding-left:20px!important;padding-right:20px!important;}'
  . '.dn-h1{font-size:19px!important;}'
. '}'
. '@media (prefers-color-scheme:dark){'
  . 'body,.dn-bg{background:#121212!important;}'
  . '.dn-card{background:#1e1e1e!important;}'
  . '.dn-card h1,.dn-card p,.dn-card td{color:#e8e8e8!important;}'
  . '.dn-muted{color:#9a9a9a!important;}'
. '}'
. '</style></head>'
. '<body class="dn-bg" style="margin:0;padding:0;background:#f2f2f2;">'
. ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $pre . '</div>' : '')
. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" class="dn-bg" style="background:#f2f2f2;">'
. '<tr><td align="center" style="padding:24px 12px;">'
. '<table role="presentation" cellpadding="0" cellspacing="0" width="600" class="dn-wrap dn-card" '
. 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">'

// header
. '<tr><td class="dn-pad" style="padding:26px 32px 0;">'
. '<span style="display:inline-block;font-family:Helvetica,Arial,sans-serif;font-weight:900;font-size:22px;'
. 'letter-spacing:-0.4px;color:#141414;">DishNet</span>'
. '<div style="height:4px;width:64px;background:' . $acc . ';border-radius:2px;margin:6px 0 0;"></div>'
. $badge
. '</td></tr>'

// body
. '<tr><td class="dn-pad" style="padding:22px 32px 6px;">' . $bodyHtml . '</td></tr>'

// footer
. '<tr><td class="dn-pad" style="padding:16px 32px 26px;border-top:1px solid #eeeeee;">'
. '<p class="dn-muted" style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:11px;'
. 'line-height:1.7;color:#999999;">'
. '<strong style="color:#666666;">' . self::e($b['company_name']) . '</strong> &middot; ' . self::e($b['locality'])
. $legal . '<br>'
. '<a href="https://' . $site . '" style="color:' . $acc . ';text-decoration:none;">' . $site . '</a>'
. ' &middot; Support: <a href="https://wa.me/' . self::e($wa) . '" style="color:' . $acc . ';text-decoration:none;">'
. self::e($b['support_phone']) . '</a>'
. '</p>'
. '<p class="dn-muted" style="margin:12px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:10px;'
. 'line-height:1.5;color:#bbbbbb;">&copy; ' . $year . ' ' . self::e($b['company_name'])
. '. You are receiving this because you are a DishNet customer.</p>'
. '</td></tr>'

. '</table></td></tr></table></body></html>';
    }

    /** Plain-text footer, so every text alternative ends the same way. */
    public static function textFooter(array $config): string
    {
        $b = self::brand($config);
        $out = "\r\n--\r\n" . $b['company_name'] . ' · ' . $b['locality'] . "\r\n";
        if ($b['legal_line'] !== '') $out .= $b['legal_line'] . "\r\n";
        if ($b['badge_line'] !== '') $out .= $b['badge_line'] . "\r\n";
        $out .= 'https://' . $b['website'] . "\r\n";
        $out .= 'Support: ' . $b['support_phone'] . "\r\n";
        return $out;
    }
}
