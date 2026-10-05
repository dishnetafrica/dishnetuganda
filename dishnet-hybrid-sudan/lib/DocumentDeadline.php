<?php
declare(strict_types=1);

/**
 * The document readers' three small shared types (Batch 4 of the AI communication layer, docs/58 §4, §6).
 *
 * DocumentRefused   — a reader could not or would not go on: a FIXED reason code (DocumentExtraction::REASONS) and a short
 *                     detail that names a size, a count, a member or a class — never a line of the document.
 * DocumentTooSlow   — the deadline passed. Permanent, deliberately: a deterministic extraction that was slow once will be
 *                     slow again, so unlike a provider timeout it is not retried.
 * DocumentDeadline  — the time budget, checked between bounded steps (a zip member, a thousand XML nodes, two hundred rows,
 *                     a PDF object). PHP cannot interrupt a loop, so every loop is bounded and asks between its steps.
 *
 * PHP 7.4 compatible.
 */
class DocumentRefused extends \RuntimeException
{
    /** @var string */
    public $reason;
    /** @var string */
    public $detail;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        $this->detail = $detail;
        parent::__construct($reason . ($detail !== '' ? ' (' . $detail . ')' : ''));
    }
}

final class DocumentTooSlow extends DocumentRefused
{
    public function __construct(string $step)
    {
        parent::__construct('too_slow', 'deadline passed at ' . $step);
    }
}

final class DocumentDeadline
{
    /** @var float */
    private $until;
    /** @var int */
    public $checks = 0;

    public function __construct(float $seconds)
    {
        $this->until = microtime(true) + max(0.0, $seconds);
    }

    /** @throws DocumentTooSlow */
    public function check(string $step): void
    {
        $this->checks++;
        if (microtime(true) >= $this->until) throw new DocumentTooSlow($step);
    }

    public function remaining(): float
    {
        return max(0.0, $this->until - microtime(true));
    }
}
