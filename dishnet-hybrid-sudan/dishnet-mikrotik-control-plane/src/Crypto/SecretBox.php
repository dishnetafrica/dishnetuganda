<?php
declare(strict_types=1);
namespace Dn\Crypto;

/**
 * Authenticated encryption for secrets at rest (docs/30 Artifact 7, principle 3).
 *
 * AEAD, not plain encryption: the device id is bound in as associated data, so
 * an envelope moved from one device row to another fails to open rather than
 * silently decrypting into the wrong device's credential.
 *
 * The key never lives in the database. If it did, a database dump would carry
 * both halves and the encryption would be decoration.
 */
final class SecretBox
{
    private string $key;

    public function __construct(?string $key = null)
    {
        $raw = $key ?? (getenv('DNB_SECRET_KEY') ?: '');
        if ($raw === '') {
            throw new \RuntimeException(
                'DNB_SECRET_KEY is not set. Refusing to run with an implicit or default key: '
                . 'a predictable key makes every envelope openable by anyone holding a dump.');
        }
        // Accept a passphrase or raw key material; normalise to 32 bytes.
        $this->key = hash('sha256', $raw, true);
    }

    public function seal(string $plaintext, string $aad = ''): string
    {
        $nonce = random_bytes(12);
        $tag   = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key,
                              OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($ct === false) { throw new \RuntimeException('seal failed'); }
        return 'v1.' . base64_encode($nonce . $tag . $ct);
    }

    public function open(string $envelope, string $aad = ''): string
    {
        if (!str_starts_with($envelope, 'v1.')) {
            throw new \RuntimeException('unrecognised envelope');
        }
        $raw = base64_decode(substr($envelope, 3), true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('malformed envelope'); }

        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key,
                              OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        if ($pt === false) {
            // Wrong key, tampered ciphertext, or an envelope from another
            // device. All three are the same answer: it does not open.
            throw new \RuntimeException('envelope did not open');
        }
        return $pt;
    }
}
