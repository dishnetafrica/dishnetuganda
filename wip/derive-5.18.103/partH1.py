# Part H1 — the header, first half: the title, what 5.18.103 does, the cash sheet, the copies, GETs only, 5.18.103's own
# checks (QC), 5.18.102's carried (QL), and the rollback. Root docs/72 §G.


def apply(doc, ctx):
    na, nc, nt = ctx["n_added"], ctx["n_changed"], ctx["n_added"] + ctx["n_changed"]
    doc.lines_between("# deploy-5.18.102.sh — on Uganda the plugin's KYC quotations take their lines and prices from uCRM's Products and",
                      "# docs/71, 27 Oct, as D14 (§R) builds it — over 5.18.101; or roll it back to 5.18.101.",
        "# deploy-5.18.103.sh — on Uganda the uCRM catalogue: a new screen (lib/UcrmCatalogue.php, tabs/sales/ucrm_catalogue.php)\n"
        "# whose switch, once an admin turns it on, makes the KYC form, Send Quotation and the lead quotes offer uCRM's own service\n"
        "# plans and products, by uCRM id, at uCRM's prices, the plugin's own plan and kit lists hidden, a standard quotation in\n"
        "# one click — plugin 5.18.103, Uganda; root docs/72 §G, 1 Nov — over 5.18.102; or roll it back to 5.18.102.", why="H title")
    doc.lines_between("#   5.18.102 Each plan, kit and the installation is priced through the uCRM item an admin linked it to on the Plans and",
                      "#            the uCRM links an admin makes, lib/UcrmQuoteLinks.php, among them).",
        "#   5.18.103 A new screen, the uCRM catalogue (Uganda; in the menu for those who may see Plans or Hardware, its settings an\n"
        "#            admin's), lists what uCRM's Products and Service Plans offer — each plan pair (a service plan with the one\n"
        "#            product spelled like it, G-4), each hardware item (a counted product that passes the release's rules and is\n"
        "#            not spelled like a plan, G-5) and the installation product an admin sets (G-6) — and why every other item is\n"
        "#            not offered; there an admin keeps products off the forms and saves up to ten standard quotations. Its switch\n"
        "#            is OFF until an admin turns it on (G-1): with it off, 5.18.103 behaves exactly as 5.18.102 — the KYC\n"
        "#            quotations through the uCRM link an admin records for each plan, kit and the installation (docs/71 §R). With\n"
        "#            it on, the KYC form, Send Quotation and the lead quotes offer uCRM's items by uCRM id at uCRM's prices, a\n"
        "#            standard quotation fills the KYC form in one click, and the plugin's Plans and Hardware leave the menu, their\n"
        "#            link pickers refusing (the link store frozen); a direct sale stores its uCRM items (quote_sale_entry\n"
        "#            web-direct, quote_item_ids; package_choice, device_id and the cart's ids blanked, G-7) and is priced and quoted\n"
        "#            from a fresh uCRM read — a Cash sale collects exactly the quotation's total, and is refused when the sale\n"
        "#            cannot be priced or an item changed since the form read it; an unpriced Credit one is registered with no\n"
        "#            quotation (P1) and the agent told — and Send Quotation and the lead quotes re-price every picked line from uCRM\n"
        "#            at the send, inside QuotationService, the mobile API's sends included (G-15). A web registration of 5.18.102\n"
        "#            left unpriced is quoted in uCRM by hand once the switch is on (G-10). The retailer app and the mobile API keep\n"
        "#            the plugin's own lists (§G.11). The switch and the settings live in the store's ucrm_catalogue table, made by\n"
        "#            the first save — no migration, no setting key. All of it under 5.18.102's gate,\n"
        "#            QuoteOrganization::setting(...)['on']: never on South Sudan, whose requests, texts, pages and store stay byte\n"
        "#            for byte 5.18.102's, nor on Uganda with ucrm_quote_organization_id 0/off. webhook.php, cron_quote_wa.php,\n"
        "#            api/index.php, QuoteOrganization, PublicPriceFeed, prices.php, shop.php, tools/set_config.php and\n"
        "#            UcrmQuoteLinks byte for byte 5.18.102's, and so are the brain, the leads, the follow-ups, the Inbox, the\n"
        "#            channel registry and its owners, the Cashbook, ECO Bank and every Balance. Files, against 1a0ea5f:\n"
        f"#            {nc} changed, {na} added — {nt} (the catalogue's class and screen, and its test tests/test_catalogue_direct.php\n"
        "#            with its directory tests/catalogue_direct/, are the added ones).", why="H release")
    # the cash sheet
    doc.sub("#            /root/dnb-5.18.102/cash-<mode>-<UTC stamp>.txt, mode 0600,", "#            /root/dnb-5.18.103/cash-<mode>-<UTC stamp>.txt, mode 0600,")
    doc.lines_between("#            (QL-G1, QL-CA1, QL-LK1, QL-N7) — each plan's and kit's row id and name, and the installation's, with its uCRM",
                      "#            numbers uCRM gives its products; and the window's application ids, never a name (QL-LK1). The terminal and",
        "#            (QL-G1, QL-N7) — each plan's and kit's row id and name, and the installation's, with its uCRM link record and\n"
        "#            the uCRM product (and service plan) it names by id, its unit and the resolver's reason, which can carry a\n"
        "#            price; per item, under \"uCRM items with the same spelling — a hint only\", the uCRM items spelled like it, by\n"
        "#            id and name, each with whether it could be linked or the rule that stops it; the organization numbers uCRM\n"
        "#            gives its products; what the forms would offer from uCRM in direct mode (QC-G1, QC-CA1) — each plan pair's\n"
        "#            and hardware item's uCRM ids, name, price and unit, the installation's, every other item with its reason —\n"
        "#            the uCRM catalogue's settings on the copy by record id, uCRM id and name, the waiting web applications' ids\n"
        "#            (QC-N4) and, after a rollback, the web-direct applications' ids to quote by hand (QC-RB2). The terminal and",
        why="H sheet items")
    doc.sub("#            owner checks (TN) counts and role names only; the sign-off checks (QS) counts of lines of code only. Read\n",
            "#            uCRM catalogue checks (QC) counts by the release's why codes, read states and HTTP codes; the owner checks\n"
            "#            (TN) counts and role names only; the sign-off checks (QS) counts of lines of code only. Read\n")
    # on copies
    doc.sub("#            page cache (ucrm_quote_catalogue_cache.json) into the data folder it is given. The owner checks (TN-G2,\n",
            "#            page cache (ucrm_quote_catalogue_cache.json) into the data folder it is given. 5.18.103's checks (QC) read the\n"
            "#            same way: the RELEASE's lib/UcrmCatalogue.php required by its own path (the pin's before the copy, the\n"
            "#            installed after it), its records read on the copy only (UcrmCatalogue::fromStore, which never creates the\n"
            "#            table), and UcrmQuoteLines::offered() on the clients built here over UcrmCatalogue::fromRecords — never\n"
            "#            forDirectPage(), which writes the page cache and the last good copy into its data folder, nor\n"
            "#            forDirectUse(), whose quoteClient() returns a client nothing wraps. The owner checks (TN-G2,\n")
    # GETs only
    doc.sub("#            service-plans?limit=500, and nothing else — the check QL-G1 and QL-CA1 make — and, after it, the script's own\n",
            "#            service-plans?limit=500, and nothing else — the check QL-G1 makes, and QC-G1 and QC-CA1 through offered() —\n"
            "#            and, after QL-G1's, the script's own\n")
    doc.sub("(QL-N1). The run's count of other requests must be 0 (Q-G4, Q-CA3, QL-G1, QL-CA1, QL-LK1). Nothing is ever",
            "(QL-N1). The run's count of other requests must be 0 (Q-G4, Q-CA3, QL-G1, QC-G1, QC-CA1). Nothing is ever")
    # 5.18.103's own checks, 5.18.102's carried
    doc.lines_between("#   5.18.102'S OWN CHECKS, their ids new so that none reads as a 5.18.101, 5.18.100 or 5.18.99 check (QL, \"quote lines\"),",
                      "#            counted (a note).",
        "#   5.18.103'S OWN CHECKS, their ids new so that none reads as an earlier release's (QC, \"quote catalogue\"), counts and\n"
        "#            fixed why codes only in the log (root docs/72 §G.13). The switch is OFF after the copy until an admin turns it\n"
        "#            on, so nothing is offered from uCRM before then and every count of what is offered is a note. Refusal before\n"
        "#            the copy: QC-G1 what the forms would offer in direct mode — through the PIN's UcrmQuoteLines::offered() over\n"
        "#            the copy's catalogue settings (the pin's UcrmCatalogue by its own path), through the plugin's own client and,\n"
        "#            when crm_auth_token is set, the token client, GET-only: NO-GOs that no flag lifts — an unreadable catalogue\n"
        "#            (either client); an answer whose why code is outside the release's set, or whose reason is empty (\"other\");\n"
        "#            a request but the two lists; the settings on the copy unread, or the pin without lib/UcrmCatalogue.php; the\n"
        "#            items' keyed hashes not made. With no quoting organization in force (Q-G3) QC-G1 is a note: the catalogue lives\n"
        "#            under the gate. Notes, counts only: QC-G1's items not offered, by why; QC-N1 the switch as stored — said in\n"
        "#            CAPITALS when it is already on (an earlier, rolled-back run left it so: agents see direct mode the moment the\n"
        "#            copy lands); QC-N2 the installation product and the standard quotations, defined and usable now; QC-N3 the\n"
        "#            applications by entry, quote_sale_entry web, app, web-direct or none — F11's measure (§G.11); QC-N4 5.18.102's\n"
        "#            web applications refused P1 with no quotation, quoted in uCRM by hand once the switch is on (G-10), their ids on\n"
        "#            the sheet; QC-N5 the offered hardware with no active stock category of the same title (the stock deduction\n"
        "#            matches by title, §G.7); QC-N6 the offered items public_price_exclude names — a hint, the forms never read\n"
        "#            it (G-5); QC-N7 the plugin's own active plans by type, none of which direct mode draws. After the copy: QC-CA1\n"
        "#            the same through the INSTALLED offered() and UcrmCatalogue on a fresh copy — in the deploy run equal to stage\n"
        "#            A's ITEM BY ITEM (each item's verdict a keyed hash under the QL key, kept in a file of mode 0600 beside the\n"
        "#            state file, never printed), unless the catalogue's settings changed since stage A (a note naming it); in an\n"
        "#            --after-only run a note naming its direction; in either an unreadable catalogue, \"other\", the settings unread\n"
        "#            or any request but a GET fails. QC-CA2 (stage V): the uCRM catalogue screen sends a visitor with no session to\n"
        "#            the sign-in page, and the installed includes/post/post_sync.php guards its five actions — POST-only, inside\n"
        "#            the gate, an admin, the account's fresh re-read and the own-page check before any write — read as text beside\n"
        "#            the pin's. QC-CA3: Send Quotation and the lead quotes re-price picked lines inside QuotationService, as text\n"
        "#            beside the pin's. In an --after-only run, once an admin has turned the switch on (each a FAIL otherwise):\n"
        "#            QC-ON1 the switch ON; QC-ON2 the installation product set and passing; QC-ON3 every standard quotation usable\n"
        "#            (none defined, a note); QC-ON4 G-7 over the copy's web-direct records — package_choice and device_id empty, only\n"
        "#            id 0 in the cart. After a rollback: QC-RB1 nothing in 5.18.102 names UcrmCatalogue or ucrm_catalogue — every\n"
        "#            file 5.18.102 tracks swept, each file 5.18.103 changes read beside its control on the pin — and the table\n"
        "#            ucrm_catalogue left on the book, counted; QC-RB2 the web-direct applications with no quotation, counted, their\n"
        "#            ids on the sheet (a note: 5.18.102 never quotes them, an admin does by hand).\n"
        "#\n"
        "#   5.18.102'S CHECKS, CARRIED (QL, \"quote lines\"), against 5.18.103's code: with the switch off 5.18.103 quotes through\n"
        "#            the links as 5.18.102 does, so QL-G1 still refuses before the copy where uCRM offers nothing an admin could\n"
        "#            link for a kind, unless --accept-unpriced (the pin's resolver and UcrmQuoteLinks on a copy, GET-only; its\n"
        "#            no-flag NO-GOs as 5.18.102 had them); QL-N1–QL-N7 its notes (QL-N3's marks now read 5.18.102's as written by\n"
        "#            the live release); QL-CA2 the salesPerson attribute again after the copy; QL-CA3 (stage V) the Plans and\n"
        "#            Hardware screens, their pickers and the Orders card without a session, post_sync.php's link and quotation\n"
        "#            actions as text, Product Mapping's two actions admin-only. RETIRED (docs/72 §G.13): QL-CA1 and QL-LK1 — with\n"
        "#            the switch on links decide nothing, and under the owner's D6 every item stays unlinked, so QL-LK1 would fail\n"
        "#            every run; QC-CA1 compares what the forms offer instead — and QL-RB1–QL-RB3 with --rollback-unpriced-pending,\n"
        "#            which guarded a rollback to 5.18.101 (its plan line at offer_price ?? 0).", why="H QC and QL")
    doc.lines_between("#   A ROLLBACK MEETS ONE HAZARD, AND --rollback REFUSES ON IT BEFORE ITS COPY (docs/71 §Q, D-R2). 5.18.102 saves a",
                      "#            and 5.18.101's cron only as what follows once it serves.",
        "#   A ROLLBACK TO 5.18.102 MEETS NO PRICE-0 HAZARD; IT COUNTS WHAT IT LEAVES UNQUOTED (docs/72 §G.10). 5.18.102 keeps its\n"
        "#            gate on and reads a web-direct application as one from the app — P1, or P2 where cash was collected — so it\n"
        "#            never quotes it, nor offers Make the quotation for it: an admin quotes each in uCRM by hand, after checking the\n"
        "#            customer has none. --rollback counts them in stage A and again after the rollback, on a copy of the book, read-\n"
        "#            only (QC-RB2: counts in the log, their ids on the sheet) — a note, never a refusal; so does the deploy's own\n"
        "#            automatic rollback (V1), after it. A direct form still open in a browser posts no plugin plan, so 5.18.102\n"
        "#            refuses it and writes nothing. The uCRM catalogue's settings stay in the store, read by nothing (QC-RB1); a\n"
        "#            later deploy of 5.18.103 finds them as they were. When V1's rollback does not verify, its stop says the\n"
        "#            rollback did not complete — 5.18.103 may still serve — and prints the by-hand put-back, which counts nothing.",
        why="H rollback")
