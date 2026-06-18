<?php

declare(strict_types=1);

namespace RhMonitor;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhMonitor\Admin\MonitorDashboard;
use RhMonitor\Admin\MonitorServicesPage;

/**
 * Bootstrap von rh-monitor.
 *
 * Sentry-Init (`plugins_loaded`, früh) und Health-Endpoint (`init`, früh) werden
 * direkt in boot() registriert, damit sie vor dem Core-`booted`-Hook (init) bzw.
 * möglichst früh greifen. Settings + Menü hängen am Core-Hook. Braucht den Core,
 * keine db-engine.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        // Früh, damit möglichst viele Fehler erfasst werden bzw. der Health-Check
        // vor dem Template antwortet. Migration läuft vor initSentry (prio 1 < 5).
        add_action('plugins_loaded', [Migration::class, 'maybeRun'], 1);
        add_action('plugins_loaded', [Monitor::class, 'initSentry'], 5);
        add_action('init', [Monitor::class, 'maybeHealth'], 0);
        add_action('wp_enqueue_scripts', [Monitor::class, 'enqueueBrowser'], 5);

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        $core->settings()->registerTab('monitor', __('Monitoring', 'rh-monitor'), 60);

        // Keine GroupInterface-Registrierung: der Monitor-Tab wird komplett bespoke
        // gerendert (Dienst-Reihen oben, Live-Status + Fehler-Log unten), im Look
        // der Tracking-Seite. Beide schreiben/lesen die Option 'monitor' (Monitor.php
        // unverändert). MonitorGroup bleibt als Quelle der Feld-Konstanten bestehen.
        (new MonitorServicesPage())->boot();
        (new MonitorDashboard())->boot();

        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Monitoring', 'rh-monitor'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=monitor'),
                'icon' => 'chart-area',
            ];
            return $links;
        });
    }
}
