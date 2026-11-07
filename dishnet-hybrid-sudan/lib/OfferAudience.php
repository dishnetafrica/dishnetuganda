<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/PhoneNumber.php';
require_once __DIR__ . '/TenantProfile.php';
require_once __DIR__ . '/StaffDirectory.php';
require_once __DIR__ . '/InternalNumbers.php';
require_once __DIR__ . '/EmailReplyPolicy.php';
require_once __DIR__ . '/EvolutionApiService.php';
require_once __DIR__ . '/HumanTakeover.php';
require_once __DIR__ . '/QuoteChase.php';
require_once __DIR__ . '/OfferText.php';
require_once __DIR__ . '/OfferSwitch.php';
require_once __DIR__ . '/OfferGateway.php';
require_once __DIR__ . '/EvolutionOfferGateway.php';

/**
 * OfferAudience — who an offer may go to, from which handset, and why everyone else is left out (5.18.104 P6, root
 * docs/73 §H.4.8, §H.4.9; rulings R3, R13, R18).
 *
 * STORE-FREE (ruling R13): it takes no store, builds none and opens none. Every list is read through the \PDO it is given
 * (the switch tool's query_only $ro included); the two store collections the lists need come as arguments: the
 * retailers.json rows and the store's kyc_config.json (storedRows() reads both straight from a connection), and the
 * DishNet-number check is given reader(), a read-only object whose load() answers those two and wa_evo_numbers.json.
 *
 * ONE PHONE FORM (O-D17): digits() is PhoneNumber::international without its '+', or null. Every list is turned into it
 * before any comparison; a row that cannot be is compared on its last nine digits, for EXCLUSION ONLY, and counted as
 * <list>_loose. Over-excluding is the safe direction.
 *
 *   build()  the preview and the Start: every warm phone, its chosen conversation and handset, each left out by reason
 *   judge()  one recipient just before its send, at its moment, under the offer's recorded warm_days and recent_days
 *            (the sender passes them in $config): ok | skip(code) | defer(code, retry_at) | move(conversation)
 *   lists()  the one list loader both call; OfferSwitch::checkLive() calls it for C-O2-9 at a recipient's judged_at
 *
 * The person rule is Part F's HumanTakeover alone (C7), and the quotation hold Part F's QuoteChase::offerHolds()/
 * offerHold() (ruling R3); a read that fails stops the build with audience_unreadable:<list>, never "nobody to exclude".
 * P6 reads nothing of Part V: an inbound voice note holds the phone for the whole warm window (ruling R3). PHP 7.4.
 */
final class OfferAudience
{
    const SIZES = ['100' => 100, '200' => 200, 'all' => 0];
    /** On a tie of the latest customer message, the department order, then the channel id. */
    const CHANNEL_ORDER = ['sales' => 0, 'support' => 1, 'account' => 2];
    const HOLD_48H_S = 172800;
    const PERSON_72H_S = 259200;
    const AWAIT_REPLY_S = 7200;
    const CHANNEL_WAIT_S = 1800;
    /** The list exclusions in the order a phone is judged (and counted) by. */
    const LIST_CODES = ['opted_out', 'offer_stop', 'stop_words', 'recent_voice_note', 'unread_inbound', 'colleague', 'dishnet_number',
                        'partner_contact', 'quote_hold', 'followup_open', 'followup_recent', 'recent_offer', 'person_handling',
                        'escalation_words', 'ambiguous_identity'];

    // ── the one phone form ─────────────────────────────────────────────────────────────────────────────────────

    /** International digits without '+', by the tenant's rule; null when the number does not normalise. */
    public static function digits(string $raw, \TenantProfile $t): ?string
    {
        if (trim($raw) === '') return null;
        $i = \PhoneNumber::international($raw, $t);
        return $i === null ? null : ltrim($i, '+');
    }

    /** The last nine digits of whatever was typed, for the loose (exclusion-only) comparison; '' when fewer. */
    private static function last9(string $raw): string
    {
        $d = (string)preg_replace('/\D+/', '', $raw);
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    // ── what the audience is given ─────────────────────────────────────────────────────────────────────────────

    /**
     * The retailers.json rows and the stored kyc_config.json, read straight from a connection in the forms SqliteStore
     * keeps them (a record table's rows by id; a flat table's one row, id 0). A missing table reads as nothing; any other
     * failure throws audience_unreadable:retailers or audience_unreadable:kyc_config.
     *
     * @return array{retailers: array, kyc_config: array}
     */
    public static function storedRows(\PDO $pdo): array
    {
        $out = ['retailers' => [], 'kyc_config' => []];
        foreach (['retailers' => 'SELECT data FROM [retailers] ORDER BY id', 'kyc_config' => 'SELECT data FROM [kyc_config] WHERE id = 0'] as $t => $sql) {
            try {
                $q = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
                $q->execute([$t]);
                if ($q->fetchColumn() === false) continue;
                $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                throw new \RuntimeException('audience_unreadable:' . $t);
            }
            if ($t === 'retailers') {
                foreach ($rows as $j) { $r = json_decode((string)$j, true); if (is_array($r)) $out['retailers'][] = $r; }
            } else {
                $r = $rows === [] ? [] : json_decode((string)$rows[0], true);
                $out['kyc_config'] = is_array($r) ? $r : [];
            }
        }
        return $out;
    }

    /** A read-only stand-in for the store: retailers.json, kyc_config.json and wa_evo_numbers.json; [] for any other file. */
    public static function reader(\PDO $pdo, array $retailerRows, array $storedConfig)
    {
        return new class($pdo, $retailerRows, $storedConfig) {
            private $pdo; private $retailers; private $kyc;
            public function __construct(\PDO $pdo, array $r, array $k) { $this->pdo = $pdo; $this->retailers = $r; $this->kyc = $k; }
            public function load(string $file): array
            {
                if ($file === 'retailers.json') return $this->retailers;
                if ($file === 'kyc_config.json') return $this->kyc;
                if ($file === \InternalNumbers::REPORTED_FILE) return \InternalNumbers::reader($this->pdo)->load($file);
                return [];
            }
        };
    }

    // ── the lists ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every exclusion list, each loaded once, for every phone or one ($phone, international digits). $atUtc bounds the
     * one list with a storage time that C-O2-9 re-judges through here, the partner contacts (created_at at or before it);
     * the store lists record no time and ignore it. $now is the moment the windows are counted back from: given by
     * build() and judge(), else $atUtc, else the clock. The warm and recent windows are $config's offers_warm_days and
     * offers_recent_days (the sender passes the offer's recorded values; ruling R18).
     *
     * @return array the sets, the loose last-nine sets, the two coded maps, the recent-offer ids, the DishNet numbers,
     *               the tenant and the loose counts
     */
    public static function lists(\PDO $pdo, array $config, ?string $dataDir, array $retailerRows, array $storedConfig,
                                 ?string $phone, ?string $atUtc = null, ?int $now = null): array
    {
        $m = $now ?? ($atUtc !== null && UtcClock::parse($atUtc) > 0 ? UtcClock::parse($atUtc) : time());
        $t = \TenantProfile::current($config, $dataDir);
        $set = OfferSwitch::settings($config);
        $warm = (int)($set['offers_warm_days'] ?? OfferSwitch::SETTINGS['offers_warm_days'][0]);
        $recent = (int)($set['offers_recent_days'] ?? OfferSwitch::SETTINGS['offers_recent_days'][0]);
        $sinceWarm = UtcClock::stamp($m - $warm * 86400);
        $since48 = UtcClock::stamp($m - self::HOLD_48H_S);
        $sinceRecent = UtcClock::stamp($m - $recent * 86400);
        $l9 = $phone === null ? '' : self::last9($phone);

        $L = ['moment' => $m, 'tenant' => $t, 'sets' => [], 'loose' => [], 'maps' => [], 'recent_ids' => [], 'counts' => [],
              'internal' => null];
        foreach (self::LIST_CODES as $c) { $L['sets'][$c] = []; $L['loose'][$c] = []; }
        $add = function (string $code, string $raw) use (&$L, $t, $phone): void {
            $d = self::digits($raw, $t);
            if ($d !== null) {
                if ($phone === null || $d === $phone) $L['sets'][$code][$d] = true;
                return;
            }
            $k = self::last9($raw);
            if ($k === '') return;
            if ($phone !== null && $k !== self::last9($phone)) return;
            $L['loose'][$code][$k] = true;
            $L['counts'][$code . '_loose'] = ($L['counts'][$code . '_loose'] ?? 0) + 1;
        };
        $col = function (string $list, string $sql, array $args = []) use ($pdo): array {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
            } catch (\Throwable $e) {
                throw new \RuntimeException('audience_unreadable:' . $list, 0, $e);
            }
        };
        // A conversation read limited to one phone's numbers: last nine digits in SQL, the one form in PHP.
        $conv = $phone === null ? '' : ' AND substr(c.phone, -9) = :l9';
        $convArgs = $phone === null ? [] : [':l9' => $l9];

        foreach ($col('opted_out', 'SELECT phone FROM contact_optouts WHERE active = 1') as $p) $add('opted_out', $p);
        foreach ($col('offer_stop', 'SELECT DISTINCT phone FROM wa_offer_recipients WHERE stop_at IS NOT NULL AND phone IS NOT NULL') as $p) $add('offer_stop', $p);
        foreach ($col('unread_inbound', "SELECT phone FROM wa_seen_marks WHERE direction = 'in' AND phone <> '' AND seen_at >= :w",
                      [':w' => $sinceWarm]) as $p) $add('unread_inbound', $p);
        foreach ($retailerRows as $r) {
            if (!is_array($r)) continue;
            $raw = trim((string)($r['phone'] ?? ''));
            if ($raw === '') continue;
            $intl = \StaffDirectory::phoneOf($r, $t);
            $add('colleague', $intl !== null ? $intl : $raw);
        }
        foreach ($col('colleague', "SELECT DISTINCT c.phone FROM wa_conversations c WHERE c.category = 'staff'{$conv}", $convArgs) as $p) $add('colleague', $p);
        foreach ($col('partner_contact', 'SELECT phone FROM dist_contacts' . ($atUtc !== null ? ' WHERE created_at <= :at' : ''),
                      $atUtc !== null ? [':at' => UtcClock::stamp(UtcClock::parse($atUtc))] : []) as $p) $add('partner_contact', $p);
        foreach ($col('followup_open', 'SELECT DISTINCT phone FROM followups WHERE closed_at IS NULL') as $p) $add('followup_open', $p);
        foreach ($col('followup_recent', 'SELECT DISTINCT phone FROM followup_sends WHERE sent_at >= :s', [':s' => $since48]) as $p) $add('followup_recent', $p);
        foreach ($col('followup_recent', "SELECT DISTINCT phone FROM wa_auto_sends WHERE kind IN ('quote','enquiry')
                                            AND (outcome IN ('sent','uncertain') OR outcome IS NULL) AND claimed_at >= :s AND phone IS NOT NULL",
                      [':s' => $since48]) as $p) $add('followup_recent', $p);
        try {
            $st = $pdo->prepare("SELECT id, phone FROM wa_offer_recipients WHERE phone IS NOT NULL
                                  AND ((state IN ('sent','uncertain') AND sent_at >= :r) OR state = 'claimed')");
            $st->execute([':r' => $sinceRecent]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $d = self::digits((string)$r['phone'], $t);
                if ($d === null || ($phone !== null && $d !== $phone)) continue;
                $L['sets']['recent_offer'][$d] = true;
                $L['recent_ids'][$d][] = (int)$r['id'];
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:recent_offer');
        }
        foreach ($col('ambiguous_identity', "SELECT DISTINCT c.phone FROM wa_conversations c WHERE c.crm_link_method = 'ambiguous'{$conv}", $convArgs) as $p) {
            $add('ambiguous_identity', $p);
        }

        // Part F's two readers (ruling R3): the quotation hold and the person rule, at the moment, each phone in the one form.
        $at = UtcClock::stamp($m);
        try {
            $holds = $phone === null ? QuoteChase::offerHolds($pdo, $at) : self::one($phone, QuoteChase::offerHold($pdo, $phone, $at));
            $people = $phone === null ? HumanTakeover::phonesSince($pdo, UtcClock::stamp($m - self::PERSON_72H_S), $at)
                                      : self::one($phone, (string)HumanTakeover::phoneSince($pdo, $phone, UtcClock::stamp($m - self::PERSON_72H_S), $at));
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:partf');
        }
        foreach (['quote_hold' => $holds, 'person_handling' => $people] as $code => $map) {
            foreach ($map as $p => $why) {
                $d = self::digits((string)$p, $t);
                if ($d === null || ($phone !== null && $d !== $phone)) continue;
                $L['sets'][$code][$d] = true;
                $L['maps'][$code][$d] = (string)$why;
            }
        }

        // The streamed inbound read: the phone's own messages within the warm window, at any length (S2-07; ruling R3).
        try {
            $st = $pdo->prepare("SELECT c.phone, m.body, m.media_type FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                                  WHERE m.direction = 'in' AND m.sent_at >= :w{$conv}");
            $st->execute([':w' => $sinceWarm] + $convArgs);
            while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
                $d = self::digits((string)$r['phone'], $t);
                if ($d === null || ($phone !== null && $d !== $phone)) continue;
                $body = (string)($r['body'] ?? '');
                if ((string)($r['media_type'] ?? '') === 'audio') $L['sets']['recent_voice_note'][$d] = true;
                if ($body !== '' && OfferText::stopWordIn($body) !== '') $L['sets']['stop_words'][$d] = true;
                if ($body !== '' && !empty(\EmailReplyPolicy::scanForEscalation($body)['escalate'])) $L['sets']['escalation_words'][$d] = true;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:inbound');
        }

        // DishNet's own lines, line owners and alert recipients: InternalNumbers over the read-only reader, never a store.
        try {
            $reader = self::reader($pdo, $retailerRows, $storedConfig);
            $evo = \EvolutionApiService::forStore($config, $pdo, $dataDir);
            $in = null;
            if ($evo->registryOn()) {
                $evo->useStaffStore($reader);
                $in = $evo->automationPolicy()->internal();
            }
            if ($in === null) {
                $in = \InternalNumbers::fromRows([], \EvolutionApiService::configInstanceMap($config), [], $config, $dataDir);
                $in->useStore($reader);
            }
            $L['internal'] = $in;
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:dishnet_number');
        }
        return $L;
    }

    /** A single-phone reader's answer as the bulk form's map. */
    private static function one(string $phone, string $why): array
    {
        return $why === '' ? [] : [$phone => $why];
    }

    /**
     * The first reason $digits is left out, in LIST_CODES order, after the number rule; '' when none. $exceptRecipient is
     * the row judge() is deciding, whose own claim is not a recent offer.
     */
    public static function excludedBy(array $L, string $digits, int $exceptRecipient = 0): string
    {
        /** @var \TenantProfile $t */
        $t = $L['tenant'];
        $dial = (string)preg_replace('/\D+/', '', $t->dialCode());
        if ($dial === '' || !preg_match('/^' . $dial . '\d{' . $t->nsnLength() . '}$/', $digits)) {
            return strpos($digits, '211') === 0 ? 'south_sudan_number' : 'not_uganda_number';
        }
        $k = self::last9($digits);
        foreach (self::LIST_CODES as $code) {
            if ($code === 'dishnet_number') {
                if ($L['internal'] !== null && $L['internal']->senderClass($digits, false) !== '') return $code;
                continue;
            }
            if ($code === 'recent_offer') {
                $ids = array_diff($L['recent_ids'][$digits] ?? [], [$exceptRecipient]);
                if ($ids !== []) return $code;
                continue;
            }
            if (isset($L['sets'][$code][$digits]) || ($k !== '' && isset($L['loose'][$code][$k]))) return $code;
        }
        return '';
    }

    // ── build ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The audience at $now: every warm phone on an Evolution number, warmest first, cut to $size; and everyone left out,
     * by reason (counts only). $gw: the gateway whose routing names the handsets (built over $pdo and reader() unless given).
     *
     * @return array{eligible: array, queued: int, left_out: array<string,int>, waiting: array<string,int>,
     *               by_handset: array<string,int>, loose: array<string,int>, warm: int}
     */
    public static function build(\PDO $pdo, array $config, ?string $dataDir, array $retailerRows, array $storedConfig, string $size,
                                 int $now, ?OfferGateway $gw = null): array
    {
        if (!array_key_exists($size, self::SIZES)) throw new \InvalidArgumentException('size');
        $gw = $gw ?? new EvolutionOfferGateway($pdo, $config, $dataDir, self::reader($pdo, $retailerRows, $storedConfig));
        $L = self::lists($pdo, $config, $dataDir, $retailerRows, $storedConfig, null, null, $now);
        $set = OfferSwitch::settings($config);
        $warm = (int)($set['offers_warm_days'] ?? OfferSwitch::SETTINGS['offers_warm_days'][0]);
        $owned = (int)($set['offers_on_owned_numbers'] ?? 0) === 1;
        $owners = self::ownerTypes($pdo);

        $byPhone = [];
        $left = [];
        foreach (self::warmConversations($pdo, UtcClock::stamp($now - $warm * 86400), null) as $c) {
            $d = self::digits((string)$c['phone'], $L['tenant']);
            $key = $d ?? ('?' . (string)$c['phone']);
            $h = $gw->handsetFor((string)$c['channel']);
            if ($h === '') continue;                       // accounts, marketing, an unmapped channel: never a candidate
            $c['handset'] = $h;
            $byPhone[$key][] = $c;
        }
        $eligible = [];
        $waiting = [];
        foreach ($byPhone as $key => $convs) {
            $key = (string)$key;                           // a digits key is stored as an int by PHP
            $waitCode = '';
            if ($key[0] === '?') { $left['not_uganda_number'] = ($left['not_uganda_number'] ?? 0) + 1; continue; }
            $d = (string)$key;
            $why = self::excludedBy($L, $d);
            $ch = self::chosen($convs);
            if ($why === '') $why = self::channelRule($gw, $owners, (string)$ch['channel'], $d, $owned, $waitCode);
            if ($why !== '') { $left[$why] = ($left[$why] ?? 0) + 1; continue; }
            if ($waitCode !== '') $waiting[$waitCode] = ($waiting[$waitCode] ?? 0) + 1;
            $eligible[] = ['phone' => $d, 'conversation_id' => (int)$ch['id'], 'channel' => (string)$ch['channel'],
                           'handset' => (string)$ch['handset'], 'last_in_at' => (string)$ch['last_customer_at']];
        }
        usort($eligible, function ($a, $b) {
            return [UtcClock::parse($b['last_in_at']), $a['phone']] <=> [UtcClock::parse($a['last_in_at']), $b['phone']];
        });
        $n = self::SIZES[$size];
        $cut = $n === 0 ? $eligible : array_slice($eligible, 0, $n);
        $byH = [];
        foreach ($cut as $e) $byH[$e['handset']] = ($byH[$e['handset']] ?? 0) + 1;
        ksort($left);
        return ['eligible' => $cut, 'queued' => count($cut), 'all_eligible' => count($eligible), 'left_out' => $left,
                'waiting' => $waiting, 'by_handset' => $byH, 'loose' => $L['counts'], 'warm' => $warm];
    }

    // ── judge ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * One recipient, just before its send, at its moment $nowUtc (the sender's judged_at, under the book's write lock).
     * $config carries the offer's recorded warm_days and recent_days as offers_warm_days and offers_recent_days.
     *
     * @return array{outcome:string, code:string, retry_at:?int, conversation_id?:int, channel?:string, handset?:string}
     */
    public static function judge(\PDO $pdo, array $config, ?string $dataDir, array $retailerRows, array $storedConfig, array $row,
                                 string $nowUtc, ?OfferGateway $gw = null): array
    {
        $phone = $row['phone'] ?? null;
        if ($phone === null || (string)$phone === '') return self::res('skip', 'erased');
        $phone = (string)$phone;
        $m = UtcClock::parse($nowUtc);
        if ($m <= 0) return self::res('skip', 'unreadable_time');
        $gw = $gw ?? new EvolutionOfferGateway($pdo, $config, $dataDir, self::reader($pdo, $retailerRows, $storedConfig));

        // 1. every list exclusion, read fresh for this phone
        $L = self::lists($pdo, $config, $dataDir, $retailerRows, $storedConfig, $phone, null, $m);
        $why = self::excludedBy($L, $phone, (int)($row['id'] ?? 0));
        if ($why !== '') return self::res('skip', $why);

        // 2. cooled: no inbound on any Evolution conversation of the phone within the warm window
        $set = OfferSwitch::settings($config);
        $warm = (int)($set['offers_warm_days'] ?? OfferSwitch::SETTINGS['offers_warm_days'][0]);
        $convs = [];
        foreach (self::warmConversations($pdo, UtcClock::stamp($m - $warm * 86400), $phone) as $c) {
            if (self::digits((string)$c['phone'], $L['tenant']) !== $phone) continue;
            $h = $gw->handsetFor((string)$c['channel']);
            if ($h === '') continue;
            $c['handset'] = $h;
            $convs[] = $c;
        }
        if ($convs === []) return self::res('skip', 'cooled');

        // 3. moved: the customer wrote on another handset since; never sent from the old one
        $ch = self::chosen($convs);
        if ((int)$ch['id'] !== (int)($row['conversation_id'] ?? 0) || (string)$ch['handset'] !== (string)($row['handset'] ?? '')) {
            return ['outcome' => 'move', 'code' => 'moved', 'retry_at' => null, 'conversation_id' => (int)$ch['id'],
                    'channel' => (string)$ch['channel'], 'handset' => (string)$ch['handset']];
        }

        // 4. waiting for an answer: an offer never takes the place of one
        $lastIn = UtcClock::parse((string)$ch['last_customer_at']);
        $lastAgent = UtcClock::parse((string)($ch['last_agent_at'] ?? ''));
        if ($lastIn > $lastAgent || $m - $lastIn < self::AWAIT_REPLY_S) {
            $at = $lastIn + self::AWAIT_REPLY_S;
            return self::res('defer', 'awaiting_reply', $at > $m ? $at : $m + self::CHANNEL_WAIT_S);
        }

        // 5. the channel: a partner's number, an owned number, the policy
        $owned = (int)($set['offers_on_owned_numbers'] ?? 0) === 1;
        $waitCode = '';
        $why = self::channelRule($gw, self::ownerTypes($pdo), (string)$ch['channel'], $phone, $owned, $waitCode);
        if ($why !== '') return self::res('skip', $why);
        if ($waitCode !== '') return self::res('defer', $waitCode, $m + self::CHANNEL_WAIT_S);
        return self::res('ok', '');
    }

    private static function res(string $outcome, string $code, ?int $retryAt = null): array
    {
        return ['outcome' => $outcome, 'code' => $code, 'retry_at' => $retryAt];
    }

    // ── the chosen conversation and its channel ────────────────────────────────────────────────────────────────

    /** Conversations with a customer message since $since (UTC), every phone's or one phone's. */
    private static function warmConversations(\PDO $pdo, string $since, ?string $phone): array
    {
        try {
            $sql = 'SELECT c.id, c.phone, c.channel, c.category, c.crm_link_method, c.last_customer_at, c.last_agent_at
                      FROM wa_conversations c
                     WHERE c.last_customer_at >= :since_warm';
            $args = [':since_warm' => $since];
            if ($phone !== null) { $sql .= ' AND substr(c.phone, -9) = :l9'; $args[':l9'] = self::last9($phone); }
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:conversations');
        }
    }

    /** The conversation the customer last wrote on: the greatest last_customer_at; ties sales, support, account, then id. */
    private static function chosen(array $convs): array
    {
        usort($convs, function ($a, $b) {
            $ka = [-UtcClock::parse((string)$a['last_customer_at']), self::CHANNEL_ORDER[(string)$a['channel']] ?? 3, (string)$a['channel']];
            $kb = [-UtcClock::parse((string)$b['last_customer_at']), self::CHANNEL_ORDER[(string)$b['channel']] ?? 3, (string)$b['channel']];
            return $ka <=> $kb;
        });
        return $convs[0];
    }

    /** wa_channels' owner_type by channel id; [] without the registry table. */
    private static function ownerTypes(\PDO $pdo): array
    {
        try {
            $q = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'wa_channels'");
            if ($q->fetchColumn() === false) return [];
            $out = [];
            foreach ($pdo->query('SELECT channel_id, owner_type FROM wa_channels')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['channel_id']] = (string)$r['owner_type'];
            }
            return $out;
        } catch (\Throwable $e) {
            throw new \RuntimeException('audience_unreadable:wa_channels');
        }
    }

    /**
     * Whether the chosen channel may carry this offer: partner_number | owned_number | channel_refused:<code> (final), or
     * '' with $waitCode channel_waiting:<code> when the policy's reason may clear by itself.
     */
    private static function channelRule(OfferGateway $gw, array $owners, string $channel, string $phone, bool $owned, ?string &$waitCode): string
    {
        $waitCode = '';
        $type = $owners[$channel] ?? 'department';
        if ($type === 'partner') return 'partner_number';
        if ($type === 'staff' && !$owned) return 'owned_number';
        $r = $gw->refusal($channel, $phone);
        if ($r === '') return '';
        if ($gw->transient($r)) { $waitCode = 'channel_waiting:' . $r; return ''; }
        return 'channel_refused:' . $r;
    }
}
