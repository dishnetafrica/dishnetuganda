<?php
declare(strict_types=1);

/**
 * MediaBlob — fetched media, in memory only (Batch 1, docs/55 §9).
 *
 * The bytes exist for the length of one worker turn. Nothing here writes them anywhere, nothing logs them, and
 * wipe() is called by the worker when it is done, whatever the outcome. What survives the turn is on the wa_media
 * row: size, media type and sha256. PHP 7.4 compatible.
 */
final class MediaBlob
{
    /** @var string */
    public $bytes;
    /** @var string */
    public $mimetype;
    /** @var int */
    public $size;
    /** @var string */
    public $sha256;
    /** @var string */
    public $kind;
    /** @var string */
    public $fileName;

    public function __construct(string $bytes, string $mimetype, string $kind, string $fileName = '')
    {
        $this->bytes    = $bytes;
        $this->mimetype = $mimetype;
        $this->size     = strlen($bytes);
        $this->sha256   = hash('sha256', $bytes);
        $this->kind     = $kind;
        $this->fileName = $fileName;
    }

    /** Forget the bytes. The hash and size stay, which is all a record may keep. */
    public function wipe(): void
    {
        $this->bytes = '';
    }

    /** Never the bytes: what a log line may say about this blob. */
    public function describe(): string
    {
        return sprintf('%s %s %d bytes sha256=%s…', $this->kind, MediaPolicy::baseMime($this->mimetype), $this->size,
                       substr($this->sha256, 0, 12));
    }
}
