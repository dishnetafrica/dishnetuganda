# Part C — 5.18.103's own checks (QC; root docs/72 §G.13): the probe's readings, stage A's judge (QC-G1, QC-N1–QC-N7),
# the judge after the copy (QC-CA1; --after-only: QC-ON1–QC-ON4), the state file's lines, and their calls.
import os
HERE = os.path.dirname(os.path.abspath(__file__))


def snippet(name):
    return open(os.path.join(HERE, name), encoding="utf-8").read()


QC_BASH = r'''# ── 5.18.103: what the forms offer from uCRM in direct mode (QC; root docs/72 §G.13) ─────────────────────────────────────
# The readings are qo_run's (the probe's "qc" parts: on the copy, the uCRM catalogue's records through the RELEASE's
# lib/UcrmCatalogue.php by its own path; from uCRM, GET-only, the RELEASE's UcrmQuoteLines::offered() over them — the pin's
# at stage A, the INSTALLED code after the copy). Counts, read states, HTTP codes and fixed why codes in the log; names,
# uCRM ids, prices and reasons on the sheet; each item's verdict as a keyed hash under the QL key, never printed.
qc_well() { local f; for f in sp pr plans hw not unread other; do [ -n "$(ql_n "$1" "$f")" ] || return 1; done; return 0; }   # qc_well PART — qcc's seven counts
qc_say() { printf '%s service plan(s) and %s product(s) counted; offered %s plan(s) and %s hardware item(s); %s not offered' "$(ql_n "$1" sp)" "$(ql_n "$1" pr)" "$(ql_n "$1" plans)" "$(ql_n "$1" hw)" "$(ql_n "$1" not)"; }
qc_why_say() {   # qc_why_say PART — qcwhy's "why.n+…" (the release's fixed codes) in words
  case "$1" in ''|none) printf 'none'; return 0 ;; esac
  printf '%s\n' "$1" | tr '+' '\n' | awk -F. '
    { w = $1
      if (w == "plan_rows") w = "200 service plans or more"; else if (w == "unlisted") w = "no longer listed"
      else if (w == "plan_twin") w = "a service plan spelled like another"; else if (w == "no_month") w = "no 1-month price"
      else if (w == "plan_price") w = "a service plan priced other than its 1-month price"
      else if (w == "plan_no_product") w = "no product spelled like the service plan"; else if (w == "plan_two_products") w = "two products spelled like the service plan"
      else if (w == "bad") w = "no usable price, taxable, or an invoice label unlike its name"; else if (w == "price_differs") w = "a product price unlike its service plan"
      else if (w == "spelled_apart") w = "product and service plan spelled apart"; else if (w == "like_plan") w = "hardware spelled like a service plan"
      else if (w == "kept_off") w = "kept off by an admin"; else if (w == "archived") w = "archived"; else if (w == "inactive") w = "not active"
      else if (w == "organization") w = "another organization'"'"'s"; else if (w == "other") w = "UNCLASSIFIED"
      printf "%s%s %s", (n++ ? ", " : ""), $2, w }
    END { if (!n) printf "none" }'
}
qc_cmp() {   # qc_cmp STAGE_A NOW — two "<H>:<H>,…" lists (or none) compared by key: "same:<n>,differs:<n>,new:<n>,gone:<n>"
  awk -v a="$1" -v b="$2" 'BEGIN {
    na = (a == "none" || a == "") ? 0 : split(a, x, ","); nb = (b == "none" || b == "") ? 0 : split(b, y, ",")
    for (i = 1; i <= na; i++) A[substr(x[i], 1, 32)] = substr(x[i], 34)
    for (i = 1; i <= nb; i++) { k = substr(y[i], 1, 32); v = substr(y[i], 34); if (k in A) { if (A[k] == v) s++; else d++; seen[k] = 1 } else n++ }
    for (k in A) if (!(k in seen)) g++
    printf "same:%d,differs:%d,new:%d,gone:%d", s, d, n, g }'
}
qc_cmp_say() { printf '%s compared — the same %s, different %s; not offered or left out at stage A %s, at stage A and not now %s' "$(( $(ql_n "$1" same) + $(ql_n "$1" differs) ))" "$(ql_n "$1" same)" "$(ql_n "$1" differs)" "$(ql_n "$1" new)" "$(ql_n "$1" gone)"; }
qc_inst_say() { case "$1" in ok) echo "set, and passing" ;; unset) echo "not set" ;; unread) echo "not read (uCRM unreadable)" ;; unset*|'') echo "${1:-not read}" ;; *) echo "set, and not passing ($(qc_why_say "$1.1" | sed 's/^1 //'))" ;; esac; }
# qc_stage — QC-G1 and QC-N1–QC-N7, stage A (a deploy and --check), on qo_stage's reading (qo_run a: the PIN's code from
# Git — its UcrmQuoteLines and, by that file's own path, its lib/UcrmCatalogue.php — on the copies, and uCRM with GETs
# only, in the probe whose requests Q-G4's refusal counted). Direct mode lives under the quoting organization (D7): with
# none in force QC-G1 is a note. QC-G1 refuses — no flag lifts it — an unreadable catalogue through the plugin's own client
# or, when crm_auth_token is set, the token client; an answer this script cannot classify ("other": a why code outside the
# release's set, or an empty reason); a request but the two lists; the catalogue's settings on the copy unread, or the pin
# without its lib/UcrmCatalogue.php; the items' keyed hashes not made (QC-CA1 compares them after the copy). Every count
# of what is offered is a note: nothing is offered from uCRM until an admin turns the switch on. Sets QC_A (QC-G1's counts
# by client), QC_I_A (each item's keyed hash with its verdict's, under the QL key: never printed), QCSW_A and QCCAT_A (the
# switch and the catalogue's records on stage A's copy) — for QC-CA1 after the copy and, through the state file, a later
# --after-only's.
QC_A=""; QC_I_A=""; QCSW_A=""; QCCAT_A=""
qc_stage() {
  local c p t x lt w s i k
  case "$QO_KIND:$QO_R" in a:*" gets="*" other="*) ;; *) stop "NO-GO (QC-G1): stage A's quoting reading is not in this run (${QO_KIND:-none}) — what the forms would offer from uCRM cannot be judged. Nothing was changed. Send the log file" ;; esac
  c="$(kv "$QO_R" qcc)"; lt="$(kv "$QO_L" qccat)"; QCCAT_A="$lt"; QCSW_A="$(kv "$QO_L" qcsw)"
  case "$c" in
    off) QC_A="off"
      note "QC-G1 not asked: no quoting organization is in force (Q-G3) — the uCRM catalogue lives under it, so $EXPECTED_VERSION then offers nothing from uCRM and its screen is not shown (docs/72 §G.10)" ;;
    plug:*)
      x="$(kv "$QO_R" qcpath)"
      [ "$x" = "ok" ] || stop "NO-GO (QC-G1): the RELEASE's offered() asked uCRM, through $(ql_who "${x#bad:}"), for something other than its two lists once each (GET products?limit=500, then GET service-plans?limit=500) — the code is not the one this script was reviewed against; the wrapper let only GETs through. No flag lifts it. Nothing was changed. Send the log file"
      p="$(ql_of "$c" plug)"; t="$(ql_of "$c" tok)"
      qc_well "$p" || stop "NO-GO (QC-G1): what the forms would offer could not be counted through the plugin's own client ($c). No flag lifts it. Nothing was changed. Send the log file"
      if [ "$QO_TOK" = "token=unset" ]; then [ "$t" = "unset" ] || stop "NO-GO (QC-G1): crm_auth_token is unset, and the probe read the catalogue through a token client ($t). Nothing was changed. Send the log file"
      else qc_well "$t" || stop "NO-GO (QC-G1): crm_auth_token is set, and what the forms would offer could not be counted through the token client (${t:-none}). No flag lifts it. Nothing was changed. Send the log file"; fi
      [ "$(ql_n "$p" unread)" = "0" ] || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only ($(ql_rd "$(ql_of "$(kv "$QO_R" qcread)" plug)")) — the uCRM catalogue screen, Send Quotation and the lead quotes read them so. No flag lifts it. Nothing was changed. Send the log file"
      [ "$t" = "unset" ] || [ "$(ql_n "$t" unread)" = "0" ] || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the token client (crm_auth_token), GET-only ($(ql_rd "$(ql_of "$(kv "$QO_R" qcread)" tok)")) — the KYC form, the submission and the retries read them so. No flag lifts it. Nothing was changed. Send the log file"
      w=$(( $(ql_n "$p" other) + $( [ "$t" = "unset" ] && echo 0 || ql_n "$t" other ) ))
      [ "$w" = "0" ] || stop "NO-GO (QC-G1): $w answer(s) of the RELEASE's offered() cannot be classified (\"other\": a why code outside the release's set, or an empty reason) — the release's words and this script's disagree; each is on the sheet, verbatim (less $SHEET). No flag lifts it. Nothing was changed. Send the log file"
      case "$lt" in
        absent|records:*) ;;
        '?nocat') stop "NO-GO (QC-G1): the quoting organization is in force, and the RELEASE's lib/UcrmCatalogue.php was not where the local reading loads it, the pin's own file by its path (?nocat) — the catalogue's settings on the copy cannot be read. No flag lifts it. Nothing was changed. Send the log file" ;;
        '?created') stop "NO-GO (QC-G1): reading the uCRM catalogue's settings on the copy created their table (?created) — the release's reader never does: the pin's code is not the one reviewed. No flag lifts it. Nothing was changed. Send the log file" ;;
        *) stop "NO-GO (QC-G1): the uCRM catalogue's settings on the copy could not be read through the pin's UcrmCatalogue (${lt:-not read}). No flag lifts it. Nothing was changed. Send the log file" ;;
      esac
      ql_hl_ok i "$QO_QCI" || stop "NO-GO (QC-G1): the items' keyed hashes were not made ($(ql_hl_say "$QO_QCI")) — QC-CA1 would have nothing to compare after the copy, item by item. Nothing was changed. Send the log file"
      QC_A="$c"; QC_I_A="$QO_QCI"
      ok "QC-G1 what the forms would offer in direct mode is read and judged through the RELEASE's UcrmQuoteLines::offered() on a copy, GET-only — GET products?limit=500, then GET service-plans?limit=500, nothing else — with the copy's catalogue settings ($( [ "$lt" = "absent" ] && echo "none: the table was never written on this book" || echo "$(ql_n "$lt" records) record(s), $(ql_n "$lt" inforce) in force")), every answer classified by the release's why codes: through the plugin's own client $(qc_say "$p")$( [ "$t" = "unset" ] && echo " (crm_auth_token unset)" || echo "; through the token client $(qc_say "$t")"); per item its name, uCRM ids, price and unit, or its reason, on the sheet"
      note "QC-G1 not offered, by why — through the plugin's own client: $(qc_why_say "$(ql_of "$(kv "$QO_R" qcwhy)" plug)")$( [ "$t" = "unset" ] || echo "; through the token client: $(qc_why_say "$(ql_of "$(kv "$QO_R" qcwhy)" tok)")") — each one's reason says its remedy in uCRM (docs/72 §G.4); a note, never toward the NO-GO"
      [ "$(ql_n "$p" plans)" != "0" ] || note "QC-G1 no plan is offered from uCRM through the plugin's own client: the uCRM catalogue screen refuses to turn the switch on until one is (each service plan's reason is on the sheet) — after the copy $EXPECTED_VERSION behaves as $BASELINE_VERSION" ;;
    *) stop "NO-GO (QC-G1): what the forms would offer could not be read on the copy (${c:-not read}$(case "$c" in nolib) echo ": the pin's code has no lib/UcrmQuoteLines.php or no lib/UcrmCatalogue.php" ;; nooffer) echo ": the copy's catalogue settings, stock categories or public_price_exclude were not read" ;; '?'*) echo ": the reading threw" ;; esac)). No flag lifts it. Nothing was changed. Send the log file" ;;
  esac
  # QC-N1 — the switch as stored on stage A's copy. ON only where an earlier, rolled-back run of this release left it on:
  # then agents see direct mode the moment the copy lands — said in capitals.
  case "$QCSW_A" in
    on) note "QC-N1 THE uCRM CATALOGUE IS SWITCHED ON in this book's store — an earlier, rolled-back run of $EXPECTED_VERSION left it on (docs/72 §G.10). FROM THE COPY, AGENTS SEE uCRM'S PLANS AND HARDWARE AT ONCE: the KYC form, Send Quotation and the lead quotes offer uCRM's items and the plugin's Plans and Hardware are hidden. If that is not wanted now, an admin turns it off on the uCRM catalogue screen (Uganda) right after the run's last line" ;;
    off|none) note "QC-N1 the uCRM catalogue's switch on stage A's copy: $( [ "$QCSW_A" = "none" ] && echo "never set" || echo "off, as an admin left it") — after the copy $EXPECTED_VERSION behaves as $BASELINE_VERSION until an admin turns it on on the uCRM catalogue screen (docs/72 §G.3)" ;;
    two) note "QC-N1 two switch records are in force on stage A's copy — the release reads that as off (it never writes two); an admin sets it again on the uCRM catalogue screen" ;;
    *) note "QC-N1 the uCRM catalogue's switch could not be read on the copy (${QCSW_A:-not read}) — said, never a reason to stop" ;;
  esac
  # QC-N2 — the installation product and the standard quotations, on the copy, judged through the pin's offered().
  case "$c" in
    off) ;;
    *) i="$(kv "$QO_R" qcinst)"; s="$(kv "$QO_R" qcstd)"
      note "QC-N2 the installation product (the copy's: $(kv "$QO_L" qcinst)): through the plugin's own client $(qc_inst_say "$(ql_of "$i" plug)")$( [ "$QO_TOK" = "token=unset" ] || echo "; through the token client $(qc_inst_say "$(ql_of "$i" tok)")") — while it is not set, or not passing, a sale DishNet installs is unpriced in direct mode (the agent is told; P1); standard quotations defined and usable now: $(ql_of "$s" plug | sed 's#/# defined, #; s#$# usable#')$( [ "$QO_TOK" = "token=unset" ] || echo " (the token client: $(ql_of "$s" tok | sed 's#/# defined, #; s#$# usable#'))") — each on the sheet" ;;
  esac
  # QC-N3 — F11's measure (docs/72 §G.11): the applications by entry; QC-N4 — 5.18.102's web applications refused P1 with
  # no quotation, which an admin quotes by hand once the switch is on (G-10).
  x="$(kv "$QO_L" qcentry)"
  case "$x" in
    web:*) note "QC-N3 the applications by entry (quote_sale_entry, which every application saved since $BASELINE_VERSION's copy carries): web $(subkv "$x" web) (the KYC form), app $(subkv "$x" app) (the retailer app, the mobile API, or a form opened before that copy), web-direct $(subkv "$x" direct) (an earlier, rolled-back run of $EXPECTED_VERSION), none $(subkv "$x" none) (saved before $BASELINE_VERSION)$( [ "$(subkv "$x" other)" = "0" ] || echo ", another value $(subkv "$x" other)") — F11's measure (docs/72 §G.11): the retailer app and the mobile API stay on the plugin's own lists in $EXPECTED_VERSION; if agents use them, moving them to the uCRM catalogue is the next step" ;;
    *) note "QC-N3 the applications could not be counted by entry (${x:-not read}) — said, never a reason to stop" ;;
  esac
  x="$(kv "$QO_L" qcwebp1)"
  case "$x" in
    0) note "QC-N4 no web application of $BASELINE_VERSION is waiting with P1 and no quotation" ;;
    [0-9]*) note "QC-N4 $x web application(s) of $BASELINE_VERSION refused P1 with no quotation — under $EXPECTED_VERSION with the switch off, Make the quotation applies to them as before once their items are linked; with the switch on, each is quoted in uCRM by hand, as its Orders card then says (docs/72 G-10). Ids on the sheet" ;;
    *) note "QC-N4 the waiting web applications could not be counted (${x:-not read})" ;;
  esac
  # QC-N5 — offered hardware with no active stock category of its title (the stock deduction matches by title, §G.7);
  # QC-N6 — offered items public_price_exclude names (a hint: the forms never read that list, G-5); QC-N7 — the plugin's
  # active plans by type, none of which direct mode draws.
  case "$c" in
    off) ;;
    *) x="$(ql_of "$(kv "$QO_R" qcstock)" plug)"
      case "$x" in
        0) note "QC-N5 every hardware item offered through the plugin's own client has an active stock category of the same title ($(kv "$QO_L" qcstock))" ;;
        [0-9]*) note "QC-N5 $x hardware item(s) offered from uCRM have no active stock category of the same title ($(kv "$QO_L" qcstock)) — a direct sale's cart carries uCRM's names, so a completed job deducts nothing for such an item (\"No stock category found\"): title those categories as uCRM names the products (docs/72 §G.7); names on the sheet" ;;
        *) note "QC-N5 the stock categories could not be compared (${x:-not read}; $(kv "$QO_L" qcstock))" ;;
      esac
      x="$(ql_of "$(kv "$QO_R" qcppx)" plug)"
      case "$x" in
        0) note "QC-N6 public_price_exclude ($(kv "$QO_L" qcppx)) names none of the items offered from uCRM" ;;
        [0-9]*) note "QC-N6 public_price_exclude ($(kv "$QO_L" qcppx)) names $x item(s) offered from uCRM — the website hides them, the forms do not read that list (docs/72 G-5): an admin keeps one off on the uCRM catalogue screen if it is not to be sold; names on the sheet" ;;
        *) note "QC-N6 public_price_exclude could not be compared (${x:-not read}; $(kv "$QO_L" qcppx))" ;;
      esac ;;
  esac
  x="$(kv "$QO_L" qcplantype)"
  note "QC-N7 the plugin's own active plans by type: $(printf '%s' "${x:-not read}" | sed 's/:/ /g; s/,/, /g') — with the switch on none of them is drawn: the KYC form offers uCRM's service plans with their products, so a plan sold from the plugin's list today (an LTE or SIM one among them) must exist in uCRM as a service plan with its product to be offered"
  return 0
}
# qc_after INST_ROOT — QC-CA1 after the copy (stage R; --after-only too), on qo_after's reading (qo_run r: the INSTALLED
# code's offered(), a copy of it, ICODE_R, over a fresh copy's catalogue settings, uCRM with GETs only). In the deploy run
# every item's verdict must equal stage A's, item by item, under the QL key — unless the catalogue's records changed since
# stage A (an admin used the uCRM catalogue screen while the run printed), then the comparison is a note naming that; in
# an --after-only run (the state file's hashes, the key file's key) a note. An unreadable catalogue, an unclassified
# answer, the settings unread, a request but the two lists or any request but a GET fails. Then, in an --after-only run
# only, run once an admin has turned the switch on (docs/72 §G.13): QC-ON1 the switch ON; QC-ON2 the installation product
# set and passing; QC-ON3 every standard quotation usable (none defined: a note); QC-ON4 G-7's invariant over the copy's
# web-direct records — package_choice '', device_id '' and only id 0 in the cart. Reports; never stops.
qc_after() {
  local inst="$1" c p t x m lt ok_r="" i s n u g
  case "$QO_KIND:$QO_R" in r:*" gets="*" other="*) ok_r=1 ;; esac
  lt="$( [ -n "$ok_r" ] && kv "$QO_L" qccat)"
  if [ -z "$inst" ]; then bad "QC-CA1 the copy of the installed code was not made — what the forms offer is not read after the copy"
  elif [ -z "$ok_r" ]; then bad "QC-CA1 what the forms offer could not be read after the copy: the quoting checks' reading through the installed code did not complete (${QO_ERR:-Q-CA2 and Q-CA3 above say why})"
  else
    c="$(kv "$QO_R" qcc)"
    case "$c" in
      off) if [ -n "$QC_A" ] && [ "$QC_A" != "off" ]; then bad "QC-CA1 no quoting organization is in force through the installed code (Q-CA2), and stage A read the catalogue under one — the gate is not as stage A read it"
           else echo "  QC-CA1          not asked: no quoting organization is in force (Q-CA2) — the uCRM catalogue lives under it"; fi ;;
      plug:*)
        p="$(ql_of "$c" plug)"; t="$(ql_of "$c" tok)"; x=""
        [ "$(kv "$QO_R" qcpath)" = "ok" ] || x="$x offered() asked for something other than its two lists once each (through $(ql_who "$(kv "$QO_R" qcpath | sed 's/^bad://')"));"
        if ! qc_well "$p"; then x="$x the plugin's own client's counts not read ($p);"
        elif [ "$(ql_n "$p" unread)" != "0" ]; then x="$x unreadable through the plugin's own client ($(ql_rd "$(ql_of "$(kv "$QO_R" qcread)" plug)"));"
        elif [ "$(ql_n "$p" other)" != "0" ]; then x="$x $(ql_n "$p" other) unclassified answer(s) through the plugin's own client;"; fi
        if [ "$QO_TOK" = "token=unset" ]; then [ "$t" = "unset" ] || x="$x a token client's reading where crm_auth_token is unset ($t);"
        elif ! qc_well "$t"; then x="$x the token client's counts not read (${t:-none});"
        elif [ "$(ql_n "$t" unread)" != "0" ]; then x="$x unreadable through the token client ($(ql_rd "$(ql_of "$(kv "$QO_R" qcread)" tok)"));"
        elif [ "$(ql_n "$t" other)" != "0" ]; then x="$x $(ql_n "$t" other) unclassified answer(s) through the token client;"; fi
        case "$lt" in absent|records:*) ;; *) x="$x the catalogue's settings on the copy not read through the installed UcrmCatalogue (${lt:-not read});" ;; esac
        [ "$QO_OTHER" = "0" ] && [ -z "$QO_COUNT_BAD" ] || x="$x a request other than a GET was asked of uCRM (other $QO_OTHER;${QO_COUNT_BAD:- counted});"
        m=""; { [ -n "$QC_I_A" ] && ql_hl_ok i "$QC_I_A" && ql_hl_ok i "$QO_QCI"; } && m="$(qc_cmp "$QC_I_A" "$QO_QCI")"
        if [ -n "$x" ]; then bad "QC-CA1 what the forms offer, through the INSTALLED offered():${x%;} — each item by name on the sheet"
        elif [ "$MODE" = "deploy" ]; then
          if [ -z "$QC_I_A" ]; then bad "QC-CA1 stage A's verdicts are not known to this run$( [ "$QC_A" = "off" ] && echo " (stage A read none: no quoting organization was in force then, Q-G3)") — the installed code's: through the plugin's own client $(qc_say "$p")"
          elif [ -z "$m" ]; then bad "QC-CA1 what the installed code offers was not compared with stage A's, item by item: the hashes are not as written (stage A's $(ql_hl_say "$QC_I_A"), now $(ql_hl_say "$QO_QCI"))"
          elif [ "$(ql_n "$m" differs):$(ql_n "$m" new):$(ql_n "$m" gone)" = "0:0:0" ]; then
            ok "QC-CA1 the INSTALLED UcrmQuoteLines::offered(), on a fresh copy and GET-only, offers exactly what stage A's pin offered, item by item (compared under this run's QL key, never printed: $(qc_cmp_say "$m")) — the same plans and hardware at the same uCRM ids, prices and units, the same reason for each item left out; through the plugin's own client $(qc_say "$p")"
          elif [ "$lt" != "$QCCAT_A" ] || [ "$(kv "$QO_L" qcsw)" != "$QCSW_A" ]; then
            note "QC-CA1 what the installed code offers differs from stage A's, item by item ($(qc_cmp_say "$m")), and the uCRM catalogue's settings changed since stage A ($QCCAT_A, switch $QCSW_A → $lt, switch $(kv "$QO_L" qcsw)) — an admin used the uCRM catalogue screen while the run printed; each item on the sheet"
          else bad "QC-CA1 what the INSTALLED code offers is not what stage A's pin offered, item by item (compared under this run's QL key): $(qc_cmp_say "$m") — uCRM's Products or Service Plans changed while the deploy ran (a price, a name, an item archived), or the code differs from the one reviewed; each item by name on the sheet. Send the log file"; fi
        else
          ok "QC-CA1 what the forms offer, through the INSTALLED UcrmQuoteLines::offered() on a fresh copy, GET-only: through the plugin's own client $(qc_say "$p")$( [ "$t" = "unset" ] || echo "; through the token client $(qc_say "$t")"); not offered, by why: $(qc_why_say "$(ql_of "$(kv "$QO_R" qcwhy)" plug)")"
          if [ -n "$m" ]; then note "QC-CA1 against stage A's verdicts, item by item (the state file's hashes, under the deploy's QL key — never printed): $(qc_cmp_say "$m") — uCRM's catalogue and the uCRM catalogue screen's settings change between runs; each item on the sheet"
          else note "QC-CA1 stage A's verdicts could not be compared item by item ($( [ -z "$QC_I_A" ] && echo "the state file $STATE holds none, or the deploy's QL key file $QL_KEY_FILE is missing or not one QL_KEY= line of 48 hex" || echo "the hashes are not as written"))"; fi
        fi ;;
      *) bad "QC-CA1 what the forms offer could not be read through the installed code (${c:-not read})" ;;
    esac
  fi
  [ "$MODE" = "after" ] || return 0
  # QC-ON1–QC-ON4 — an --after-only run, once an admin has turned the switch on (docs/72 §G.13).
  x="$( [ -n "$ok_r" ] && kv "$QO_L" qcsw)"
  case "$x" in
    on) ok "QC-ON1 the uCRM catalogue is switched ON (the copy's record): the KYC form, Send Quotation and the lead quotes offer uCRM's plans and hardware on Uganda, and the plugin's Plans and Hardware are hidden" ;;
    *) bad "QC-ON1 the uCRM catalogue is not switched on (${x:-not read}) — turn it on on the uCRM catalogue screen (Uganda), as an admin, then run --after-only again; until then $EXPECTED_VERSION behaves as $BASELINE_VERSION (a run before it is on fails here by design)" ;;
  esac
  i="$( [ -n "$ok_r" ] && kv "$QO_R" qcinst)"
  case "$(ql_of "$i" plug)" in
    ok) [ "$QO_TOK" = "token=unset" ] || [ "$(ql_of "$i" tok)" = "ok" ] && ok "QC-ON2 the installation product is set and passes through the release's own rules$( [ "$QO_TOK" = "token=unset" ] || echo ", through the plugin's own client and the token client") — a sale DishNet installs carries it as a line" \
        || bad "QC-ON2 the installation product passes through the plugin's own client and not through the token client ($(qc_inst_say "$(ql_of "$i" tok)")) — the KYC form's submission reads with the token" ;;
    *) bad "QC-ON2 the installation product: $(qc_inst_say "$(ql_of "$i" plug)") — a sale DishNet installs is unpriced in direct mode (P1, the agent told): set it, or fix it in uCRM, on the uCRM catalogue screen (its reason is on the sheet)" ;;
  esac
  s="$( [ -n "$ok_r" ] && ql_of "$(kv "$QO_R" qcstd)" plug)"; n="${s%%/*}"; u="${s#*/}"
  case "$n:$u" in
    0:0) note "QC-ON3 no standard quotation is defined — a one-click quotation needs one: an admin defines it on the uCRM catalogue screen (docs/72 §G.3)" ;;
    *[!0-9:]*|:*|*:) bad "QC-ON3 the standard quotations could not be judged (${s:-not read})" ;;
    *) if [ "$n" = "$u" ]; then ok "QC-ON3 every standard quotation ($n) is usable now: its plan, hardware and installation are all offered"
       else bad "QC-ON3 $((n - u)) of $n standard quotation(s) cannot be used now — an item in it is no longer offered; the uCRM catalogue screen, and the sheet, say which"; fi ;;
  esac
  g="$( [ -n "$ok_r" ] && kv "$QO_L" qcg7)"
  case "$g" in
    direct:0,bad:0) note "QC-ON4 no web-direct application on the copy yet — G-7's invariant has nothing to read; run --after-only again after the first direct sale" ;;
    direct:*,bad:0) ok "QC-ON4 every web-direct application on the copy ($(subkv "$g" direct)) keeps G-7: package_choice and device_id empty, and only id 0 in its cart — nothing 5.18.102 would match to a plugin row after a rollback" ;;
    direct:*,bad:*) bad "QC-ON4 $(subkv "$g" bad) of $(subkv "$g" direct) web-direct application(s) break G-7 (package_choice or device_id set, or a cart id that is not 0) — ids on the sheet. Send the log file" ;;
    *) bad "QC-ON4 the web-direct applications could not be read on the copy (${g:-not read})" ;;
  esac
  return 0
}
'''


def apply(doc, ctx):
    # ── the probe: the copies keep public_price_exclude (QC-N6); the two new path arguments; the readings ──
    doc.sub('$keys = ["tenant_profile", "currency_code", "currency_symbol", "cashbook_base_currency", "cashbook_currencies", "ucrm_quote_organization_id", "ucrm_lead_organization_id"];',
            '$keys = ["tenant_profile", "currency_code", "currency_symbol", "cashbook_base_currency", "cashbook_currencies", "ucrm_quote_organization_id", "ucrm_lead_organization_id", "public_price_exclude"];   // 5.18.103: public_price_exclude for QC-N6 (a list of names, never a credential)')
    doc.sub('foreach (["data", "row", "out", "ids", "pick", "orgsout", "token", "sink", "elog", "offer", "q314", "qlh", "links", "recs", "appmaxout", "qla"] as $k) {',
            'foreach (["data", "row", "out", "ids", "pick", "orgsout", "token", "sink", "elog", "offer", "q314", "qlh", "links", "recs", "appmaxout", "qla", "cat", "qch"] as $k) {')
    doc.sub('        $ql_ofs = json_encode($offer);\n', snippet("qc_local.php.txt") + '        $ql_ofs = json_encode($offer);\n')
    doc.sub('        if (!$put((string)($A["out"] ?? ""), implode("\\n", array_merge($S, $qlS)) . "\\n")) { echo "?out"; exit(3); }',
            '        if (!$put((string)($A["out"] ?? ""), implode("\\n", array_merge($S, $qlS, $qcS)) . "\\n")) { echo "?out"; exit(3); }')
    doc.sub('             " qlsince=", $qlO["qlsince"], " qlended=", $qlO["qlended"], " qlwin=", $qlO["qlwin"];',
            '             " qlsince=", $qlO["qlsince"], " qlended=", $qlO["qlended"], " qlwin=", $qlO["qlwin"],\n'
            '             " qccat=", $qcO["qccat"], " qcsw=", $qcO["qcsw"], " qcinst=", $qcO["qcinst"], " qcstd=", $qcO["qcstd"], " qchid=", $qcO["qchid"],\n'
            '             " qcentry=", $qcO["qcentry"] ?? "-", " qcwebp1=", $qcO["qcwebp1"] ?? "-", " qcg7=", $qcO["qcg7"] ?? "-", " qcplantype=", $qcO["qcplantype"] ?? "-",\n'
            '             " qcstock=", $qcO["qcstock"] ?? "-", " qcppx=", $qcO["qcppx"] ?? "-";')
    doc.sub('        if (isset($what["attr"])) {\n', snippet("qc_remote.php.txt") + '        if (isset($what["attr"])) {\n')
    doc.must('$qcO["qcentry"]', why="local echo")
    # ── the judges, before 5.18.100's section ──
    doc.sub("# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────",
            QC_BASH + "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────")
    # ── stage A: after QL-G1 ──
    doc.sub('  hdr "A. The catalogue the KYC form offers (5.18.102; GETs only)"\n  ql_stage\nfi\n',
            '  hdr "A. The catalogue the KYC form offers (5.18.102\'s check, carried; GETs only)"\n  ql_stage\n'
            '  # QC-G1, QC-N1–QC-N7 — 5.18.103 (docs/72 §G.13), before anything changes: what the forms would offer from uCRM in\n'
            '  # direct mode — the PIN\'s UcrmQuoteLines::offered() over the copy\'s catalogue settings (the pin\'s UcrmCatalogue, by its\n'
            '  # own path), through the plugin\'s own client and, when crm_auth_token is set, the token client, GET-only (their requests\n'
            '  # counted with Q-G4\'s); then the notes, counts only. A refusal stops here: nothing has changed; no flag lifts one.\n'
            '  hdr "A. What the forms would offer from uCRM in direct mode (5.18.103; GETs only)"\n  qc_stage\nfi\n')

    # ── the state file: stage A's catalogue readings for a later --after-only ──
    doc.lines_between("  # 5.18.102: stage A's catalogue reading, for QL-CA1, QL-LK1 and QL-CA2 of a later --after-only — QL-G1's counts by client",
                      '    else bad "the QL key could not be kept in $QL_KEY_FILE — a later --after-only could not compare stage A\'s verdicts item by item"; fi',
        "  # 5.18.102: stage A's catalogue reading, for QL-CA2 of a later --after-only — QL-G1's counts by client and by class, the\n"
        "  # salesPerson attribute's answer (an attribute id at most) and whether --accept-unpriced decided QL-G1. 5.18.103 (docs/72\n"
        "  # §G.13): for QC-CA1 of a later --after-only, QC-G1's counts by client (QC_A), each item's keyed hash with its verdict's\n"
        "  # (QC_ITEMS), and the switch and the catalogue's records on stage A's copy (QCSW_A, QCCAT_A) — never a name, a uCRM id,\n"
        "  # a price or an application id. The key the hashes are made under (QL_KEY) is kept in a file of its own, QL_KEY_FILE —\n"
        "  # mode 0600, in this run's folder of mode 700, beside the state file and outside its glob, never printed. This run's own\n"
        "  # MAP_SALT is never written. (5.18.102's QLG1_ITEMS, QLG1_RECS and QLG1_APP_MAX went with QL-CA1 and QL-LK1.)\n"
        "  if [ \"$MODE\" = \"deploy\" ]; then\n"
        "    printf 'QLG1_A=%s\\nQLATTR_A=%s\\nQLG1_NOTED=%s\\nQC_A=%s\\nQC_ITEMS=%s\\nQCSW_A=%s\\nQCCAT_A=%s\\n' \"${QLG1_A:-}\" \"${QLATTR_A:-}\" \"${QLG1_NOTED:-}\" \"${QC_A:-}\" \"${QC_I_A:-}\" \"${QCSW_A:-}\" \"${QCCAT_A:-}\" >> \"$STATE_W\"\n"
        "    if ( umask 077; printf 'QL_KEY=%s\\n' \"$QL_KEY\" > \"$QL_KEY_FILE\" ) && chmod 600 \"$QL_KEY_FILE\"; then\n"
        "      ok \"the QL key kept in $QL_KEY_FILE (mode 0600, never printed) — QC-CA1 of a later --after-only compares under it\"\n"
        "    else bad \"the QL key could not be kept in $QL_KEY_FILE — a later --after-only could not compare stage A's verdicts item by item\"; fi", why="state file")
    # ── stage R: the readings an --after-only takes from the state file, and the judges ──
    doc.lines_between("# 5.18.102 D14 (docs/71 §R.7.2, §R.7.3; derivation M-1, R-12): the catalogue's reading after the copy (qo_run r, inside",
                      'QL_IN_APPMAX="${QLG1_APP_MAX:--}"; QL_IN_COPYAT="${QL_COPY_AT:--}"',
        "# 5.18.103 (docs/72 §G.13): QC-CA1, after the copy (qo_run r, inside qo_after), reads what the installed code offers and\n"
        "# compares it with stage A's verdicts item by item: in a deploy this run's stage A; in an --after-only run the deploy's\n"
        "# state file — QLG1_A, QLATTR_A and QLG1_NOTED for QL-CA2 as before, and QC_A, QC_ITEMS, QCSW_A and QCCAT_A (counts and\n"
        "# hashes, never a name) — and the QL key from its own file, QL_KEY_FILE (one QL_KEY= line of 48 hex, mode 0600, beside\n"
        "# the state file; never printed). Without that key the state file's hashes are under a key this run does not hold, so\n"
        "# none is compared: QC-CA1 then says it \"could not be compared\", never a guess.\n"
        "if [ \"$MODE\" = \"after\" ]; then\n"
        "  QLG1_A=\"$(sed -n 's/^QLG1_A=//p' \"$STATE\" 2>/dev/null | tail -n1)\"; QLATTR_A=\"$(sed -n 's/^QLATTR_A=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n"
        "  QLG1_NOTED=\"$(sed -n 's/^QLG1_NOTED=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n"
        "  QC_A=\"$(sed -n 's/^QC_A=//p' \"$STATE\" 2>/dev/null | tail -n1)\"; QC_I_A=\"$(sed -n 's/^QC_ITEMS=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n"
        "  QCSW_A=\"$(sed -n 's/^QCSW_A=//p' \"$STATE\" 2>/dev/null | tail -n1)\"; QCCAT_A=\"$(sed -n 's/^QCCAT_A=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n"
        "  QL_KEY_A=\"$(sed -n 's/^QL_KEY=//p' \"$QL_KEY_FILE\" 2>/dev/null | tail -n1)\"\n"
        "  case \"$QL_KEY_A\" in *[!0-9a-f]*) QL_KEY_A=\"\" ;; esac; [ \"${#QL_KEY_A}\" = \"48\" ] || QL_KEY_A=\"\"\n"
        "  if [ -n \"$QL_KEY_A\" ]; then QL_KEY=\"$QL_KEY_A\"; else QC_I_A=\"\"; fi\n"
        "else QL_KEY_A=\"$QL_KEY\"; fi", why="stage R state reads")
    doc.lines_between("# QL-CA1, QL-CA2 — 5.18.102 (docs/71 §L.3, §Q D-R3; D14 §R.7.2), after the copy: the catalogue again, through the",
                      '[ "$MODE" != "after" ] || ql_lk1',
        "# QL-CA2 — 5.18.102's, carried, after the copy: the salesPerson attribute again, through the installed code's reading\n"
        "# (QL-CA1 and QL-LK1 are retired since 5.18.103, docs/72 §G.13). Reports; never stops.\n"
        "hdr \"R. The salesPerson attribute, installed (5.18.102's check, carried; GETs only)\"\n"
        "ql_after \"${ICODE_R:-}\"\n"
        "# QC-CA1 — 5.18.103 (docs/72 §G.13), after the copy: what the forms offer in direct mode, through the INSTALLED\n"
        "# UcrmQuoteLines::offered() and UcrmCatalogue (ICODE_R) on the reading qo_after made above — a fresh copy's catalogue\n"
        "# settings, uCRM with GETs only, its requests in QO_GETS and QO_OTHER — item by item against stage A's under the QL key\n"
        "# (never printed); and, in an --after-only run once an admin has turned the switch on, QC-ON1–QC-ON4. Reports; never\n"
        "# stops.\n"
        "hdr \"R. What the forms offer from uCRM in direct mode, installed (5.18.103; GETs only)\"\n"
        "qc_after \"${ICODE_R:-}\"", why="stage R calls")
    doc.mustnot("QL_IN_"); doc.mustnot("QLG1_I_A"); doc.mustnot("QLG1_R_A")
