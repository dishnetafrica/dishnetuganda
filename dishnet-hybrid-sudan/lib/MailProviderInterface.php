<?php
declare(strict_types=1);

/**
 * MailProviderInterface — the seam between customer identities and whichever
 * system actually hosts the mailboxes.
 *
 * The plugin decides WHAT exists (john.doe@dishnetuganda.com, active or
 * suspended); the provider decides HOW. Every method is idempotent from the
 * caller's point of view: calling ensureMailbox twice for the same address
 * must succeed twice and leave one mailbox.
 *
 * Every method returns the uniform envelope used across this codebase:
 *   ['ok' => bool, 'data' => mixed|null, 'error' => string]
 * Nothing here throws for a remote failure — the EventBus retry loop decides
 * what a failure means.
 */
interface MailProviderInterface
{
    /** Human name for logs and the admin screen, e.g. 'stalwart'. */
    public function name(): string;

    /** True when the config carries enough to reach the provider. */
    public function isConfigured(): bool;

    /**
     * Create the mailbox if it does not exist. $quotaMb 0 = provider default.
     * Returns data = provider reference (id or name) on success.
     * The initial password is random and intentionally discarded — access is
     * granted later through resetPassword(), so no credential is ever stored
     * on the uCRM side.
     */
    public function ensureMailbox(string $email, string $displayName, int $quotaMb = 250): array;

    /** Disable login + delivery hold, keeping all mail. Retention, not deletion. */
    public function suspendMailbox(string $email): array;

    /** Reverse of suspendMailbox. */
    public function unsuspendMailbox(string $email): array;

    /**
     * Set a fresh random password and return it ONCE (data = the password).
     * The caller hands it to the customer over WhatsApp and must not store it.
     */
    public function resetPassword(string $email): array;
}
