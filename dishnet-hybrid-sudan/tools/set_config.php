<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * set_config.php — turn a plugin setting on or off from the terminal.
 *
 *   php tools/set_config.php                          what the AI flags are set to
 *   php tools/set_config.php --key <name> --value 1   set one
 *   php tools/set_config.php --key <name> --clear     back to the default
 *
 * Every feature added to the AI is gated on a config key whose absence means
 * the old behaviour, so South Sudan is never moved by a Uganda change. That is
 * the right design and it has one consequence: shipping a feature is not
 * enabling it, and there was no way to enable one without a browser.
 *
 * Secrets are refused. PluginConfig::saveOverrides rejects them at the write,
 * and they belong on the uCRM Configuration screen where they are stored
 * encrypted — never in a shell command, which lands in root's history.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$dataDir = cliDataDir($root);

// The flags that change how the assistant behaves. Listed so `set_config.php`
// with no arguments answers "what is switched on?", which is the question
// actually being asked at a terminal.
// type matters. The first version of this tool ran every value through
// filter_var(FILTER_VALIDATE_BOOLEAN), which counts only 1/true/on/yes as
// true — so a 30-minute cooldown displayed as "OFF", indistinguishable from
// the 0 that disables the stand-down rule entirely. A settings screen that
// cannot tell 30 from 0 is worse than no settings screen.
require_once dirname(__DIR__) . '/lib/timezone.php';

$FLAGS = [
    'ai_qualification' => ['bool',
        'Qualify before recommending; route CCTV/VPN/servers to Business'],
    'ai_lead_capture' => ['bool',
        'Write a CRM lead when the assistant qualifies a real opportunity'],
    'ai_crm_lead_sync' => ['bool',
        'Also create that lead in uCRM as a lead client (needs ai_lead_capture)'],
    'ucrm_lead_organization_id' => ['text',
        'uCRM organization new leads belong to (unset = the one existing clients use)'],
    'ucrm_lead_country_id' => ['text',
        'uCRM country id for new leads (unset = the one existing clients use)'],
    'ai_hardware_expert' => ['bool',
        'Know the dishes: coverage vs Wi-Fi, Ethernet per model, solar'],
    'ai_sales_on_all_numbers' => ['bool',
        'Let every number answer a sales question'],
    'ai_handover_message' => ['text',
        'What the customer hears when the AI hands over'],
    'wa_human_cooldown_minutes' => ['minutes',
        'How long the AI stays quiet after a colleague replies (0 = never stands down)'],
    'stock_statement' => ['text',
        'What to say about availability — stated to customers as written'],
    'ai_currency' => ['text',
        'Currency prices are stated in — shown to customers exactly as typed'],

    // Phase 2 of the customer-login audit — the tenant profile and the
    // sign-in eligibility gates (plan §D.3, §E.6). The profile is the one
    // source of every country-dependent default; the gates are configurable
    // until the lifecycle decision (Phase 3) fixes them.
    'tenant_profile' => ['text',
        'Country profile: south-sudan or uganda (blank = derived from the currency)'],
    'portal_login_allow_leads' => ['bool',
        'Let uCRM leads sign in to the customer portal (default: no)'],
    'portal_login_require_service' => ['bool',
        'Refuse portal sign-in to clients with no uCRM service (default: no)'],
    // 5.18.73 — where the customer portal hands usage over to the dishnet-data-report plugin. Blank follows the
    // country profile (yes in both since 5.18.74, at the operator's decision); the portal mints the hand-off token
    // only once webhook_secret and crm_auth_token are both set, because the other plugin rebuilds the same secret
    // and answers 404 to a token signed from an empty one.
    'portal_data_report_handoff' => ['text',
        'Customer portal: open usage in the Data Report plugin — yes or no (blank = the country profile)'],
    // 5.18.74 — the one signing input of that hand-off this plugin can supply itself. A 'secret' key: GENERATED here
    // (--generate), never typed (a value in a shell command lands in root's history) and never shown (the listing says
    // how many characters are set). Settings → Webhook Secret has a Copy button for the one case that needs the value:
    // a uCRM webhook configured to send a key — unset there, uCRM sends none and nothing is refused. The other input is
    // Settings → UCRM Connection → Admin Auth Token.
    'webhook_secret' => ['secret',
        'Signs the customer portal\'s Data Report hand-off (with the Admin Auth Token); a uCRM webhook carrying a '
      . 'DIFFERENT key is refused once this is set — --generate or --clear, never --value'],
    'app_jwt_ttl_days' => ['number',
        'Days a customer stays signed in after a code (default 30)'],
    // A migration instrument with an end date, not a business setting. It
    // runs the controlled customer tools beside the legacy support/accounts
    // prompt and logs whether the two readers agree — verdicts only, never
    // values. The customer still gets the legacy answer either way. It is
    // deleted when B3.5 migrates those two callers.
    'ai_shadow_compare' => ['bool',
        'B3.4: compare the customer tools against the legacy prompt, and log which disagree'],

    // The clock. Everything stamped, scheduled or reported runs on this.
    // Unset means Africa/Juba, which is what the code said before it was
    // configurable — so the South Sudan install is unaffected by its absence.
    // Uganda MUST set it: Africa/Juba has been UTC+2 since South Sudan left
    // East Africa Time on 31 January 2021, while Kampala is UTC+3.
    'timezone' => ['tz',
        'Zone crons, reports and the follow-up window run in (default Africa/Juba)'],

    // Who the cashbook person-picker offers where real history is thin. The
    // defaults are South Sudan — Juba sites, JEDCO, NRA, Zain and VivaCell.
    // Merged over per category; [] means offer nothing, which is the right
    // answer for staff and supplier lists that cannot be guessed.
    'cashbook_seeds' => ['json',
        'Cashbook name suggestions per category, e.g. {"Airtime":["MTN","Airtel"]}'],
    'cashbook_sites' => ['json',
        'Sites offered in the cashbook picker — a JSON list; [] starts blank'],

    // Customer follow-up. Draft mode only: nothing reaches a customer without
    // somebody approving it on the Follow-ups screen.
    'followup_enabled' => ['bool',
        'Notice quiet enquiries and draft a follow-up for a person to approve'],
    'followup_not_before' => ['text',
        'Ignore conversations quiet BEFORE this UTC date — set it when switching on'],
    'followup_daily_cap' => ['number',
        'Most follow-ups sent on one channel in a day (default 30)'],
    'followup_max_age_hours' => ['number',
        'An enquiry older than this is history, not a live lead (default 336 = 14 days)'],
    'followup_run_limit' => ['number',
        'Follow-ups EVALUATED per run (default 5) — set 1 or 2 on a large backlog'],
    'followup_auto_send' => ['bool',
        'Send WhatsApp enquiry follow-ups WITHOUT a person approving them '
        . '(account-level and escalated ones still wait; e-mail is unaffected)'],

    // On every quotation the team sends. QuotationService compiles South Sudan
    // defaults for all three, so an unset key is not a blank — it is Juba's
    // phone number printed on a Ugandan customer's quote.
    'ai_fact_location_pin' => ['text',
        'Map pin for the office — sent verbatim; unset means the AI must not write one'],
    // 5.18.11: the tax treatment of listed prices, as a stated fact. Without
    // it the assistant hedges ("the quotation confirms the tax treatment") on
    // every price; with it, it says what the operator says, and still may
    // not calculate a tax amount or rate.
    // 5.18.49 (docs/42 §9): on Uganda the operator chose the quotations' sentence (QuoteTaxLine::TEXT), so the
    // assistant answers a tax question in the words every quotation uses.
    'ai_fact_prices' => ['text',
        'What to say about tax on listed prices — Uganda uses the quotations\' sentence: "All prices include all taxes — '
      . 'URA taxes and UCC charges are already in them. Nothing is added on top." (unset = the AI hedges)'],
    // Appended by PlanFenceGuard to any reply that names a Business plan
    // without naming Residential. It is here, and not in the prompt, because
    // the prompt version was measured: 18 of 21 replies ignored it. "omit"
    // switches the fence off; unset uses PlanFenceGuard::DEFAULT_NOTE.
    'ai_fact_business_cap' => ['text',
        'Appended when the AI quotes a Business plan without offering Residential — the priority-data '
      . 'cap and the 1 Mbps drop ("omit" = never append; unset = the built-in wording)'],
    // 5.18.48 (docs/42): appended by KitTaxNote under a Starlink kit price, where the hardware module is on. Unset
    // uses KitTaxNote::DEFAULT_NOTE, the wording the operator approved on 27 Sep 2026; "omit" switches it off.
    'ai_fact_kit_taxes' => ['text',
        'Appended when the AI quotes a Starlink kit price (hardware module on) — what the kit price includes '
      . '("omit" = never append; unset = URA taxes and the UCC registration fee, the approved wording)'],
    // 5.18.44 (docs/40): the data allowance, stated beside the plans where the install qualifies.
    // Unset uses DishNetAiBrain::UNLIMITED_FACT, the wording the operator approved on 27 Sep 2026
    // ("keep as it is"); "omit" switches it off.
    'ai_fact_unlimited' => ['text',
        'What the AI says about data allowances, word for word ("omit" = say nothing; unset = both '
      . 'Residential plans are unlimited, only the Business plans carry a block of priority data)'],
    // The three business facts that shipped with South Sudan wording and had
    // no way to change them: not on the uCRM Configuration screen, not in the
    // Engage tab, not here. Unset, a Ugandan customer is told the office is
    // in Juba and that kits cross at the Joda border, and that we never share
    // bank details. "omit" drops a fact entirely, which beats saying the
    // wrong thing while the right words are still being decided.
    'ai_fact_payment' => ['text',
        'How customers pay — the AI repeats it verbatim; "omit" says nothing (unset = the South Sudan pay page, and a refusal to give bank details)'],
    'ai_fact_office' => ['text',
        'Where the office is and its hours (unset = the Juba office, South Sudan)'],
    'ai_fact_delivery' => ['text',
        'How kits reach the customer (unset = flown to Renk and across the Joda border into Sudan)'],
    'quote_company_name' => ['text',
        'Company name on quotations (unset = "DishNet Africa")'],
    'quote_company_phone' => ['text',
        'Phone printed on quotations (unset = +211920000000, South Sudan)'],
    'quote_company_email' => ['text',
        'Reply address on quotations (unset = info@dishnetafrica.com)'],
    // 5.18.29: the installation times in the WhatsApp a customer gets when the
    // KYC form puts them into uCRM. Unset keeps the South Sudan lines — Fiber,
    // Starlink and DishNet 4G, each with its days — which is what an install
    // selling Starlink alone must not send.
    'kyc_welcome_timeline' => ['text',
        'Installation times in the KYC booking confirmation, sent as written ("omit" = none; '
      . 'unset = the South Sudan Fiber, Starlink and DishNet 4G lines)'],
    // 5.18.30: which WhatsApp messages a customer the KYC form puts into uCRM
    // gets. Unset keeps the plugin's own — "Request Confirmed!" at once, the
    // proforma quotation three minutes later — which is what South Sudan
    // sends. On, exactly what a customer created in uCRM gets: "Welcome to
    // DishNet!", then the Quotation & Order Summary with the quotation PDF.
    'kyc_messages_like_crm' => ['bool',
        'KYC customers get the WhatsApp a customer created in uCRM gets: "Welcome to DishNet!", then the '
      . 'Quotation & Order Summary with its PDF (unset = the plugin\'s "Request Confirmed!" and proforma)'],
    // 5.18.31: the Airtel Money Pay merchant ID customers pay (dial *185*9#).
    // Printed on the WhatsApp quotation summary and in the e-mails' "How to
    // pay"; unset prints nothing new. The assistant's answer is its own
    // setting, ai_fact_payment, and the website has its own pay page.
    'pay_airtel_merchant' => ['text',
        'Airtel Money merchant ID customers pay — digits only; shown on quotations and in e-mails '
      . 'with "dial *185*9#" (unset = not shown)'],
    // 5.18.54 (docs/46 row 25, S-3): the 07:00 jobs brief to each technician, on Uganda. Until 5.18.54 it died on its
    // first line and reached nobody; fixed, it goes every morning. 0 holds it back.
    'staff_jobs_brief' => ['bool',
        'The morning jobs brief to each technician with a verified uCRM link, 07:00 (Uganda; unset = on, 0 = off)'],
    // 5.18.54 (docs/46 row 18, D-8): a KYC quote asks uCRM to send it, which has uCRM e-mail it. Unset keeps that call,
    // in both countries. Who owns the quotation e-mail — uCRM or the plugin — is decision O6 (docs/45), taken after
    // uCRM's own notification settings are read; 0 is the switch for the day it is taken.
    'kyc_quote_send_via_crm' => ['bool',
        'A KYC quote asks uCRM to send it, so uCRM e-mails the quotation (unset = on, as always; 0 = it does not ask)'],
    // ── Customer Installation Authorisation (5.18.82, hardened in 5.18.83; docs/61 §3, docs/63, docs/64) — Uganda only ──
    // A Starlink installation job may not be started (Accept Job, GPS check-in) or completed (Complete Job, GPS check-out)
    // until the customer has accepted the Installation Terms and the charges on the secure page the request sends them.
    // Off by default: with it off the job workflow is byte for byte what it was. Switching it ON is its ACTIVATION: uCRM
    // is read first and the Starlink installation jobs in progress at that moment are recorded as exempt (D3) — if uCRM
    // cannot be read, nothing is saved. Every channel is off until set (5.18.83): install_auth_whatsapp for the WhatsApp
    // messages, the two e-mail keys (under the customer e-mails master switch) for the e-mails; a request that no
    // switched-on channel can carry is refused before anything is recorded.
    'install_auth_enabled' => ['bool',
        'Customer Installation Authorisation for Starlink installation jobs (Uganda): the customer must accept the terms and charges on a secure link before a technician can accept, check in to, check out of or complete the job (docs/63, docs/64). Turning it on first records the jobs already in progress (D3) and refuses if uCRM cannot be read. Off = the workflow as before'],
    'install_auth_job_titles' => ['text',
        'Which uCRM job titles are a Starlink installation: comma-separated, matched (case aside) on the title before " — <customer>" (default: Starlink Installation)'],
    'install_auth_whatsapp' => ['bool',
        'Send the authorisation messages by WhatsApp — the customer\'s request and confirmation, the technician\'s, the leaders\' alerts. Absent means OFF'],
    'install_auth_link_days' => ['number',
        'How many days the customer\'s authorisation link stays valid (default 14; 1 to 90), or until 3 days after the scheduled date if that is later'],
    'customer_email_install_auth_request' => ['bool',
        'The authorisation REQUEST e-mail to the customer (needs the customer e-mails master switch). Absent means OFF; set 1 to send it'],
    'customer_email_install_auth_confirmed' => ['bool',
        'The confirmation e-mail after the customer accepts (needs the master switch). Absent means OFF; set 1 to send it'],
    // ── The customer's WhatsApp when an installation is booked (5.18.84) — Uganda only ──
    // uCRM's job.add sent the customer the install_scheduled e-mail and nothing else, so a customer with no e-mail address
    // heard nothing while the technician was told. With this on the customer also gets a WhatsApp, to the first number on
    // their uCRM record: the e-mail's conditions (a client, an installation title, a date), once per job.
    'customer_wa_install_scheduled' => ['bool',
        'The customer\'s WhatsApp when an installation job is created with a date (Uganda): the date and time, the location, the technician, and for a Starlink installation under authorisation that the secure link follows. Sent whether or not the customer has an e-mail address. Absent means OFF'],
    // ── The WhatsApp channel registry (5.18.86, docs/65; multi-number Batch 1) — Uganda only ──
    // OFF, every number routes as it always has: three configuration keys, three departments. ON, the webhook, the
    // assistant, the follow-ups and the Inbox route by CHANNEL through wa_channels (migration 087):
    // a number the registry has switched off is refused, never routed to another, and a reply leaves only on the
    // number its message arrived on. The three department numbers stay configured where they are. Batch 1: dark.
    'multi_number_channels_enabled' => ['bool',
        'Route WhatsApp by channel through the channel registry (Uganda; docs/65): a switched-off number is refused, a reply leaves only on the number the customer wrote to. Absent means OFF — the three department numbers exactly as before'],
    // ── A salesperson's own number (5.18.89, docs/65 §AA) — Uganda only. The first two act only with the registry on. ──
    'wa_handover_copy_central' => ['bool',
        'On a salesperson\'s own number, a hand-over alerts the salesperson and sends a copy to the central alert number (D4, decided 07 Oct). Absent means ON; 0 = the salesperson alone (the central number still gets it when they have no phone on record)'],
    'wa_followups_on_owned_numbers' => ['bool',
        'Allow follow-ups on a salesperson\'s own number (docs/65 §AA item 6). Absent means OFF: the salesperson follows up personally, and the follow-up scan, drafts and sends leave those conversations alone'],
    'sales_own_leads_only' => ['bool',
        'A salesperson sees only their own leads (assigned to them, created by them, or on their call list today) on Sales → Leads, in the quote picker, the status change and the call log (Uganda, D7). Admins and anyone granted All Leads see everything. Absent means OFF: every lead visible to every salesperson, as before'],
];

$show = function () use ($root, $dataDir, $FLAGS) {
    $cfg = PluginConfig::load($root, $dataDir);
    echo "\n  AI SETTINGS\n\n";
    foreach ($FLAGS as $k => list($type, $what)) {
        $raw = $cfg[$k] ?? null;
        $set = !($raw === null || $raw === '');
        $note = '';

        if (!$set) {
            $shown = 'not set (default)';
            // Only the cooldown has a 1440 default. Saying so for every
            // numeric key printed "default: 1440 (24 hours)" directly above a
            // description reading "(default 30)" — the same screen stating two
            // different defaults for one key, which is worse than stating none.
            if ($type === 'minutes') $note = 'default: 1440 (24 hours)';
            if ($type === 'tz')      $note = 'running on ' . dn_tz_label([]);
        } elseif ($type === 'bool') {
            $shown = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'ON' : 'OFF';
        } elseif ($type === 'minutes') {
            // Shown as the number it is. 0 is not "off" — it is a decision
            // with a consequence, so it says the consequence.
            $n = is_numeric($raw) ? (int)$raw : null;
            if ($n === null)   { $shown = '"' . (string)$raw . '"'; $note = 'not a number — treated as the 1440 default'; }
            elseif ($n === 0)  { $shown = '0 minutes'; $note = '⚠ the AI NEVER stands down, even while a colleague is typing'; }
            else               { $shown = $n . ' minutes'; }
        } elseif ($type === 'tz') {
            // Never just echo the identifier. A zone name looks right long
            // after it has stopped meaning what the reader assumes, which is
            // the whole reason this key exists — so print what it resolves to.
            $raw = trim((string)$raw);
            if (!dn_tz_valid($raw)) {
                $shown = '"' . $raw . '"';
                $note  = '⚠ not a zone PHP knows — IGNORED, running on ' . dn_tz_label([]);
            } else {
                $shown = dn_tz_label(['timezone' => $raw]);
            }
        } elseif ($type === 'json') {
            $dec = json_decode((string)$raw, true);
            if (!is_array($dec)) {
                $shown = '"' . mb_strimwidth((string)$raw, 0, 40, '…') . '"';
                $note  = '⚠ not valid JSON — IGNORED, the defaults are in use';
            } else {
                // range(0, -1) is [0, -1], not [], so an empty array must be
                // settled first or it reads as a map and prints "0 categories".
                if ($dec === [] || array_keys($dec) === range(0, count($dec) - 1)) {
                    $shown = count($dec) . ' entries';
                    $note  = implode(', ', array_slice($dec, 0, 6)) . (count($dec) > 6 ? ', …' : '');
                } else {
                    // NOT $k: the outer loop over $FLAGS uses it, PHP does not
                    // scope a foreach variable, and the clobbered value then
                    // printed as the setting's own name — this listing showed
                    // the cashbook override as "Partner Remuneration", the last
                    // category in the JSON.
                    $cats = [];
                    foreach ($dec as $ck => $cv) $cats[] = $ck . ' (' . (is_array($cv) ? count($cv) : '?') . ')';
                    $shown = count($dec) . ' categories overridden';
                    $note  = implode(', ', array_slice($cats, 0, 6)) . (count($cats) > 6 ? ', …' : '');
                }
            }
        } elseif ($type === 'secret') {
            // Never the value, not even a prefix: how many characters are set is all a listing needs to say.
            $shown = 'set (' . strlen((string)$raw) . ' characters) — not shown';
        } elseif ($type === 'number') {
            // A count, an hour figure, a limit. No unit appended and no
            // default invented — the description carries both.
            $shown = is_numeric($raw) ? (string)(int)$raw
                   : '"' . (string)$raw . '" — not a number, so the default applies';
        } else {
            $shown = '"' . (string)$raw . '"';
        }

        // 5.18.82: the name field is 40 wide (was 32; 27 before Phase 2) — customer_email_install_auth_confirmed is 37 characters.
        printf("    %-40s %s\n", $k, $shown);
        if ($note !== '') printf("    %-40s %s\n", '', $note);
        printf("    %-40s %s\n\n", '', $what);
    }
};

$key = trim($value('--key'));
if ($key === '') {
    // With no arguments at all, listing IS the job. But arguments that were
    // MEANT to change something and did not must never exit 0: a command
    // like --set foo=bar printed this whole list and returned success,
    // which reads exactly like it worked. It did nothing.
    if ($args) {
        echo "\n  Nothing was changed — this tool did not understand:\n\n";
        echo "      " . implode(' ', $args) . "\n\n";
        echo "  It takes --key and --value (or --clear; a secret takes --generate):\n\n";
        echo "      php tools/set_config.php --key ai_qualification --value 1\n";
        echo "      php tools/set_config.php --key ai_qualification --clear\n\n";
        echo "  Run it with no arguments to see the settings it manages. Anything\n";
        echo "  outside that list — every Evolution, uCRM or mail setting, and\n";
        echo "  every secret — belongs on the uCRM Configuration screen.\n\n";
        exit(1);
    }
    $show();
    echo "    php tools/set_config.php --key ai_qualification --value 1\n";
    echo "    php tools/set_config.php --key ai_qualification --clear\n\n";
    exit(0);
}

if (!array_key_exists($key, $FLAGS)) {
    echo "\n  \"" . $key . "\" is not one of the settings this tool manages.\n\n";
    echo "  It manages:\n";
    foreach (array_keys($FLAGS) as $k) echo "      " . $k . "\n";
    echo "\n  Anything else — and every secret — belongs on the uCRM\n";
    echo "  Configuration screen, where it is stored encrypted.\n\n";
    exit(1);
}

$clear    = in_array('--clear', $args, true);
$generate = in_array('--generate', $args, true);
$isSecret = ($FLAGS[$key][0] ?? '') === 'secret';
if ($isSecret && in_array('--value', $args, true)) {
    // 5.18.74: a secret is never typed into a shell — the command line lands in root's history and in this terminal's
    // scrollback. Nothing was saved.
    echo "\n  " . $key . " is a secret and is never typed: use --generate (a random value, stored, never shown)\n";
    echo "  or --clear. Nothing was saved.\n\n";
    exit(1);
}
if ($generate && !$isSecret) {
    echo "\n  --generate is only for a secret (" . implode(', ', array_keys(array_filter($FLAGS, function ($f) { return $f[0] === 'secret'; }))) . "). Nothing was saved.\n\n";
    exit(1);
}
if (!$clear && !$generate && !in_array('--value', $args, true)) {
    if ($isSecret) echo "\n  Give --generate to create and store a random value (never shown), or --clear to remove it.\n\n";
    else           echo "\n  Give --value <v>, or --clear to return it to the default.\n\n";
    exit(1);
}
if ($generate && !$clear) {
    // Refuse to overwrite one that is set: rotating it silently would break a uCRM webhook configured with the old
    // value, and the hand-off works with whatever both plugins read. Clearing first is the explicit two-step rotation.
    $cur    = PluginConfig::read($root, $dataDir);   // the read-only path: this check must change nothing on disk
    $curVal = trim((string)($cur[$key] ?? ''));
    if ($curVal === '' && is_file(rtrim($dataDir, '/') . '/plugin.sqlite3')) {
        // The web requests read the STORE row (public.php: $config = $store->load('kyc_config.json')), so a value that
        // lives only there counts as set too. Never create a store here (see PluginConfig::mirrorToStore).
        try {
            require_once $root . '/lib/StoreInterface.php'; require_once $root . '/lib/JsonStore.php'; require_once $root . '/lib/SqliteStore.php';
            $row = SqliteStore::create($dataDir)->load('kyc_config.json');
            $curVal = trim((string)((is_array($row) ? $row : [])[$key] ?? ''));
        } catch (\Throwable $e) { /* unreadable store: the file answer stands */ }
    }
    if ($curVal !== '') {
        echo "\n  " . $key . " is already set (" . strlen($curVal) . " characters) — nothing was changed.\n";
        echo "  To rotate it: --clear first, then --generate. Settings → Webhook Secret shows the current value.\n\n";
        exit(1);
    }
}
$new = $clear ? '' : ($generate ? bin2hex(random_bytes(16)) : $value('--value'));

// Some of these are not flags — they are text a customer reads, verbatim.
// Saying so at the moment of setting is the only time anyone is looking.
// A refusal, not a warning. Every other key here is text somebody reads: a
// poor value looks poor and gets fixed. A misspelt zone is invisible — it is
// silently ignored and the box keeps running on the Africa/Juba default, an
// hour off Kampala, with every cron, report and follow-up window quietly
// wrong and nothing anywhere saying so.
// A template pasted straight through, placeholders and all. The example in
// the 5.18.14 deploy notes used <BANK> and <NUMBER>; it was pasted verbatim
// and the assistant began telling customers to pay into "account <NUMBER>",
// which is worse than the refusal it replaced. Angle-bracketed capitals are
// never a value a customer should read, so this is a refusal, not a warning.
if (!$clear && preg_match('/<[A-Z][A-Z0-9 _-]{1,30}>/', $new, $ph)) {
    echo "\n  That still has the example placeholder " . $ph[0] . " in it, so nothing was saved.\n\n";
    echo "  Customers would have read it exactly as typed. Replace every <...> with the\n";
    echo "  real value and run it again, or use --clear to leave the setting unset.\n\n";
    exit(1);
}

// The profile selector names a shipped profile or nothing: a misspelt value
// would silently fall back to the currency rule and put the wrong country on
// the login page with nothing anywhere saying so.
if (!$clear && $key === 'tenant_profile') {
    require_once dirname(__DIR__) . '/lib/TenantProfile.php';
    if (!in_array(strtolower(trim($new)), TenantProfile::IDS, true)) {
        echo "\n  \"" . $new . "\" is not a shipped profile, so nothing was saved.\n";
        echo "  Use one of: " . implode(', ', TenantProfile::IDS) . " — or --clear to derive it from the currency.\n\n";
        exit(1);
    }
    $new = strtolower(trim($new));
}
// 5.18.73: yes or no, nothing else — a value like "true" would read as no at the portal and nothing would say so.
if (!$clear && $key === 'portal_data_report_handoff') {
    if (!in_array(strtolower(trim($new)), ['yes', 'no'], true)) {
        echo "\n  \"" . $new . "\" is not yes or no, so nothing was saved. Use --clear to follow the country profile.\n\n";
        exit(1);
    }
    $new = strtolower(trim($new));
}
// 5.18.82 (docs/63): the customer authorisation link's life in days, 1 to 90 — refused outside the range, never clamped silently.
require_once dirname(__DIR__) . '/lib/InstallAuth.php';
if (!$clear && $key === InstallAuth::LINK_DAYS_KEY) {
    if (!preg_match('/^\d+$/', trim($new)) || (int)$new < InstallAuth::LINK_DAYS_MIN || (int)$new > InstallAuth::LINK_DAYS_MAX) {
        echo "\n  \"" . $new . "\" is not a whole number between " . InstallAuth::LINK_DAYS_MIN . " and " . InstallAuth::LINK_DAYS_MAX . " days, so nothing was saved.\n\n";
        exit(1);
    }
    $new = (string)(int)$new;
}
// 5.18.83 (docs/64 §A.3): the installation job titles — a list of real titles, each at most 80 characters.
if (!$clear && $key === InstallAuth::TITLES_KEY) {
    $items = array_values(array_filter(array_map(function ($t) { return trim((string)preg_replace('/\s+/u', ' ', $t)); }, preg_split('/[,\n]+/', $new) ?: []),
        function ($t) { return $t !== ''; }));
    $long  = array_filter($items, function ($t) { return mb_strlen($t) > 80; });
    if ($items === [] || $long !== []) {
        echo "\n  Give one or more job titles, separated by commas, each at most 80 characters — for example:\n";
        echo "      --value \"Starlink Installation, Starlink Kit Installation\"\n  Nothing was saved.\n\n";
        exit(1);
    }
    $new = implode(', ', $items);
}
if (!$clear && $key === 'timezone' && trim($new) !== '' && !dn_tz_valid(trim($new))) {    echo "\n  \"" . trim($new) . "\" is not a timezone PHP recognises, so nothing was saved.\n\n";
    echo "  Had it saved, the box would have gone on running as " . dn_tz_label([]) . "\n";
    echo "  with no error anywhere.\n\n";
    echo "  Uganda:      Africa/Kampala\n";
    echo "  South Sudan: Africa/Juba\n\n";
    exit(1);
}

if (!$clear && in_array($key, ['cashbook_seeds', 'cashbook_sites'], true) && trim($new) !== ''
    && !is_array(json_decode(trim($new), true))) {
    echo "\n  That is not valid JSON, so nothing was saved.\n\n";
    if ($key === 'cashbook_seeds') {
        echo "  It takes a category-to-names object, for example:\n\n";
        echo "      '{\"Airtime\":[\"MTN\",\"Airtel\"],\"Salary\":[]}'\n\n";
        echo "  An empty list means that category offers no suggestions at all.\n\n";
    } else {
        echo "  It takes a list of site names, for example:\n\n";
        echo "      '[\"Kampala Office\",\"Ntinda Tower\"]'\n\n";
        echo "  An empty list starts blank and fills from real cashbook history.\n\n";
    }
    exit(1);
}

$warn = [];
if (!$clear) {
    if ($key === 'ai_currency' && $new !== '' && $new !== mb_strtoupper($new)) {
        $warn[] = 'This is printed next to every price exactly as typed, so prices will '
                . 'read "' . $new . ' 329,000". Your flyer says "' . mb_strtoupper($new) . '".';
    }
    if ($key === 'stock_statement' && $new !== ''
        && in_array(mb_strtolower(trim($new)), ['yes', 'no', 'y', 'n', 'ok', 'true', 'false'], true)) {
        $warn[] = 'The assistant is told to answer stock questions from this line directly '
                . 'and confidently. As "' . $new . '" that is thin — a sentence works better, '
                . 'for example: "Standard and Mini kits are in stock in Kampala."';
    }
    if ($key === 'wa_human_cooldown_minutes' && $new !== '' && is_numeric($new) && (int)$new === 0) {
        $warn[] = '0 means the AI NEVER stands down. It will keep answering while a colleague '
                . 'is typing, which is what produced ninety-seven messages on c109.';
    }
    if ($key === 'quote_company_phone' && $new !== ''
        && strpos(preg_replace('/\D+/', '', $new) ?? '', '211') === 0) {
        $warn[] = 'That is a South Sudan number. It is printed on quotations sent to '
                . 'Ugandan customers as the number to call.';
    }
    if ($key === 'ai_fact_location_pin' && $new !== ''
        && !preg_match('#^https?://#i', $new)) {
        $warn[] = 'That is not a URL. It is sent to customers exactly as typed.';
    }
    if ($key === 'timezone' && trim($new) !== '') {
        $warn[] = 'Now running as ' . dn_tz_label(['timezone' => trim($new)])
                . '. Crons, reports, the cashbook day boundary and the 08:00-20:00 '
                . 'follow-up window all move with it.';
    }
    if ($key === 'ai_fact_payment' && $new !== '' && strtolower($new) !== 'omit'
        && preg_match('/\d[\d\s-]{6,}\d/', $new)) {
        $warn[] = 'That looks like an account or till number. The AI repeats it to customers '
                . 'character for character, and the reply guard refuses any figure that is not '
                . 'in this text — so a typo here is a customer paying into nothing, and a digit '
                . 'changed later without changing this is a customer paying into the old one. '
                . 'Read it back against the bank statement before you leave the terminal.';
    }
    if (in_array($key, ['ai_fact_office', 'ai_fact_delivery'], true) && $new !== ''
        && preg_match('/\b(juba|sudan|renk|joda)\b/i', $new)) {
        $warn[] = 'That names a South Sudan place. This box answers Ugandan customers.';
    }
    if ($key === 'ai_fact_business_cap' && $new !== ''
        && preg_match('/\b\d{1,3}[, ]\d{3}\b|UGX|shillings?/i', $new)) {
        $warn[] = 'That looks like a price. Prices come from uCRM so they stay current — a figure '
                . 'here becomes a second catalogue that goes stale silently.';
    }
    if (in_array($key, ['ai_fact_prices', 'ai_fact_kit_taxes'], true) && $new !== '' && preg_match('/\d/', $new)) {
        $warn[] = 'This is repeated to customers as a fact. A figure in it (a rate, an amount) '
                . 'will be repeated too — make sure it is exactly right and stays right.';
    }
    if ($key === 'ai_handover_message' && mb_strlen($new) > 160) {
        $warn[] = 'That is long for a holding line on WhatsApp. It is sent on its own, before '
                . 'a person arrives.';
    }
}

// 5.18.83 (docs/64 §A.4): switching Customer Installation Authorisation ON is its activation (D3, explicit). uCRM is read
// FIRST and the Starlink installation jobs in progress at this moment are recorded as exempt — those, and only those, may
// be completed without the customer's acceptance. If uCRM cannot be read, or the plugin's database does not exist yet,
// nothing is saved. Already on and activated: nothing changes (switch it off and on again for a new snapshot).
$iaActivation = null;
if (!$clear && $key === InstallAuth::FLAG && InstallAuth::flagOn([InstallAuth::FLAG => $new])) {
    $iaCur = PluginConfig::load($root, $dataDir);
    require_once $root . '/lib/StaffJobsGate.php';
    if (StaffJobsGate::applies([InstallAuth::FLAG => '1'] + $iaCur, $dataDir)) {
        if (!is_file(rtrim($dataDir, '/') . '/plugin.sqlite3')) {
            echo "\n  The plugin's database does not exist yet (open the plugin in uCRM once), so the jobs in progress\n";
            echo "  cannot be recorded. Nothing was saved.\n\n";
            exit(1);
        }
        require_once $root . '/lib/StoreInterface.php'; require_once $root . '/lib/JsonStore.php'; require_once $root . '/lib/SqliteStore.php';
        require_once $root . '/lib/CrmApiClient.php';
        $iaPdo  = SqliteStore::create($dataDir)->getPdo();
        $iaLast = InstallAuth::lastActivation($iaPdo);
        if (InstallAuth::flagOn($iaCur) && $iaLast !== null) {
            echo "\n  " . $key . " is already on (activated " . $iaLast['activated_at'] . " UTC, #" . $iaLast['id'] . ") — nothing was changed.\n";
            echo "  For a new snapshot of the jobs in progress, switch it off (--clear) and on again.\n\n";
            exit(0);
        }
        $iaWho = 'tools/set_config.php';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $iaPw = @posix_getpwuid(posix_geteuid());
            if (is_array($iaPw) && !empty($iaPw['name'])) $iaWho .= ' as ' . $iaPw['name'];
        }
        $iaActivation = InstallAuth::activate($iaPdo, CrmApiClient::fromUcrm($root, $iaCur), [InstallAuth::FLAG => '1'] + $iaCur, $iaWho);
        if (empty($iaActivation['ok'])) {
            echo "\n  " . (string)($iaActivation['error'] ?? 'The activation failed.') . "\n\n";
            exit(1);
        }
    }
}

list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }
if (is_array($iaActivation)) {
    echo "\n  Activation #" . (int)$iaActivation['activation_id'] . ": uCRM had " . (int)$iaActivation['jobs_read'] . " job(s) in progress; "
       . (int)$iaActivation['in_scope'] . " of them Starlink installation job(s), " . (int)$iaActivation['exempted'] . " newly recorded as exempt (D3)"
       . ($iaActivation['job_ids'] ? ': job ' . implode(', ', array_map('intval', $iaActivation['job_ids'])) : '') . ".\n";
    echo "  Every other Starlink installation job now needs the customer's acceptance before it starts or is completed.\n";
    $iaNow = PluginConfig::load($root, $dataDir);
    if (!InstallAuth::whatsappOn($iaNow) && !InstallAuth::emailOn('request', $iaNow)) {
        echo "  Note: no channel is switched on yet (install_auth_whatsapp, customer_email_install_auth_request), so a request\n";
        echo "  will be refused until one is.\n";
    }
}

foreach ($warn as $w) echo "\n  Note: " . $w . "\n";

if ($clear)          echo "\n  " . $key . " cleared — back to the default.\n";
elseif ($isSecret)   echo "\n  " . $key . " generated and stored (" . strlen($new) . " characters). It is not shown here; Settings → Webhook Secret has a Copy button if uCRM's webhook needs it.\n";
else                 echo "\n  " . $key . " = " . $new . "\n";
$show();
exit(0);
