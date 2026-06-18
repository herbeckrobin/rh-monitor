<?php

declare(strict_types=1);

namespace RhMonitor;

/**
 * Single-Source für die Error-Tracking-Anbieter (alle Sentry-Protokoll-kompatibel).
 *
 * Anbieter laufen parallel: jeder hat sein eigenes enabled-Flag und seine eigenen
 * DSNs ({id}_enabled / {id}_dsn / {id}_browser_dsn) in der Option 'monitor'. Genutzt
 * von der Admin-Page (Reihen), Monitor.php (Multi-DSN-Versand) und HealthReport.
 */
final class Providers
{
    /** @var list<string> Reihenfolge = Anzeige-Reihenfolge. */
    public const IDS = ['glitchtip', 'sentry', 'bugsink'];

    /**
     * @return array<string, array{name: string, logo: string, note: string, host: string}>
     */
    public static function meta(): array
    {
        return [
            'glitchtip' => [
                'name' => 'GlitchTip',
                'logo' => 'glitchtip.svg',
                'note' => __('Self-hosted, Sentry-kompatibel.', 'rh-monitor'),
                'host' => 'errors.deine-domain.de',
            ],
            'sentry' => [
                'name' => 'Sentry',
                'logo' => 'sentry.svg',
                'note' => __('Cloud oder self-hosted, das Original.', 'rh-monitor'),
                'host' => 'o0.ingest.sentry.io',
            ],
            'bugsink' => [
                'name' => 'Bugsink',
                'logo' => 'bugsink.webp',
                'note' => __('Self-hosted, schlank (ein Container).', 'rh-monitor'),
                'host' => 'bugsink.deine-domain.de',
            ],
        ];
    }

    public static function exists(string $id): bool
    {
        return in_array($id, self::IDS, true);
    }

    public static function enabledKey(string $id): string
    {
        return $id . '_enabled';
    }

    public static function dsnKey(string $id): string
    {
        return $id . '_dsn';
    }

    public static function browserDsnKey(string $id): string
    {
        return $id . '_browser_dsn';
    }
}
