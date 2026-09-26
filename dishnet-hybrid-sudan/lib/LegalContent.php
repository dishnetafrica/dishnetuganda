<?php
/**
 * DishNet Customer Legal Documents
 * v4.12.19 — Terms of Service and Privacy Policy content.
 *
 * Document version is tracked in dnLegalVersion() — bump when wording changes
 * materially. Consent table stores (phone, tos_version, privacy_version,
 * accepted_at, ip) so we can tell who has accepted which version and re-prompt
 * only when the version bumps.
 *
 * Pages are public (no auth) so any customer can review them before signing up.
 * Accessible at ?page=terms and ?page=privacy.
 *
 * 5.18.41 (docs/38 A1.1): the CONTACT lines read the tenant profile (WhatsApp, e-mail).
 *
 * 5.18.42 (docs/38 change set A2): the documents are TEMPLATES over the tenant profile.
 * Every sentence that names a country, a company, a product line, a fee, a court or a
 * regulator is composed from the profile's `legal` block and its other facts; this file
 * carries no tenant's wording of its own. The version and date are the tenant's too
 * (`legal.version`, `legal.dated`), so a wording change for one tenant re-asks only that
 * tenant's customers. South Sudan's profile holds exactly the text this file carried
 * before, and tests/test_portal_tenant.php pins the rendering byte for byte. Where a
 * profile does not answer, the sentence falls back to a NEUTRAL fact-derived form
 * (the country's name, "internet services") — never to another tenant's wording.
 */

declare(strict_types=1);

require_once __DIR__ . '/TenantProfile.php';

/**
 * The tenant's document versions and effective date. Stored with every consent
 * record and compared on every sign-in — so the profile the caller passes must be
 * the one the request is served under (the parameter is deliberately required).
 */
function dnLegalVersion(TenantProfile $tp): array {
    return [
        'tos'     => $tp->text('legal.version.tos', '1.0'),
        'privacy' => $tp->text('legal.version.privacy', '1.0'),
        'dated'   => $tp->text('legal.dated', '18 April 2026'),
    ];
}

/** The profile's `legal` facts, each with its neutral fallback. @internal */
function dnLegalFacts(TenantProfile $tp): array {
    $country = $tp->countryName() !== '' ? $tp->countryName() : 'the country of registration';
    $city    = $tp->text('office.city', '');
    $courts  = $tp->text('jurisdiction.courts', '');
    return [
        'entity'     => $tp->legalEntity() !== '' ? $tp->legalEntity() : 'DishNet Africa',
        'identity'   => $tp->text('legal.identity', 'a company registered in ' . $country),
        'services'   => $tp->text('legal.services', 'internet services'),
        'area'       => $tp->text('legal.service_area', 'customers in ' . ($city !== '' ? $city . ' and across ' : '') . $country),
        'upstreams'  => $tp->text('legal.upstreams', 'SpaceX/Starlink and other upstream providers'),
        'aup'        => $tp->text('legal.acceptable_use_policies', "our upstream providers' acceptable use policies"),
        'equipment'  => $tp->text('legal.equipment', 'Starlink dishes, routers and other terminals'),
        'fees'       => is_array($tp->get('legal.fees')) ? $tp->get('legal.fees') : null,
        'transfer'   => is_array($tp->get('legal.transfer')) ? $tp->get('legal.transfer') : null,
        'law'        => $tp->text('jurisdiction.law', $country),
        'forum'      => $tp->text('legal.forum', 'the courts of ' . ($courts !== '' ? $courts : $country)),
        'partners'   => (string)$tp->get('legal.sharing_partners', ''),
        'regulators' => $tp->text('legal.regulators_phrase', $country . ' regulatory authorities'),
        'sign_in_heading' => $tp->text('legal.sign_in_heading', 'WhatsApp and login codes'),
        'sign_in'    => $tp->text('legal.sign_in',
            "We sign you into the DishNet app by sending a six-digit code to your WhatsApp " .
            "number. That means your phone number travels through Meta's WhatsApp Business " .
            "infrastructure, subject to Meta's own privacy terms. We never ask for your " .
            "WhatsApp password — only your phone number so we can route the code to you."),
    ];
}

/**
 * Terms of Service — deliberately short and specific to DishNet.
 * Returns an array of sections: [['heading' => ..., 'body' => ...], ...]
 * so the render layer can style them without editing HTML here.
 */
function dnTermsContent(TenantProfile $tp): array {
    $wa    = TenantProfile::formatWa($tp->contact('support_wa', '211921443002'));
    $email = $tp->email() ?: 'info@dishnetafrica.com';
    $f     = dnLegalFacts($tp);
    $fees  = $f['fees'];
    $tr    = $f['transfer'];
    $billing = $fees !== null
        ? "Service is billed monthly and due on the invoice date shown on each bill. " .
          "If payment is not received within " . (string)($fees['late_days'] ?? '7') . " days of the due date, a late fee of " . (string)($fees['late_fee'] ?? '5%') . " of " .
          "the outstanding balance will be added. Continued non-payment may result in " .
          "suspension without further notice. Reconnection after suspension costs " . (string)($fees['reconnection'] ?? 'the reconnection charge on your invoice') . ". " .
          "Cheques should be made payable to \"" . (string)($fees['cheque_payee'] ?? $f['entity']) . "\". We accept " . (string)($fees['methods'] ?? 'bank transfers and mobile money') . "."
        // No confirmed figures for this tenant (docs/38 §7.3 point 7): state none, point to the invoice.
        : "Service is billed as shown on each invoice and is due on the due date it states. " .
          "Late-payment and reconnection charges, where they apply, are those stated on your " .
          "quotation or invoice. Continued non-payment may result in suspension without further " .
          "notice. We accept the payment methods shown in the app and on your invoice.";
    $transfers = $tr !== null
        ? "Starlink accounts managed on your behalf by DishNet may be transferred to you " .
          "after a minimum " . (string)($tr['min_period'] ?? '6-month') . " service period, subject to a " . (string)($tr['fee'] ?? 'transfer') . " transfer fee and a " .
          (string)($tr['lead_time'] ?? '120-day') . " lead time for administrative processing. Transfer terms are governed by " .
          "SpaceX/Starlink policy and may change without notice."
        : "Starlink accounts managed on your behalf by DishNet may be transferred to you on " .
          "request. Any minimum service period, transfer fee and processing time are confirmed " .
          "to you in writing before a transfer. Transfer terms are governed by SpaceX/Starlink " .
          "policy and may change without notice.";
    return [
        [
            'heading' => 'About these Terms',
            'body'    =>
                "{$f['entity']} (\"DishNet\", \"we\", \"us\") is {$f['identity']}, " .
                "providing {$f['services']} to {$f['area']}. These Terms of Service govern your use of " .
                "our internet services, customer portal, mobile app, and related tools. By signing in " .
                "to the DishNet app or using any DishNet service, you agree to these Terms.",
        ],
        [
            'heading' => 'Our service',
            'body'    =>
                "We provide internet connectivity on a best-effort basis. Speeds, latency, and " .
                "uptime depend on upstream providers ({$f['upstreams']}) " .
                "and on conditions we do not fully control — including weather, cable cuts, regulatory " .
                "actions, and power availability. We commit to restoring service as quickly as " .
                "reasonably possible when it is disrupted.",
        ],
        [
            'heading' => 'Billing and payment',
            'body'    => $billing,
        ],
        [
            'heading' => 'Acceptable use',
            'body'    =>
                "You agree not to use DishNet service for illegal activity, to send unsolicited " .
                "bulk communication (spam), to attack or probe other networks without authorisation, " .
                "or to host commercial services that violate {$f['aup']}. You agree not to resell DishNet connectivity " .
                "without a written commercial agreement with us. We reserve the right to suspend " .
                "service for abuse that threatens the network or other customers.",
        ],
        [
            'heading' => 'Equipment',
            'body'    =>
                "On leased-kit plans, hardware ({$f['equipment']}) " .
                "remains the property of DishNet throughout the life of the service. You must not " .
                "sell, lend, transfer, or modify the equipment. If equipment is lost, stolen, or " .
                "damaged through misuse, you are responsible for replacement costs. On purchase " .
                "plans, the equipment is yours after full payment.",
        ],
        [
            'heading' => 'Starlink transfers',
            'body'    => $transfers,
        ],
        [
            'heading' => 'Termination',
            'body'    =>
                "You may end your service at any time by contacting DishNet support. We may end " .
                "service for unpaid bills, prohibited use, or repeated violations of these Terms. " .
                "On termination, outstanding balances remain due and leased equipment must be " .
                "returned within 14 days.",
        ],
        [
            'heading' => 'Limitation of liability',
            'body'    =>
                "DishNet is not liable for business loss, lost revenue, or damages resulting from " .
                "service interruption, except where required by law. We will credit your account " .
                "for unplanned extended outages at our discretion. Our total liability for any " .
                "claim related to service is limited to the fees you paid us in the three months " .
                "preceding the event.",
        ],
        [
            'heading' => 'Changes to these Terms',
            'body'    =>
                "We may update these Terms as our services evolve. When we make a material change, " .
                "you will be asked to accept the new Terms the next time you sign in to the app. " .
                "Continuing to use DishNet service after accepting new Terms means you agree to " .
                "the updated version.",
        ],
        [
            'heading' => 'Jurisdiction',
            'body'    =>
                "These Terms are governed by the laws of {$f['law']}. " .
                "Any dispute that cannot be resolved between you and DishNet will be submitted " .
                "to {$f['forum']}.",
        ],
        [
            'heading' => 'Contact',
            'body'    =>
                "Questions about these Terms? Reach us on WhatsApp at {$wa} or email " .
                "{$email}. We reply fastest on WhatsApp.",
        ],
    ];
}

/**
 * Privacy Policy — what we collect, why, who we share with.
 */
function dnPrivacyContent(TenantProfile $tp): array {
    $wa    = TenantProfile::formatWa($tp->contact('support_wa', '211921443002'));
    $email = $tp->email() ?: 'info@dishnetafrica.com';
    $f     = dnLegalFacts($tp);
    $partners = $f['partners'] !== '' ? rtrim($f['partners']) . ' ' : '';
    return [
        [
            'heading' => 'Who this applies to',
            'body'    =>
                "This Privacy Policy explains how {$f['entity']} handles information about " .
                "you when you use our internet services, customer portal, or mobile app. It applies " .
                "to every DishNet customer and anyone who contacts us about becoming a customer.",
        ],
        [
            'heading' => 'What we collect',
            'body'    =>
                "Account information you give us: your name, phone number, email, physical address, " .
                "and the services you subscribe to. Usage information our systems record automatically: " .
                "how much data your connection uses, which services are active, your invoice and payment " .
                "history, network performance at your site, and WiFi configuration (network name, " .
                "encryption type — but not your password in plain text, that stays on your router). " .
                "Device information shown in the Connected Devices view: the MAC addresses of devices " .
                "currently connected to your WiFi, their IP addresses, and link speed.",
        ],
        [
            'heading' => 'How we use it',
            'body'    =>
                "We use this information to provide your service, bill you correctly, send you " .
                "invoices and receipts on WhatsApp, diagnose technical issues, and comply with " .
                "legal and tax obligations. We do not use your information to target you with " .
                "third-party advertising. We do not sell your information.",
        ],
        [
            'heading' => $f['sign_in_heading'],
            'body'    => $f['sign_in'],
        ],
        [
            'heading' => 'Who we share with',
            'body'    =>
                "Starlink (SpaceX) — for Starlink service customers, we share account and kit " .
                "information required to provision service on their network. {$partners}" .
                "Payment processors and our accountants — for invoicing and tax records. {$f['regulators']} — if required by law, such as for tax audits or lawful " .
                "investigation. We do not share your data with advertisers, data brokers, or " .
                "anyone else without your permission.",
        ],
        [
            'heading' => 'How long we keep it',
            'body'    =>
                "For as long as you are a DishNet customer, plus up to 3 additional years after " .
                "you leave, primarily to meet financial record-keeping requirements. Technical " .
                "data (connection logs, device lists) is retained for a shorter period — typically " .
                "90 to 365 days — unless needed for an active support case.",
        ],
        [
            'heading' => 'Your rights',
            'body'    =>
                "You can request a copy of the information we hold about you. You can ask us to " .
                "correct information that is wrong. You can ask us to delete your information when " .
                "you leave DishNet, subject to the financial record-keeping obligation above. " .
                "To exercise any of these rights, contact {$email} with your " .
                "DishNet account number.",
        ],
        [
            'heading' => 'Security',
            'body'    =>
                "We protect your information with industry-standard safeguards: encrypted " .
                "connections to our portal, hashed authentication tokens, rate-limited login, " .
                "and limited internal access on a need-to-know basis. No system is perfectly " .
                "secure — if a breach ever affects your data, we will notify you without undue " .
                "delay and explain what happened.",
        ],
        [
            'heading' => 'Children',
            'body'    =>
                "DishNet service is intended for adult customers. We do not knowingly collect " .
                "information from anyone under the age of 18. If you believe a minor has signed " .
                "up for service under their own name, please contact us so we can review the account.",
        ],
        [
            'heading' => 'Changes to this Policy',
            'body'    =>
                "We will post any material changes to this Policy on this page and ask you to " .
                "accept the updated version the next time you sign in to the app. The current " .
                "version and date appear at the top of this page.",
        ],
        [
            'heading' => 'Contact',
            'body'    =>
                "Questions about your privacy? WhatsApp {$wa} or email " .
                "{$email} — we'll respond within a few business days.",
        ],
    ];
}
