<?php
declare(strict_types=1);

/**
 * EfrisClientField — which tax field, if any, EFRIS invoicing reads a uCRM
 * client custom field as.
 *
 * One rule, in one place. EfrisInvoiceMapper uses it to find the buyer's TIN,
 * BRN, NIN, taxpayer type and buyer type on the client. Everything that WRITES
 * uCRM custom fields, or reads them for some other purpose, uses it to leave
 * those fields alone.
 *
 * Why that matters is measured, not theoretical: on the Uganda uCRM custom
 * field 1 is "EFRIS TIN" (key efrisTin), while the KYC create was written for
 * the South Sudan uCRM where field 1 is "Sales Person". Sending the agent's
 * name to field 1 there would have made it the customer's tax number.
 */
final class EfrisClientField
{
    /**
     * @param string $keyOrName the custom field's key, or its name when it has no key
     * @return string|null 'tin' | 'brn' | 'nin' | 'taxpayer_type' | 'buyer_type', or null
     */
    public static function of(string $keyOrName): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $keyOrName) ?? '');
        if ($key === '')                                  return null;
        if (substr($key, -3) === 'tin')                   return 'tin';
        if (substr($key, -3) === 'brn')                   return 'brn';
        if (substr($key, -3) === 'nin')                   return 'nin';
        if (strpos($key, 'taxpayertype') !== false)       return 'taxpayer_type';
        if (strpos($key, 'buyertype') !== false)          return 'buyer_type';
        return null;
    }

    /** True when either the key or the name of a uCRM custom field is a tax field. */
    public static function isTaxField(array $customField): bool
    {
        return self::of((string)($customField['key'] ?? '')) !== null
            || self::of((string)($customField['name'] ?? '')) !== null;
    }
}
