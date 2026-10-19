<?php
/**
 * CashbookNonCashView — the helpers of the Cashbook's read-only "Bank & other accounts" tab and of its export
 * (5.18.98; SPEC-separate-v2 §2.3). Uganda only: the page and the export use them only under rule A.
 *
 * It holds no SQL of its own. It reads through CashbookService only: countFiltered, nonCashAccounts and
 * accountLabels. Nothing here writes anything, and nothing here adds amounts: the tab shows no balance and no
 * total, because the book holds only part of Ecobank's history.
 *
 * PHP 7.4 and 8.1: no match, no str_* helpers, no named arguments, no enums, no readonly.
 */

require_once __DIR__ . '/CashbookService.php';

final class CashbookNonCashView
{
    /**
     * The tab's buttons: one per bank account that is active, or has at least one listed row in this project, then
     * always 'other' (every other not-cash row: stock, supplier, director, share capital, payable, receivable, an id
     * naming no account, and a bank account whose id cannot have a button: 0, negative, text, real). No "All" button, so two
     * bank accounts are never listed together. Each button carries its count under the current search and dates, its
     * account's active flag and currency (the ECO Bank link uses both). Together the buttons hold every not-cash row
     * exactly once: nonCashAccounts() and the 'other' clause share one rule for which ids get a button.
     *
     * @return array<int, array{key: int|string, name: string, currency: string, active: bool, count: int}>
     */
    public static function chips(CashbookService $cb, string $proj, string $search, string $from, string $to): array
    {
        $scope = array_filter(['project' => $proj, 'search' => $search, 'date_from' => $from, 'date_to' => $to, 'book' => 'noncash']);
        $whole = array_filter(['project' => $proj, 'book' => 'noncash']);
        $chips = [];
        foreach ($cb->nonCashAccounts() as $a) {
            $id = $a['id'];
            if (!is_int($id) || $id <= 0) continue;                          // a second guard: nonCashAccounts() lists only ids above 0
            if (!$a['active'] && $cb->countFiltered($whole + ['book_account' => $id]) === 0) continue;
            $chips[] = [
                'key'      => $id,
                'name'     => self::mask($a['name']),
                'currency' => $a['currency'],
                'active'   => $a['active'],
                'count'    => $cb->countFiltered($scope + ['book_account' => $id]),
            ];
        }
        $chips[] = [
            'key'      => 'other',
            'name'     => 'Other accounts',
            'currency' => '',
            'active'   => false,
            'count'    => $cb->countFiltered($scope + ['book_account' => 'other']),
        ];
        return $chips;
    }

    /**
     * The button in view: cb_acct when it is 'other' or a string of digits naming a listed button, compared as text,
     * never cast (so '7abc' names nothing). Otherwise, while searching, the first button with a match; else the first
     * button (the book's first currency's bank account first).
     *
     * @param array $chips what chips() returned
     * @param mixed $get   the request's query ($_GET)
     * @return int|string  an account id, or 'other'
     */
    public static function selected(array $chips, $get, bool $searching)
    {
        $want = (is_array($get) && isset($get['cb_acct']) && is_string($get['cb_acct'])) ? $get['cb_acct'] : '';
        if ($want === 'other' || ($want !== '' && ctype_digit($want))) {
            foreach ($chips as $c) {
                if ((string)$c['key'] === $want) return $c['key'];
            }
        }
        if ($searching) {
            foreach ($chips as $c) {
                if ($c['count'] > 0) return $c['key'];
            }
        }
        return $chips ? $chips[0]['key'] : 'other';
    }

    /**
     * A row's account, for the tab and its export, HTML-escaped (the export decodes it with htmlspecialchars_decode).
     * The id is looked up only when it is an integer or a string of digits, and never cast, so a text id such as '7abc'
     * never reads as account 7. Otherwise, or when no account has that id: "account not found (#…)". A run of five or
     * more digits in a name shows as •••• and its last four (the rule of tabs/accounts/bank.php, copied here).
     */
    public static function label($accountId, array $labels): string
    {
        $raw = is_scalar($accountId) ? (string)$accountId : '';
        if ((is_int($accountId) || ctype_digit($raw)) && isset($labels[$raw]) && is_array($labels[$raw])) {
            return self::esc(self::mask((string)($labels[$raw]['name'] ?? '')));
        }
        return self::esc('account not found (#' . $raw . ')');
    }

    /**
     * A row's state in words. "Awaiting approval" is never called "pending": on this page "Pending" is the Pending
     * tab's validation_status = 'pending'. Any other stored value is given as it is (the caller escapes it).
     */
    public static function state(string $status): string
    {
        $map = [
            'approved'         => 'approved',
            'pending_approval' => 'awaiting approval',
            'rejected'         => 'rejected',
            'voided'           => 'voided',
        ];
        return $map[$status] ?? $status;
    }

    /** The flag a currency's button wears: the map of tabs/accounts/bank.php (the Cashbook's own). */
    public static function flag(string $cur): string
    {
        $map = ['USD' => "\u{1F4B5}", 'SSP' => "\u{1F1F8}\u{1F1F8}", 'UGX' => "\u{1F1FA}\u{1F1EC}"];
        return $map[$cur] ?? "\u{1F4B1}";
    }

    /** A run of five or more digits shows as •••• and its last four. */
    private static function mask(string $name): string
    {
        return (string)preg_replace_callback('/\d{5,}/', function (array $m): string {
            return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr($m[0], -4);
        }, $name);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
