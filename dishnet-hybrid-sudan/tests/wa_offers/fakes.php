<?php
declare(strict_types=1);
/**
 * The scripted gateway of tests/wa_offers (5.18.104, root docs/73 §H.4.16), declared once the copy's OfferGateway
 * interface is loaded (tests/test_wa_offers.php requires this after wo_load()). TEST ONLY.
 */
/**
 * The scripted gateway: handsets by channel, refusals by channel, and send() answers from $results in turn (default
 * SENT with a fresh id, echo claimed). $onSend runs while the call is in progress (a row stored, the clock advanced).
 */
final class WoGateway implements OfferGateway
{
    public $log; public $handsets; public $refusals = []; public $transientCodes = ['internal_numbers_incomplete', 'channel_paused'];
    public $results = []; public $onSend = null; public $n = 0;
    public function __construct(WoLog $log, array $handsets = ['sales' => 'i:sales-ug', 'support' => 'i:dishnet-ug', 'account' => 'i:dishnet-ug'])
    { $this->log = $log; $this->handsets = $handsets; }
    public function name(): string { return 'test'; }
    public function handsetFor(string $channel): string { return $this->handsets[$channel] ?? ''; }
    public function refusal(string $channel, string $phone): string { return $this->refusals[$channel] ?? ''; }
    public function transient(string $code): bool { return in_array($code, $this->transientCodes, true); }
    public function send(OfferMessage $m): OfferSendResult
    {
        $this->log->calls[] = ['send', $m->channel, $m->phone, $m->text, $m->image !== null, $m->echoEvent, $m->class];
        if ($this->onSend !== null) ($this->onSend)($m);
        $r = array_shift($this->results);
        $this->n++;
        return $r ?? new OfferSendResult(OfferSendResult::SENT, '', 'WO-SENT-' . $this->n, false, 'claimed');
    }
}


/**
 * A connection on a book whose prepare() can run a callback just before a chosen statement is prepared: another process's
 * write placed between two of the sender's statements, deterministically (the concurrent cases of F and K).
 */
final class WoRacePdo extends \PDO
{
    /** @var array<int,array{0:string,1:callable}> [needle, callback]: the first statement containing the needle fires it, once */
    public $hooks = [];
    /** @var callable|null run before every statement is prepared, with its text */
    public $always = null;
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->always !== null) ($this->always)((string)$query);
        foreach ($this->hooks as $i => [$needle, $fn]) {
            if (strpos((string)$query, $needle) !== false) { unset($this->hooks[$i]); $fn(); break; }
        }
        return parent::prepare($query, $options);
    }
}

/** The plugin store over its own book with a WoRacePdo for getPdo(); every collection read and written by the real store. */
final class WoStore implements StoreInterface
{
    public $inner; public $pdo; public $onLoad = null;
    public function __construct(SqliteStore $inner, string $dataDir)
    {
        $this->inner = $inner;
        $this->pdo = new WoRacePdo('sqlite:' . rtrim($dataDir, '/') . '/plugin.sqlite3');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }
    public function getPdo(): \PDO { return $this->pdo; }
    public function load(string $file): array { if ($this->onLoad !== null) ($this->onLoad)($file); return $this->inner->load($file); }
    public function save(string $file, array $data): void { $this->inner->save($file, $data); }
    public function append(string $file, array $record): array { return $this->inner->append($file, $record); }
    public function findOne(string $file, string $key, $value): ?array { return $this->inner->findOne($file, $key, $value); }
    public function findAll(string $file, string $key, $value): array { return $this->inner->findAll($file, $key, $value); }
    public function updateOne(string $file, string $key, $value, array $updates): bool { return $this->inner->updateOne($file, $key, $value, $updates); }
    public function nextId(string $file): int { return $this->inner->nextId($file); }
    public function appendWithId(string $file, array $record): array { return $this->inner->appendWithId($file, $record); }
    public function path(string $file): string { return $this->inner->path($file); }
    public function withLock(string $file, callable $fn) { return $this->inner->withLock($file, $fn); }
}
