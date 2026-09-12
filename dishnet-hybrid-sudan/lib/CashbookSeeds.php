<?php
declare(strict_types=1);
require_once __DIR__ . '/timezone.php';

/**
 * Who the cashbook offers you when a category has little history.
 *
 * These are SUGGESTIONS, not a whitelist — the picker falls back to them only
 * where real cb_ledger history has fewer than six names, and a clerk can
 * always type something else. Nothing is rejected for being absent here.
 *
 * That is exactly why they were easy to miss: the list below is South Sudan
 * top to bottom — Juba sites, JEDCO power, NRA tax, and airtime from Zain and
 * VivaCell, neither of which operates in Uganda. Nothing errors; a Kampala
 * clerk is simply offered the wrong names, and on a fresh install with no
 * history yet, they are the ONLY names offered.
 *
 * ── SHAPE OF THE FIX ────────────────────────────────────────────────────
 *
 * The defaults stay exactly as they were, so an install that configures
 * nothing behaves as it always has. `cashbook_seeds` in config is merged
 * OVER them per category:
 *
 *   absent                        → the South Sudan defaults, untouched
 *   {"Airtime": ["MTN","Airtel"]} → that one category replaced
 *   {"Salary": []}                → that category offers nothing at all
 *
 * The empty-array case matters most. For the staff and vendor categories
 * there is no correct Uganda list that can be written here — those are this
 * business's own people and suppliers, and guessing them would put invented
 * names in front of a clerk posting real money. Suggesting nothing is the
 * honest state until history fills in on its own.
 */
final class CashbookSeeds
{
    /** Unchanged South Sudan history. Do not localise in place — override. */
    public const DEFAULTS = [
        // Staff payments
        'Salary'         => ['Bidal','Emmanuel','Ochiti','Kamanda','Modi Mawa Francis','Diko','Amos','Mackline Anena'],
        'Transport Allowance' => ['Bidal','Emmanuel','Diko','Mackline Anena','Modi Mawa Francis','Kamanda'],
        'Food Allowance' => ['Bidal','Emmanuel','Ochiti','Kamanda','Diko','Mackline Anena','Modi Mawa Francis'],
        'Bonus'          => ['Bidal','Emmanuel','Ochiti','Kamanda','Amos','Diko','Modi Mawa Francis','Mackline Anena'],
        'Employee Benefit'=> ['Kamanda','Emmanuel','Bidal','Ochiti','Diko','Amos'],
        'Staff Advance'  => ['BBC','Bidal','Emmanuel','Kamanda','Diko','Ochiti','Amos','Justus','Meckline','Modi Mawa Francis'],
        'SSP Advance'    => ['BBC','Bidal','Emmanuel','Kamanda','Diko','Ochiti','Amos','Modi Mawa Francis'],
        'Commission'     => ['Christine','NID Bank','Robert','Sokiri','Peter','Stephen Eku','Ahmed - ICAP','Charles - Afenet','Kennedy Bidali - Afenet','Emmanuel Alli - AFENET'],
        // Sites
        'Site Power'     => ['JEDCO','Electricity - Tomping','Electricity - Munuki','Electricity - City Mall'],
        'Site Rent'      => ['Tomping Branch','City Mall Office','Tower GMSH','UAP Tower','Guest House','Tower Nimule','Gudele Medical','UNMISS Accommodation'],
        'Site Expense'   => ['Emmanuel','Kamanda','Bidal','Kennedy','Geoffrey','Sokiri'],
        // Suppliers & Vendors (from BookKeeper narrations)
        'Local Purchase' => ['Atul','Francis','Kamanda','Amos','Gukina Electricals','Chesco Hi-Tech','CVL General Supply','Bimot Enterprises','Dubai Store','C/C Electrical Shop','Dubai For Exhibition'],
        'Capital Purchase'=> ['Bimot Enterprises','Friends IT','OYEI Times','Flyfine Digital','CVL General Supply'],
        'Bandwidth'      => ['4G Telecom','Bentley Walker','Intersat / BSS Africa','LEOKONNECT','Liquid Telecom','Digital Trend / Wilken','XCEED NET'],
        'Airtime'        => ['MTN','Zain','VivaCell'],
        'Travel & Field' => ['Kamanda','Emmanuel','Francis','Amos','Bidal','Junubin Logistics','Sokiri'],
        // Finance
        'Exchange'       => ['Diko','Rupesh','BBC','Juba Trading'],
        'Tax'            => ['NRA Audit','BPT Tax','PIT Tax','Excise Tax','WT Rental Tax'],
        'Loan Given'     => ['Harpal Bapu','Arkangelo','Dynamic Construction','Build Africa','Bhavin','Staff Advance'],
        'Loan Received'  => ['Waka General Trading','4G Telecom Advance','BBC'],
        'Interco Out'    => ['DishNet 4G','BlueCARD','Build Africa'],
        'Bank Transfer'  => ['ECO Bank','Stanbic Bank','Equity Bank'],
        'Discount'       => ['Customer Discount','Promotional'],
        'Build Africa'   => ['Build Africa','Tax & Work Permit','Iron Bed'],
        'Misc Expense'   => ['Arkangelo','Charles','Rupesh','Chirag Patel','Amos','Yash','Manoj Bhai'],
        'Refund'         => ['Customer Refund'],
        'Customer Refund'=> ['Customer Refund','Overpayment','Service Issue','Cancelled Subscription'],
        'Customer Commission' => ['Referral Bonus','Loyalty Discount','Promotional','Agent Bonus'],
        // v4.9.10: new categories from BookKeeper audit
        'Govt Fees'      => ['NCA Administrative Fees','NCA Operation Fees','NRA Audit Fees','USAF (Universal Service Fund)','Excise Tax','PIT Tax','BPT Tax','Rental Tax (WT)'],
        'Legal Fees'     => ['Lawyer Fees','Court Fees','Work Permit','Visa Fees','Registration'],
        'Vehicle'        => ['Maintenance','Fuel / Diesel','Insurance','Spare Parts','Registration','Tyre'],
        'Advertising'    => ['Facebook Ads','Google Ads','Print / Billboard','Promotional Material'],
        'Partner Remuneration' => ['Tom (Joseph Luate)','Bhavin (Madlani)','Nirmal (Samani)','Paji (Shamshare Singh)','Rupesh'],
        'Renewal Charges'=> ['License Renewal','Domain / Hosting','Software Charges','Splynx','Zoom','SSL Certificate','AFRINIC'],
    ];

    /** The site picker's fallback list. Juba locations, same as above. */
    public const DEFAULT_SITES = [
        'Tomping Branch — JEDCO','Tomping Branch — M-Gurush',
        'City Mall Office','Munuki','Hai Saura','Wamo Site',
        'Kator New Site','Konyo Konyo / Yatco','Jebel Market',
        'Custom Market','Jabrona','Home / Office — JEDCO',
        'Tomping Branch Office','City Mall Office — Rent',
        'Tower GMSH','UAP Tower','Tower Nimule',
        'Guest House (Dishnet)','Gudele Medical — Server Room',
        'Shop — Advance Rent','UNMISS Accommodation',
        'JEDCO','SSEC (Govt Power)',
        'Generator — Fuel','Generator — Maintenance',
    ];

    /**
     * Sites offered in the cashbook picker, `cashbook_sites` overriding.
     *
     * A plain list, not a map: an install replaces it wholesale or leaves it.
     * Real cb_ledger history is appended to whatever this returns, so an
     * install that sets [] simply starts empty and fills from its own use.
     */
    public static function sites(?array $config = null): array
    {
        $over = $config['cashbook_sites'] ?? null;
        if (is_string($over) && trim($over) !== '') {
            $decoded = json_decode($over, true);
            $over = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($over)) return self::DEFAULT_SITES;

        $clean = [];
        foreach ($over as $n) {
            if (!is_scalar($n)) continue;
            $n = trim((string)$n);
            if ($n !== '' && !in_array($n, $clean, true)) $clean[] = $n;
        }
        return $clean;
    }

    /**
     * The seed map for this install.
     *
     * @param array|null $config plugin config; `cashbook_seeds` may override
     */
    public static function map(?array $config = null): array
    {
        $seeds = self::DEFAULTS;
        $over  = $config['cashbook_seeds'] ?? null;

        // Config arrives from JSON, so anything could be in there. A malformed
        // override must leave the defaults standing rather than empty the
        // picker; a half-applied suggestion list is worse than none.
        if (is_string($over) && trim($over) !== '') {
            $decoded = json_decode($over, true);
            $over = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($over)) return $seeds;

        foreach ($over as $cat => $names) {
            $cat = trim((string)$cat);
            if ($cat === '' || !is_array($names)) continue;
            $clean = [];
            foreach ($names as $n) {
                if (!is_scalar($n)) continue;
                $n = trim((string)$n);
                if ($n !== '' && !in_array($n, $clean, true)) $clean[] = $n;
            }
            $seeds[$cat] = $clean;   // [] deliberately means "offer nothing"
        }
        return $seeds;
    }
}
