<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

/**
 * Sources and channels, in plain words.
 *  - Source: where one enquiry came from (Meta ads, Google ads, website, referral …). A fixed list.
 *  - Channel: the KIND of marketing a source belongs to. A workspace may re-map a source to another channel; the default table is below.
 * "Not recorded" (`other`) means nobody recorded a source. It is reported as untracked, never as a marketing channel.
 */
final class Channels
{
    public const SOURCES = ['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other'];

    public const SOURCE_LABEL = [
        'website' => 'Website', 'referral' => 'Referral', 'portal' => 'Property portal', 'direct' => 'Direct',
        'meta_ads' => 'Meta ads', 'google_ads' => 'Google ads', 'walk_in' => 'Walk-in', 'other' => 'Not recorded',
    ];

    public const CHANNELS = [
        ['key' => 'paid_social', 'label' => 'Paid social'],
        ['key' => 'paid_search', 'label' => 'Paid search'],
        ['key' => 'website', 'label' => 'Website'],
        ['key' => 'referral', 'label' => 'Referral'],
        ['key' => 'portal', 'label' => 'Property portals'],
        ['key' => 'offline', 'label' => 'Offline'],
        ['key' => 'direct', 'label' => 'Direct'],
        ['key' => 'untracked', 'label' => 'Not recorded'],
    ];

    public const DEFAULT_CHANNEL = [
        'meta_ads' => 'paid_social', 'google_ads' => 'paid_search', 'website' => 'website', 'referral' => 'referral',
        'portal' => 'portal', 'walk_in' => 'offline', 'direct' => 'direct', 'other' => 'untracked',
    ];

    /** @return string[] */
    public static function channelKeys(): array
    {
        return array_column(self::CHANNELS, 'key');
    }

    public static function channelLabel(string $k): string
    {
        foreach (self::CHANNELS as $c) if ($c['key'] === $k) return $c['label'];
        return $k;
    }

    public static function sourceLabel(string $k): string
    {
        return self::SOURCE_LABEL[$k] ?? $k;
    }
}
