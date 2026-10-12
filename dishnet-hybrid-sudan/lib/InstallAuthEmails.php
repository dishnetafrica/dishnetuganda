<?php
declare(strict_types=1);
/**
 * InstallAuthEmails — the two customer e-mails of Customer Installation Authorisation (5.18.82, docs/61 §3, docs/63):
 * the request (the brief's phase 13 — subject verbatim, the job, the charges, the scope, the two buttons, the contact
 * line) and the confirmation after acceptance (phase 7, the same words as the WhatsApp). Built on EmailTemplate like every
 * customer e-mail; sent by CustomerEmailDispatcher::sendInstallAuth(), off the catalogue (the catalogue's tests iterate it,
 * and these two have the feature's own switch). Every builder is a pure function of its inputs: nothing is read here.
 */
require_once __DIR__ . '/EmailTemplate.php';

final class InstallAuthEmails
{
    /** The brief's subject, verbatim (an en dash before "Job"). */
    public static function requestSubject(int $jobId): string
    {
        return 'Action Required: Authorise Your DishNet Starlink Installation – Job ' . $jobId;
    }

    /**
     * The request. $d: customer_name, job_id, service, equipment, location, scheduled_label, installation, transport,
     * other, other_label, total (each already formatted "UGX 350,000"), link, terms_version, legal.
     */
    public static function request(array $c, array $d): array
    {
        $e = function ($s) { return EmailTemplate::e((string)$s); };
        $job   = (int)($d['job_id'] ?? 0);
        $link  = (string)($d['link'] ?? '');
        $legal = (string)($d['legal'] ?? 'DishNet Africa Limited');
        $sub   = self::requestSubject($job);

        $charges = ['Installation charges' => (string)($d['installation'] ?? '')];
        if (($d['transport_raw'] ?? 0) > 0) $charges['Transport charges'] = (string)($d['transport'] ?? '');
        if (($d['other_raw'] ?? 0) > 0)     $charges[(string)($d['other_label'] ?? 'Other agreed charges')] = (string)($d['other'] ?? '');
        $charges['Total'] = (string)($d['total'] ?? '');

        $body = EmailTemplate::h1('Authorise your Starlink installation')
              . EmailTemplate::p('Dear ' . $e(self::name($d)) . ',')
              . EmailTemplate::p('Your DishNet Starlink installation has been scheduled. Before our technician can commence the '
                  . 'installation, please review the details and the Installation Terms, then accept or decline on the secure page.')
              . EmailTemplate::facts([
                    'Installation Job' => (string)$job,
                    'Service'          => (string)($d['service'] ?? ''),
                    'Equipment'        => (string)($d['equipment'] ?? ''),
                    'Location'         => (string)($d['location'] ?? ''),
                    'Scheduled'        => (string)($d['scheduled_label'] ?? ''),
                ])
              . EmailTemplate::h1('Charges')
              . EmailTemplate::facts($charges)
              . EmailTemplate::p('The secure page shows the complete Installation Terms (' . $e((string)($d['terms_version'] ?? '')) . '). '
                  . 'Nothing is authorised until you confirm there.')
              . EmailTemplate::button($c, 'ACCEPT & AUTHORISE INSTALLATION', $link)
              . EmailTemplate::button($c, 'DECLINE INSTALLATION', $link . '&intent=decline')
              . EmailTemplate::note('If the buttons do not open, copy this link into your browser:<br>'
                  . '<a href="' . $e($link) . '">' . $e($link) . '</a><br>The link is personal to this installation and stops working on '
                  . $e((string)($d['expires_label'] ?? 'its expiry date')) . '.', 'info')
              . EmailTemplate::p(self::supportLine($c));

        $text = "Dear " . self::name($d) . ",\r\n\r\n"
              . "Your DishNet Starlink installation has been scheduled.\r\n\r\n"
              . self::textFacts(['Installation Job' => (string)$job, 'Service' => (string)($d['service'] ?? ''), 'Equipment' => (string)($d['equipment'] ?? ''),
                                 'Location' => (string)($d['location'] ?? ''), 'Scheduled' => (string)($d['scheduled_label'] ?? '')])
              . "\r\n" . self::textFacts($charges)
              . "\r\nBefore our technician can commence the installation, please review and accept the Installation Terms ("
              . (string)($d['terms_version'] ?? '') . "):\r\n\r\n" . $link . "\r\n\r\n"
              . "You may accept or decline from the secure page. The link stops working on " . (string)($d['expires_label'] ?? 'its expiry date') . ".\r\n\r\n"
              . $legal . "\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Review and authorise your Starlink installation — Job ' . $job);
    }

    /**
     * After acceptance (the brief's phase 7). $d adds: reference, accepted_label, technician_first ('' when nobody is
     * assigned), legal.
     */
    public static function confirmed(array $c, array $d): array
    {
        $e = function ($s) { return EmailTemplate::e((string)$s); };
        $job   = (int)($d['job_id'] ?? 0);
        $ref   = (string)($d['reference'] ?? '');
        $tech  = trim((string)($d['technician_first'] ?? ''));
        $legal = (string)($d['legal'] ?? 'DishNet Africa Limited');
        $sub   = 'Installation authorised – Job ' . $job . ' (' . $ref . ')';

        $facts = ['Installation Job' => (string)$job, 'Installation' => (string)($d['service'] ?? ''), 'Acceptance Reference' => $ref,
                  'Accepted' => (string)($d['accepted_label'] ?? ''), 'Terms' => (string)($d['terms_version'] ?? '')];
        if ($tech !== '') $facts = ['Technician' => $tech] + $facts;

        $body = EmailTemplate::h1('✅ Installation authorised')
              . EmailTemplate::p('Dear ' . $e(self::name($d)) . ',')
              . EmailTemplate::p('Your DishNet Starlink Installation Job ' . $job . ' has been authorised. You have successfully accepted the DishNet Installation Terms.')
              . ($tech !== ''
                    ? EmailTemplate::p('Our assigned technician has been notified.')
                    : EmailTemplate::p('Your installation has been authorised. DishNet will assign/confirm the technician separately.'))
              . EmailTemplate::facts($facts)
              . EmailTemplate::note('Keep this e-mail: the acceptance reference identifies your authorisation.', 'good')
              . EmailTemplate::p(self::supportLine($c));

        $text = "✅ INSTALLATION AUTHORISED\r\n\r\n"
              . "Dear " . self::name($d) . ",\r\n\r\n"
              . "Your DishNet Starlink Installation Job " . $job . " has been authorised.\r\n\r\n"
              . "You have successfully accepted the DishNet Installation Terms.\r\n\r\n"
              . ($tech !== '' ? "Our assigned technician has been notified.\r\n\r\nTechnician:\r\n" . $tech . "\r\n\r\n"
                              : "Your installation has been authorised. DishNet will assign/confirm the technician separately.\r\n\r\n")
              . "Installation:\r\n" . (string)($d['service'] ?? '') . "\r\n\r\n"
              . "Acceptance Reference:\r\n" . $ref . "\r\n\r\n"
              . $legal . "\r\n\r\n";

        return self::pack($c, $sub, $body, $text, 'Installation Job ' . $job . ' authorised — ' . $ref);
    }

    // ── Helpers (CustomerEmails keeps its own private; these are the same shapes) ──

    private static function name(array $d): string
    {
        $n = trim((string)($d['customer_name'] ?? ''));
        return $n !== '' ? $n : 'Customer';
    }

    private static function textFacts(array $rows): string
    {
        $out = '';
        foreach ($rows as $label => $value) {
            $v = trim((string)$value);
            if ($v === '') continue;
            $out .= $label . ': ' . $v . "\r\n";
        }
        return $out;
    }

    private static function supportLine(array $c): string
    {
        $b = EmailTemplate::brand($c);
        return 'Questions? WhatsApp or call us on <strong>' . EmailTemplate::e($b['support_phone']) . '</strong>.';
    }

    private static function pack(array $c, string $subject, string $body, string $text, string $pre = ''): array
    {
        $banner = trim((string)($c['email_test_banner'] ?? ''));
        if ($banner !== '') {
            $subject = '[TEST] ' . $subject;
            $text    = '*** ' . $banner . " ***\r\n\r\n" . $text;
        }
        return [
            'subject' => $subject,
            'html'    => EmailTemplate::wrap($c, $subject, $body, ['preheader' => $pre]),
            'text'    => $text . EmailTemplate::textFooter($c),
        ];
    }
}
