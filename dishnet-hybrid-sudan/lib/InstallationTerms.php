<?php
declare(strict_types=1);
/**
 * InstallationTerms — the versioned Installation Terms a customer accepts to authorise a Starlink installation
 * (5.18.82, docs/61 §3, docs/62, docs/63).
 *
 * Each version is a constant: the exact text, byte for byte. Its SHA-256 is computed from that text and stored on the
 * acceptance record beside the version name, so an acceptance made under v1.0 stays reproducible after a v1.1 exists — a
 * record keeps its own version and hash, and the page a dispute re-reads is rendered from the version the record names,
 * never from the latest. A later version is a new constant and a new entry in VERSIONS; an old one is never edited (a test
 * pins the hash of v1.0).
 *
 * The text is a DRAFT — SUBJECT TO LEGAL REVIEW (docs/62). It is a business and technical implementation; nothing here
 * claims the terms are legally approved, and nothing in them is meant to exclude a right that Ugandan law does not allow
 * to be excluded.
 */
final class InstallationTerms
{
    public const VERSION = 'INSTALLATION-TERMS-v1.0';
    public const STATUS  = 'DRAFT — SUBJECT TO LEGAL REVIEW';
    public const TITLE   = 'Starlink Installation Terms';

    /** Every version this code can render, oldest first. */
    public const VERSIONS = ['INSTALLATION-TERMS-v1.0'];

    private const TEXT_V1_0 = <<<'TERMS'
DISHNET AFRICA LIMITED — STARLINK INSTALLATION TERMS
Version INSTALLATION-TERMS-v1.0
DRAFT — SUBJECT TO LEGAL REVIEW

These Installation Terms ("Terms") apply to the installation of Starlink equipment and related work ("Installation") that DishNet Africa Limited ("DishNet", "we", "us") carries out for the customer named in the Installation Job ("Customer", "you"). The Installation Job is the record of the specific installation these Terms are accepted for: it names the job number, the installation location, the service, the equipment, the charges and, where one has been set, the scheduled date.

1. Customer authorisation
By accepting these Terms you authorise DishNet to carry out the Installation described in the Installation Job, at the installation location, for the charges shown at the time of acceptance. Acceptance is recorded electronically (section 22). DishNet will not commence the Installation before this authorisation is recorded.

2. Scope of installation
The Installation covers the work described in the Installation Job: mounting the Starlink dish at a suitable position agreed on site, routing the cable, placing and connecting the router and power supply, and confirming that the Starlink service is reachable from the equipment. Work not described in the Installation Job is outside the scope of this authorisation.

3. Installation charges
The installation charge is the amount shown in the Installation Job at the time of acceptance. It covers the labour and the standard materials for the scope in section 2.

4. Transport charges
Where a transport charge is shown in the Installation Job, it covers the technician's travel to the installation location. If no transport charge is shown, none is payable for the scheduled visit.

5. Additional materials and work
Materials or work beyond the standard scope (for example extra cable length, poles, brackets, trenching, conduit or electrical work) are not included unless they are listed in the Installation Job. If additional materials or work become necessary on site, the technician will explain what is needed and what it costs before carrying it out, and will proceed only with your agreement. You may decline additional work; the Installation is then completed as far as the authorised scope allows, or rescheduled.

6. Site access
You will provide safe access to the installation location at the scheduled time, including access to roofs, walls, rooms and any other area where the equipment or the cable is to be placed. Where the property is rented or shared, you confirm that you have the permission needed for the Installation.

7. Electricity, power and access requirements
A working mains power point is needed where the router and the power supply will be placed, and the dish needs a position with a clear view of the sky. You are responsible for providing power at the site and for any electrical work that is not part of the Installation Job.

8. Accurate information
You confirm that the name, installation location, contact details and other information given for the Installation are accurate. DishNet relies on this information to plan the visit; inaccurate information may delay or prevent the Installation.

9. Scheduling
The scheduled date and time shown in the Installation Job is the planned visit. Either party may ask to reschedule by contacting the other before the visit, and DishNet will confirm any new date and time.

10. Technician attendance
DishNet will send a technician to the installation location at the scheduled time. The technician may ask you or your representative to confirm the dish position and the cable route before work begins. If nobody is available to give access at the scheduled time, the visit may need to be rescheduled and the transport charge shown in the Installation Job, where one is shown, may apply.

11. Cancellation before dispatch
You may cancel the Installation before the technician has been dispatched by contacting DishNet. No installation or transport charge is payable for a visit cancelled before dispatch.

12. Cancellation after dispatch
If the Installation is cancelled after the technician has been dispatched and before work has commenced, the transport charge shown in the Installation Job, where one is shown, may be payable.

13. Customer refusal after work has commenced
Once the Customer has accepted these Terms and DishNet has commenced the authorised installation works, the Customer remains responsible for applicable installation charges and other charges expressly agreed for the installation, subject to any cancellation, refund or other rights that cannot lawfully be excluded under applicable law.

14. Installation completion
The Installation is complete when the equipment has been installed as described in the Installation Job and the Starlink service has been confirmed reachable from the equipment, or when the parts of the work that can be completed on site have been completed and the remainder has been recorded with you.

15. Customer sign-off
At completion the technician may ask you or your representative to confirm that the Installation has been completed and received. Sign-off confirms receipt of the completed Installation; it is separate from, and does not replace, the authorisation given under section 1.

16. Equipment handling
The technician will handle the Starlink equipment with care and install it according to the manufacturer's guidance. The equipment remains subject to the terms under which it was purchased or supplied; these Terms do not change them.

17. Site and customer-caused damage
DishNet is responsible for damage caused by its technician's negligence during the Installation. To the extent permitted by applicable law, DishNet is not responsible for damage caused by conditions that existed at the site before the Installation, by instructions you give, or by work carried out by others.

18. Service activation
Starlink service activation and the Starlink service itself are provided under their own terms. The Installation makes the equipment ready for use; service availability, speed and coverage are not promised by these Terms.

19. Billing
Charges for the Installation are invoiced by DishNet in the currency shown in the Installation Job (Ugandan Shillings unless another currency is stated there). Payment terms are as stated on the invoice.

20. Refunds and cancellation
Refunds and cancellations are handled under sections 11 to 13 and applicable law. Nothing in these Terms removes any refund or cancellation right that cannot lawfully be excluded.

21. Dispute resolution
If you have a concern about the Installation, please contact DishNet first so that it can be resolved directly. A dispute that cannot be resolved by discussion is subject to the laws of Uganda and the jurisdiction of the courts of Uganda, without limiting any right to use a consumer complaint process available under applicable law.

22. Electronic acceptance
These Terms are accepted electronically through the secure DishNet acceptance page sent to you. DishNet records the acceptance reference, the time of acceptance, the version and hash of these Terms, and the installation details and charges shown to you at that time. That record is the evidence of your authorisation.

23. Data and privacy
DishNet uses the personal information connected with the Installation (your name, contact details and installation location) to plan and carry out the Installation, to communicate with you about it, and to keep the records described in section 22, in accordance with DishNet's Privacy Policy and applicable Ugandan data protection law.

24. Terms version
These Terms are version INSTALLATION-TERMS-v1.0. Your acceptance refers to this version only. A later version does not change an Installation accepted under this version.

25. Limitation of liability
To the extent permitted by applicable law, DishNet's liability in connection with the Installation is limited to the charges paid for the Installation, and DishNet is not liable for indirect or consequential loss. Nothing in these Terms is intended to exclude or restrict any right or remedy that cannot lawfully be excluded or restricted under applicable Ugandan law.
TERMS;

    /** The exact text of a version, or null for one this code does not know. */
    public static function text(string $version = self::VERSION): ?string
    {
        switch ($version) {
            case 'INSTALLATION-TERMS-v1.0': return self::TEXT_V1_0;
            default: return null;
        }
    }

    /** SHA-256 of the exact text (hex), or null for an unknown version. */
    public static function hash(string $version = self::VERSION): ?string
    {
        $t = self::text($version);
        return $t === null ? null : hash('sha256', $t);
    }

    /** Whether a stored (version, hash) pair still names text this code holds, byte for byte. */
    public static function matches(string $version, string $hash): bool
    {
        $h = self::hash($version);
        return $h !== null && hash_equals($h, $hash);
    }

    /**
     * The text as sections for rendering: the preamble (n = 0) then one entry per numbered clause,
     * each ['n' => int, 'title' => string, 'body' => string].
     */
    public static function sections(string $version = self::VERSION): array
    {
        $t = self::text($version);
        if ($t === null) return [];
        $out = [];
        $blocks = preg_split('/\n\n+/', trim($t)) ?: [];
        $pre = [];
        foreach ($blocks as $b) {
            if (preg_match('/^(\d{1,2})\. ([^\n]+)\n(.*)$/s', $b, $m)) {
                $out[] = ['n' => (int)$m[1], 'title' => trim($m[2]), 'body' => trim($m[3])];
            } elseif ($out === []) {
                $pre[] = trim($b);
            }
        }
        array_unshift($out, ['n' => 0, 'title' => '', 'body' => implode("\n\n", $pre)]);
        return $out;
    }

    /** A short, human-readable form of a hash for a page or a message: the first and last four hex characters. */
    public static function shortHash(string $hash): string
    {
        return strlen($hash) >= 16 ? substr($hash, 0, 8) . '…' . substr($hash, -8) : $hash;
    }
}
