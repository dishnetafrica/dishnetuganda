<?php
/**
 * guide.php — checks the WORDS of R0's hand-over against what the rehearsal measured (scripts/harness/bank-recon-r0/
 * rehearse-run.sh, cases H2 and GD). A hand-over that tells the operator something the line or the log does not do is a
 * defect even when the line and the runner are right; these checks make such a sentence fail.
 *
 *   php guide.php placeholder <file>          the paragraph about the placeholder pins says what that line really does:
 *                                             refused at `git cat-file` after its fetch, an empty runner file left behind
 *                                             (which `noclobber` then refuses to overwrite) — and never that sha256sum
 *                                             stops it or that it runs nothing
 *   php guide.php exits <file>                the exit table has a row for every exit the runner can end with: 0–6, the
 *                                             interruptions 129 / 130 / 143 (no RESULT line, not retried) and 255 or other
 *   php guide.php silence <file>              the guide says R0 may print nothing for up to two minutes while it waits
 *   php guide.php readtwice <file> <log>…     the read-twice line the guide quotes, read as a pattern (a part in square
 *                                             brackets is optional, "…" is any text), matches the read-twice line of every
 *                                             log given — one that waited and one that did not
 *
 * Exit 0 when the check holds; 1 (with the reason on stdout) when it does not; 2 on a usage error.
 */
declare(strict_types=1);
[$_, $what, $file] = $argv + [null, '', ''];
$s = is_file($file) ? (string)file_get_contents($file) : '';
if ($s === '') { echo "no such file or empty: $file\n"; exit(2); }
$no = static function (string $why): void { echo $why, "\n"; exit(1); };

if ($what === 'placeholder') {
    // the paragraph: from the first line naming the placeholder pins to the first blank line (a '#' comment block counts
    // as text, its '#' removed)
    $lines = explode("\n", $s); $para = ''; $on = false;
    foreach ($lines as $l) {
        $t = preg_replace('/^#\s?/', '', $l);
        if (!$on && stripos($t, 'placeholder') !== false) $on = true;
        if ($on && trim((string)$t) === '') break;
        if ($on) $para .= ' ' . trim((string)$t);
    }
    if ($para === '') $no('no paragraph about the placeholder pins');
    foreach (['git cat-file' => 'it does not say the line is refused at git cat-file', 'fetch' => 'it does not say the fetch has run',
              'empty' => 'it does not say an empty runner file is left behind', 'noclobber' => 'it does not say noclobber then refuses to overwrite it'] as $need => $why) {
        if (stripos($para, $need) === false) $no($why);
    }
    if (preg_match('/sha256sum\W+(refuses|prints FAILED)|nothing runs|runs nothing/i', $para, $m)) $no('it still says: ' . $m[0]);
    echo "ok\n"; exit(0);
}
if ($what === 'exits') {
    foreach (['0', '1', '2', '3', '4', '5', '6', '129 / 130 / 143', '255 or other'] as $e) {
        if (!preg_match('/^\| ' . preg_quote($e, '/') . ' \|/m', $s)) $no("no row for exit $e");
    }
    preg_match('/^\| 129 \/ 130 \/ 143 \|([^\n]*)$/m', $s, $m);
    $row = (string)($m[1] ?? '');
    foreach (['no RESULT line', 'not retried', 'writes nothing'] as $need) if (stripos($row, $need) === false) $no("the interrupted row does not say: $need");
    echo "ok\n"; exit(0);
}
if ($what === 'silence') {
    if (!preg_match('/print nothing for up to two minutes/i', $s)) $no('it does not say R0 may print nothing for up to two minutes');
    echo "ok\n"; exit(0);
}
if ($what === 'readtwice') {
    if (!preg_match('/`(read twice, immutable[^`]*)`/', $s, $m)) $no('no quoted read-twice line');
    $q = (string)$m[1];
    // a part in square brackets is optional; "…" is any text; everything else is literal
    $re = ''; $rest = $q;
    while ($rest !== '') {
        if (preg_match('/^\[([^\]]*)\]/', $rest, $b)) {
            $re .= '(?:' . str_replace('N', '[0-9]+', preg_quote((string)$b[1], '/')) . ')?'; $rest = substr($rest, strlen((string)$b[0]));
        } elseif (strncmp($rest, '…', strlen('…')) === 0) { $re .= '.*'; $rest = substr($rest, strlen('…')); }
        else { $re .= preg_quote($rest[0], '/'); $rest = substr($rest, 1); }
    }
    $logs = array_slice($argv, 3);
    if (!$logs) { echo "no log given\n"; exit(2); }
    foreach ($logs as $lf) {
        $line = '';
        foreach (explode("\n", (string)@file_get_contents($lf)) as $l) if (strpos($l, 'read twice, immutable') !== false) { $line = trim($l); break; }
        if ($line === '') $no("no read-twice line in $lf");
        if (!preg_match('/^' . $re . '$/u', $line)) $no("the guide's quoted read-twice line does not match the line of " . basename($lf) . ': ' . $line);
    }
    echo "ok\n"; exit(0);
}
echo "usage: guide.php placeholder|exits|silence|readtwice <file> [log…]\n"; exit(2);
