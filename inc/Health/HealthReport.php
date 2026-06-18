<?php

declare(strict_types=1);

namespace RhMonitor\Health;

use RhMonitor\Admin\MonitorGroup;
use RhMonitor\Providers;

/**
 * Liefert einen Live-Schnappschuss des Systemzustands für die Anzeige im
 * Monitoring-Tab (DB, PHP/WP, Plattenplatz, Cron) sowie den Konfigurationsstand
 * von rh-monitor selbst (Error-Tracking, Health-Endpoint, Debug-Logging).
 *
 * Bewusst eigenständig und read-only, kein Eingriff in Monitor.php (das parallel
 * weiterentwickelt wird). Jeder Check ist [label, value, status], status steuert
 * nur die Pill-Farbe (ok|warn|err|neutral).
 */
final class HealthReport
{
    /** Unter diesem freien Plattenplatz wird gewarnt / als kritisch markiert. */
    private const DISK_WARN = 1073741824;   // 1 GB
    private const DISK_CRIT = 209715200;    // 200 MB

    /**
     * Systemzustand (live gemessen).
     *
     * @return list<array{label: string, value: string, status: string}>
     */
    public function system(): array
    {
        $checks = [];

        $dbOk = $this->dbOk();
        $checks[] = [
            'label' => __('Datenbank', 'rh-monitor'),
            'value' => $dbOk ? __('erreichbar', 'rh-monitor') : __('keine Verbindung', 'rh-monitor'),
            'status' => $dbOk ? 'ok' : 'err',
        ];

        $checks[] = [
            'label' => __('PHP-Version', 'rh-monitor'),
            'value' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.1', '<') ? 'warn' : 'neutral',
        ];

        $checks[] = [
            'label' => __('WordPress', 'rh-monitor'),
            'value' => get_bloginfo('version'),
            'status' => 'neutral',
        ];

        $checks[] = $this->diskCheck();

        $checks[] = [
            'label' => __('Speicherlimit', 'rh-monitor'),
            'value' => (string) (defined('WP_MEMORY_LIMIT') ? constant('WP_MEMORY_LIMIT') : ini_get('memory_limit')),
            'status' => 'neutral',
        ];

        $cronOff = defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON');
        $checks[] = [
            'label' => __('WP-Cron', 'rh-monitor'),
            'value' => $cronOff ? __('extern (deaktiviert)', 'rh-monitor') : __('aktiv', 'rh-monitor'),
            'status' => $cronOff ? 'warn' : 'ok',
        ];

        return $checks;
    }

    /**
     * Konfigurationsstand von rh-monitor (was ist scharf geschaltet).
     *
     * @return list<array{label: string, value: string, status: string}>
     */
    public function configuration(): array
    {
        $checks = [];

        $checks[] = $this->trackingCheck();

        $healthOn = $this->settingBool(MonitorGroup::FIELD_HEALTH_ENABLED, true);
        $healthPath = '/' . trim((string) $this->setting(MonitorGroup::FIELD_HEALTH_PATH, '/health'), '/');
        $checks[] = [
            'label' => __('Health-Endpoint', 'rh-monitor'),
            'value' => $healthOn ? $healthPath : __('aus', 'rh-monitor'),
            'status' => $healthOn ? 'ok' : 'neutral',
        ];

        $debugOn = defined('WP_DEBUG') && (bool) constant('WP_DEBUG');
        $logOn = defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG') !== false;
        $checks[] = [
            'label' => __('Debug-Logging', 'rh-monitor'),
            'value' => $logOn ? __('an', 'rh-monitor') : ($debugOn ? __('nur Anzeige', 'rh-monitor') : __('aus', 'rh-monitor')),
            'status' => $logOn ? 'ok' : 'warn',
        ];

        return $checks;
    }

    private function diskCheck(): array
    {
        $free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        if ($free === false) {
            return [
                'label' => __('Freier Plattenplatz', 'rh-monitor'),
                'value' => __('unbekannt', 'rh-monitor'),
                'status' => 'neutral',
            ];
        }

        $status = 'ok';
        if ($free < self::DISK_CRIT) {
            $status = 'err';
        } elseif ($free < self::DISK_WARN) {
            $status = 'warn';
        }

        return [
            'label' => __('Freier Plattenplatz', 'rh-monitor'),
            'value' => size_format((float) $free, 1),
            'status' => $status,
        ];
    }

    /**
     * Error-Tracking-Status über alle Anbieter (parallel), gleiche
     * Aktiv/Unvollständig/aus-Logik wie die Reihen oben. Zeigt die Namen der
     * aktiven, konfigurierten Anbieter.
     *
     * @return array{label: string, value: string, status: string}
     */
    private function trackingCheck(): array
    {
        $label = __('Error-Tracking', 'rh-monitor');
        $meta = Providers::meta();
        $anyEnabled = false;
        $configured = [];

        foreach (Providers::IDS as $id) {
            if (! $this->settingBool(Providers::enabledKey($id), false)) {
                continue;
            }
            $anyEnabled = true;
            $serverDsn = trim((string) $this->setting(Providers::dsnKey($id), ''));
            $browserDsn = trim((string) $this->setting(Providers::browserDsnKey($id), ''));
            if ($serverDsn !== '' || $browserDsn !== '') {
                $configured[] = $meta[$id]['name'];
            }
        }

        if (! $anyEnabled) {
            return ['label' => $label, 'value' => __('aus', 'rh-monitor'), 'status' => 'neutral'];
        }
        if ($configured === []) {
            return ['label' => $label, 'value' => __('Unvollständig', 'rh-monitor'), 'status' => 'warn'];
        }

        return ['label' => $label, 'value' => implode(', ', $configured), 'status' => 'ok'];
    }

    private function dbOk(): bool
    {
        global $wpdb;
        if (! ($wpdb instanceof \wpdb)) {
            return false;
        }

        return (string) $wpdb->get_var('SELECT 1') === '1';
    }

    private function setting(string $field, string $default): string
    {
        if (! function_exists('rhbp_setting')) {
            return $default;
        }

        return (string) rhbp_setting(MonitorGroup::GROUP_ID, $field, $default);
    }

    private function settingBool(string $field, bool $default): bool
    {
        if (! function_exists('rhbp_setting')) {
            return $default;
        }

        return (bool) rhbp_setting(MonitorGroup::GROUP_ID, $field, $default);
    }
}
