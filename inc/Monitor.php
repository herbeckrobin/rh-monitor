<?php

declare(strict_types=1);

namespace RhMonitor;

use RhMonitor\Admin\MonitorGroup;

/**
 * Error-Tracking (Sentry-SDK zu GlitchTip) und Health-Endpoint.
 *
 * Sentry wird früh auf `plugins_loaded` initialisiert (nach dem Core-Loader),
 * damit möglichst viele Fehler erfasst werden, das SDK registriert dabei selbst
 * die Error-/Exception-/Fatal-Handler. Der Health-Endpoint hängt früh an `init`
 * und antwortet mit JSON, bevor Templates laufen.
 *
 * Statische Methoden, weil die Hooks bereits in Plugin::boot() (vor plugins_loaded)
 * registriert werden müssen. Settings werden im Callback gelesen (function_exists-Guard).
 */
final class Monitor
{
    /**
     * Server-Error-Tracking. Anbieter laufen parallel: an JEDEN aktiven Anbieter
     * mit gesetzter Server-DSN wird gemeldet. Das SDK kennt nur einen globalen
     * Client (registriert die Fehler-Handler), darum geht der erste Anbieter über
     * \Sentry\init() und die weiteren bekommen das Event im before_send weitergereicht
     * (eigene Clients via ClientBuilder, flush beim Shutdown).
     */
    public static function initSentry(): void
    {
        if (! function_exists('rhbp_setting') || ! function_exists('Sentry\init')) {
            return;
        }

        $dsns = self::activeDsns(false);
        if ($dsns === []) {
            return; // Kein aktiver Anbieter mit DSN, kein externer Verkehr.
        }

        $base = [
            'environment' => self::environment(),
            'traces_sample_rate' => 0.0,
            'send_default_pii' => false,
        ];
        $release = self::release();
        if ($release !== '') {
            $base['release'] = $release;
        }

        // Weitere Anbieter (ab dem zweiten) als eigene Clients.
        $extra = [];
        foreach (array_slice($dsns, 1) as $dsn) {
            $extra[] = \Sentry\ClientBuilder::create(array_merge($base, ['dsn' => $dsn]))->getClient();
        }

        \Sentry\init(array_merge($base, [
            'dsn' => $dsns[0],
            'before_send' => static function ($event) use ($extra) {
                foreach ($extra as $client) {
                    $client->captureEvent($event);
                }

                /** @var mixed $event */
                return apply_filters('rh-blueprint/monitor/before_send', $event);
            },
        ]));

        if ($extra !== []) {
            register_shutdown_function(static function () use ($extra): void {
                foreach ($extra as $client) {
                    $client->flush();
                }
            });
        }
    }

    /**
     * Browser-Error-Tracking: lädt das lokal gehostete Sentry-Browser-SDK (kein CDN,
     * DSGVO) und meldet parallel an jeden aktiven Anbieter mit Browser-DSN. Erster
     * Anbieter über Sentry.init (fängt window.onerror), weitere als eigene
     * BrowserClient-Instanzen, denen das Event im beforeSend weitergereicht wird.
     */
    public static function enqueueBrowser(): void
    {
        if (is_admin() || ! function_exists('rhbp_setting')) {
            return;
        }

        $dsns = self::activeDsns(true);
        if ($dsns === []) {
            return;
        }

        $abs = RHMONITOR_PLUGIN_DIR . 'assets/vendor/sentry.min.js';
        if (! file_exists($abs)) {
            return;
        }

        wp_enqueue_script(
            'rh-monitor-glitchtip-browser',
            RHMONITOR_PLUGIN_URL . 'assets/vendor/sentry.min.js',
            [],
            (string) filemtime($abs),
            ['strategy' => 'defer', 'in_footer' => false]
        );

        $release = self::release();
        $releasePart = $release !== '' ? ',release:' . wp_json_encode($release) : '';

        // d[0] über Sentry.init (fängt window.onerror), d[1..] als eigene Clients je
        // mit eigenem Scope (offizielles Multi-Instance-Pattern: scope.setClient +
        // scope.captureEvent), beforeSend reicht das Event an diese Scopes weiter.
        $init = '(function(){var S=window.Sentry;if(!S||!S.init){return;}'
            . 'var d=' . wp_json_encode($dsns) . ';if(!d.length){return;}'
            . 'var extra=d.slice(1).map(function(dsn){try{'
            . 'var c=new S.BrowserClient({dsn:dsn,transport:S.makeFetchTransport,stackParser:S.defaultStackParser,'
            . 'integrations:S.getDefaultIntegrations({}).filter(function(i){return i.name==="InboundFilters"||i.name==="FunctionToString";})});'
            . 'var sc=new S.Scope();sc.setClient(c);c.init();return sc;'
            . '}catch(e){return null;}}).filter(Boolean);'
            . 'S.init({dsn:d[0],environment:' . wp_json_encode(self::environment()) . $releasePart . ',tracesSampleRate:0,replaysSessionSampleRate:0,beforeSend:function(event){extra.forEach(function(sc){try{sc.captureEvent(event);}catch(e){}});return event;}});'
            . '})();';

        wp_add_inline_script('rh-monitor-glitchtip-browser', $init);
    }

    /**
     * Aktive DSNs aller eingeschalteten Anbieter, in Anbieter-Reihenfolge.
     *
     * @return list<string>
     */
    private static function activeDsns(bool $browser): array
    {
        $dsns = [];
        foreach (Providers::IDS as $id) {
            if (! (bool) rhbp_setting(MonitorGroup::GROUP_ID, Providers::enabledKey($id), false)) {
                continue;
            }
            $key = $browser ? Providers::browserDsnKey($id) : Providers::dsnKey($id);
            $dsn = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, $key, ''));
            if ($dsn !== '') {
                $dsns[] = $dsn;
            }
        }

        return $dsns;
    }

    private static function environment(): string
    {
        $environment = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_ENVIRONMENT, ''));
        if ($environment === '') {
            $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        }

        return $environment;
    }

    private static function release(): string
    {
        $release = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_RELEASE, ''));
        if ($release === '' && defined('RH_MONITOR_RELEASE')) {
            $release = (string) constant('RH_MONITOR_RELEASE');
        }

        return $release;
    }

    public static function maybeHealth(): void
    {
        if (! function_exists('rhbp_setting')) {
            return;
        }
        if (! (bool) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_HEALTH_ENABLED, true)) {
            return;
        }

        $path = (string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_HEALTH_PATH, '/health');
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return;
        }

        $requestPath = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($requestPath !== $path) {
            return;
        }

        $token = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_HEALTH_TOKEN, ''));
        if ($token !== '') {
            $given = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
            if (! hash_equals($token, $given)) {
                self::respond(['status' => 'forbidden'], 403);
            }
        }

        self::respond(self::healthPayload(), self::isHealthy() ? 200 : 503);
    }

    /**
     * @return array<string, mixed>
     */
    private static function healthPayload(): array
    {
        $dbOk = self::dbOk();

        return [
            'status' => $dbOk ? 'ok' : 'degraded',
            'timestamp' => gmdate('c'),
            'checks' => [
                'db' => $dbOk ? 'ok' : 'fail',
            ],
        ];
    }

    private static function isHealthy(): bool
    {
        return self::dbOk();
    }

    private static function dbOk(): bool
    {
        global $wpdb;
        if (! ($wpdb instanceof \wpdb)) {
            return false;
        }

        return (string) $wpdb->get_var('SELECT 1') === '1';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function respond(array $payload, int $status): void
    {
        if (! headers_sent()) {
            status_header($status);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($payload);
        exit;
    }
}
