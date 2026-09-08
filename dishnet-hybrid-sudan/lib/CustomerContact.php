<?php
/**
 * CustomerContact — the phone numbers and links customers are told to use.
 *
 * These were written into message copy across NotificationService, webhook.php
 * and several crons, so a Ugandan customer was handed a +211 number and a
 * dishnetafrica.com payment link. There are five distinct numbers in use and
 * they mean different things, so each keeps its own key rather than collapsing
 * into one "support number" that would send billing questions to the install
 * team.
 *
 * Every default is the exact string that call site has always printed. An
 * install that configures nothing sends what it sends today, byte for byte.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class CustomerContact
{
    /** key => the literal that has always been printed at these call sites. */
    const DEFAULTS = [
        'contact_accounts_phone'    => '+211 921 443 002',
        'contact_support_phone'     => '+211 921 443 006',
        'contact_sales_phone'       => '+211 921 443 009',
        'contact_escalation_phone'  => '+211 927 797 217',
        'contact_shop_phone'        => '0923 400 000',
        'contact_sales_wa'          => '211923400000',
        'contact_support_wa'        => '211921443002',
        'contact_technical_wa'      => '211921443006',
        'contact_pay_url'           => 'https://dishnetafrica.com/tutorials/index.html',
        'contact_app_url'           => 'https://dishnetafrica.com/get-the-app.html',
    ];

    private static function v(?array $config, string $key): string
    {
        $set = trim((string)(($config ?? [])[$key] ?? ''));
        return $set !== '' ? $set : (self::DEFAULTS[$key] ?? '');
    }

    /** Billing and payment questions. */
    public static function accounts(?array $c = null): string   { return self::v($c, 'contact_accounts_phone'); }
    /** Installation, faults and technical help. */
    public static function support(?array $c = null): string    { return self::v($c, 'contact_support_phone'); }
    /** Quotes and new business. */
    public static function sales(?array $c = null): string      { return self::v($c, 'contact_sales_phone'); }
    /** Escalation line printed on service-failure messages. */
    public static function escalation(?array $c = null): string { return self::v($c, 'contact_escalation_phone'); }
    /** Retail counter number. */
    public static function shop(?array $c = null): string       { return self::v($c, 'contact_shop_phone'); }
    /** wa.me numbers — digits only, no plus sign, as that link requires. */
    public static function salesWa(?array $c = null): string     { return self::v($c, 'contact_sales_wa'); }
    public static function supportWa(?array $c = null): string   { return self::v($c, 'contact_support_wa'); }
    public static function technicalWa(?array $c = null): string { return self::v($c, 'contact_technical_wa'); }
    /** Where a customer is sent to pay. */
    public static function payUrl(?array $c = null): string     { return self::v($c, 'contact_pay_url'); }
    /** Where a customer downloads the app. */
    public static function appUrl(?array $c = null): string     { return self::v($c, 'contact_app_url'); }

    /** Everything at once, for settings screens and doctors. */
    public static function all(?array $c = null): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) $out[$k] = self::v($c, $k);
        return $out;
    }

    /** The Uganda values, used by tools/set_email_brand.php --uganda. */
    const UGANDA = [
        'contact_accounts_phone'   => '+256 705 993 348',
        'contact_support_phone'    => '+256 705 993 348',
        'contact_sales_phone'      => '+256 705 993 348',
        'contact_escalation_phone' => '+256 705 993 348',
        'contact_shop_phone'       => '+256 705 993 348',
        'contact_sales_wa'         => '256705993348',
        'contact_support_wa'       => '256705993348',
        'contact_technical_wa'     => '256705993348',
        'contact_pay_url'          => 'https://dishnetuganda.com/pay',
        'contact_app_url'          => 'https://dishnetuganda.com/app',
    ];
}
