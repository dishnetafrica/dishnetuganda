<?php
declare(strict_types=1);

/**
 * WaLocation — what a WhatsApp location pin means to this system.
 *
 * A customer asked where to install and answered with a pin. Nothing happened.
 * The webhook's text extractor knew eight message shapes and `locationMessage`
 * was not one of them, so the text came out empty and the queue step dropped
 * the message:
 *
 *     if ($text === '') { $skipped++; continue; }
 *
 * The AI was never told a customer had spoken. They got silence, and the
 * coordinates — the one piece of information the whole installation depends on
 * — were thrown away before anything could read them. The conversation looked,
 * to us, like a customer who simply stopped replying.
 *
 * This is the single place that understands a pin, so both inbound paths
 * (Evolution and the legacy WASender webhook) agree about what one is.
 *
 * ── Three outcomes, never two ───────────────────────────────────────────
 *
 * A pin is not simply valid or invalid:
 *
 *   NOT A LOCATION   the node is absent, the numbers are missing or not
 *                    numbers, they fall outside the range the planet has, or
 *                    they are exactly 0,0 — Null Island, which is what a
 *                    missing coordinate looks like once it has been cast to a
 *                    float. Treated as though no pin was sent.
 *
 *   OUT OF AREA      real coordinates, outside the deployment country's box.
 *                    Kept, stored, flagged. NOT discarded: the customer may be
 *                    quoting a site across a border, or our box may be too
 *                    tight. The assistant asks them to confirm rather than
 *                    silently accepting it as the installation address, and a
 *                    person can see both the pin and the doubt.
 *
 *   IN AREA          used.
 *
 * The middle case is the one worth having. Rejecting an out-of-area pin would
 * reproduce the original failure with a better excuse.
 */
final class WaLocation
{
    /**
     * Bounding boxes, deliberately generous — a box that clips a real customer
     * is worse than one that admits a neighbour's border town, because the
     * first loses a sale and the second only asks a question.
     */
    const BOUNDS = [
        'UG' => ['lat' => [-1.6, 4.4],  'lng' => [29.4, 35.2],  'name' => 'Uganda'],
        'SS' => ['lat' => [3.3, 12.4],  'lng' => [24.0, 36.0],  'name' => 'South Sudan'],
    ];

    /** Which country's box applies on this box. */
    public static function country(array $config): string
    {
        $set = strtoupper(trim((string)($config['geo_country'] ?? '')));
        if (isset(self::BOUNDS[$set])) return $set;

        // Not a new setting to forget: the timezone is already set per
        // deployment and already means "which country is this".
        $tz = trim((string)($config['timezone'] ?? ''));
        if (stripos($tz, 'Kampala') !== false) return 'UG';
        if (stripos($tz, 'Juba')    !== false) return 'SS';

        return 'SS';   // the plugin's own default zone, so the default agrees with it
    }

    /**
     * Read a pin out of an Evolution/Baileys message node.
     *
     * @param  array $message the `message` object of one webhook message
     * @return array|null     ['lat','lng','name','address','live'] or null when
     *                        there is no usable location in it at all
     */
    public static function fromMessage(array $message): ?array
    {
        $node = null;
        foreach (['locationMessage', 'liveLocationMessage'] as $k) {
            if (isset($message[$k]) && is_array($message[$k])) { $node = $message[$k]; break; }
        }
        if ($node === null) return null;

        $lat = $node['degreesLatitude']  ?? null;
        $lng = $node['degreesLongitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) return null;

        $lat = (float)$lat;
        $lng = (float)$lng;
        if (!self::isSane($lat, $lng)) return null;

        return [
            'lat'     => $lat,
            'lng'     => $lng,
            'name'    => trim((string)($node['name'] ?? '')),
            'address' => trim((string)($node['address'] ?? '')),
            'live'    => isset($message['liveLocationMessage']),
        ];
    }

    /**
     * Is this a point on Earth that somebody chose?
     *
     * 0,0 is in the Gulf of Guinea and nobody in either market has ever been
     * there. It is what an absent coordinate becomes after (float)null, so it
     * is refused as a missing value rather than trusted as a place.
     */
    public static function isSane(float $lat, float $lng): bool
    {
        if ($lat < -90.0 || $lat > 90.0)   return false;
        if ($lng < -180.0 || $lng > 180.0) return false;
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) return false;
        return true;
    }

    /** Inside the deployment country's box? */
    public static function inBounds(float $lat, float $lng, string $country): bool
    {
        $b = self::BOUNDS[$country] ?? null;
        if ($b === null) return true;          // unknown country: do not invent a doubt
        return $lat >= $b['lat'][0] && $lat <= $b['lat'][1]
            && $lng >= $b['lng'][0] && $lng <= $b['lng'][1];
    }

    /** Six decimals is about 10 cm. More is noise and makes the text unreadable. */
    public static function format(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    /**
     * What the AI is given to read, once a pin has been taken into account.
     *
     * This is the whole fix in one function. The webhook used to compute a
     * text, find it empty for a pin, and drop the message — so the decision
     * that lost the customer lived inline in a script and could not be tested.
     * It lives here now, and the test drives it directly.
     *
     * A caption plus a pin keeps both: the customer said something AND showed
     * where, and dropping either half is how this went wrong the first time.
     */
    public static function mergeText(string $text, ?array $loc, bool $inBounds, string $country): string
    {
        if ($loc === null) return $text;
        $described = self::describe($loc, $inBounds, $country);
        return $text === '' ? $described : ($text . "\n" . $described);
    }

    /**
     * The line the assistant reads in place of the customer's empty message.
     *
     * Written as a description of an event, not as words the customer typed,
     * so the model cannot mistake it for something to answer literally.
     */
    public static function describe(array $loc, bool $inBounds, string $country): string
    {
        $out = '[The customer sent a WhatsApp location pin: '
             . self::format((float)$loc['lat']) . ', ' . self::format((float)$loc['lng']);
        if (($loc['name'] ?? '') !== '')    $out .= ' — "' . $loc['name'] . '"';
        if (($loc['address'] ?? '') !== '') $out .= ', ' . $loc['address'];
        if (!$inBounds) {
            $out .= '. NOTE: that point is outside '
                  . (self::BOUNDS[$country]['name'] ?? 'the service area')
                  . ', so confirm the site with them before treating it as the'
                  . ' installation address';
        }
        return $out . ']';
    }
}
