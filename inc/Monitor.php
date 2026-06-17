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
    public static function initSentry(): void
    {
        if (! function_exists('rhbp_setting') || ! function_exists('Sentry\init')) {
            return;
        }
        if (! (bool) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_ENABLED, true)) {
            return;
        }

        $dsn = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_DSN, ''));
        if ($dsn === '') {
            return; // Ohne DSN kein Tracking, kein externer Verkehr.
        }

        $environment = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_ENVIRONMENT, ''));
        if ($environment === '') {
            $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        }

        $release = trim((string) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_RELEASE, ''));
        if ($release === '') {
            $release = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        }

        $traces = (float) rhbp_setting(MonitorGroup::GROUP_ID, MonitorGroup::FIELD_TRACES, '0');

        \Sentry\init([
            'dsn' => $dsn,
            'environment' => $environment,
            'release' => $release,
            'traces_sample_rate' => max(0.0, min(1.0, $traces)),
            'send_default_pii' => false,
            'before_send' => static function ($event) {
                /** @var mixed $event */
                return apply_filters('rh-blueprint/monitor/before_send', $event);
            },
        ]);
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
