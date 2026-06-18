<?php

declare(strict_types=1);

namespace RhMonitor\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhMonitor\Health\HealthReport;
use RhMonitor\Log\DebugLogReader;

/**
 * Live-Monitoring im Monitor-Tab: Status-Karten (System + Konfiguration) und ein
 * Fehler-Log-Viewer auf Basis des WordPress-Debug-Logs.
 *
 * Im Look der Sync- und Tracking-Seiten: Intro-Absatz (rhbp-pane-intro), Sektionen
 * als rhbp-card mit icon-Titeln, internes SVG-Icon-Helper und Status-Meldung über
 * ?rhbp_message= (Inline-Callout auf tab_content_before) statt Transient. Bespoke
 * Sektion unter dem Settings-Formular, additiv, ohne Monitor.php/MonitorGroup.php
 * umzubauen.
 */
final class MonitorDashboard
{
    public const TAB = 'monitor';
    public const CAPABILITY = 'manage_options';
    private const ACTION_CLEAR = 'rhmonitor_clear_log';
    private const NONCE_CLEAR = 'rhmonitor_clear';
    private const STYLE_HANDLE = 'rh-monitor-admin';

    public function __construct(
        private readonly HealthReport $health = new HealthReport(),
        private readonly DebugLogReader $log = new DebugLogReader(),
    ) {
    }

    public function boot(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_CLEAR, [$this, 'handleClear']);
    }

    public function enqueue(string $hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== SettingsPage::MENU_SLUG) {
            return;
        }

        $abs = RHMONITOR_PLUGIN_DIR . 'assets/monitor-admin.css';
        if (! file_exists($abs)) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            RHMONITOR_PLUGIN_URL . 'assets/monitor-admin.css',
            ['rh-blueprint-settings'],
            (string) filemtime($abs),
        );

        $js = RHMONITOR_PLUGIN_DIR . 'assets/monitor-admin.js';
        if (file_exists($js)) {
            wp_enqueue_script(
                self::STYLE_HANDLE,
                RHMONITOR_PLUGIN_URL . 'assets/monitor-admin.js',
                [],
                (string) filemtime($js),
                true,
            );
        }
    }

    public function renderInlineMessage(string $tab): void
    {
        if ($tab !== self::TAB) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige einer Status-Meldung nach Redirect, keine zustandsändernde Aktion.
        $message = isset($_GET['rhbp_message']) ? sanitize_key(wp_unslash($_GET['rhbp_message'])) : '';
        if ($message === '') {
            return;
        }

        $map = [
            'monitor_log_cleared' => ['success', __('Log geleert.', 'rh-monitor')],
            'monitor_log_failed' => ['warn', __('Log konnte nicht geleert werden (Schreibrechte prüfen).', 'rh-monitor')],
        ];
        if (! isset($map[$message])) {
            return;
        }

        [$variant, $text] = $map[$message];
        printf('<div class="rhbp-callout rhbp-callout--%s">%s</div>', esc_attr($variant), esc_html($text));
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        echo '<div class="rhbp-monitor rhmon-dash">';
        echo '<p class="rhbp-pane-intro">';
        echo esc_html__('Live-Status dieser Site und die letzten PHP-Fehler aus dem Debug-Log. Das Error-Tracking an GlitchTip und der Health-Endpoint werden oben konfiguriert.', 'rh-monitor');
        echo '</p>';

        $this->renderStatus();
        $this->renderLog();

        echo '</div>';
    }

    private function renderStatus(): void
    {
        echo '<div class="rhbp-card-grid rhmon-status">';
        $this->renderStatusCard('activity', __('System', 'rh-monitor'), $this->health->system());
        $this->renderStatusCard('sliders', __('rh-monitor', 'rh-monitor'), $this->health->configuration());
        echo '</div>';
    }

    /**
     * @param list<array{label: string, value: string, status: string}> $checks
     */
    private function renderStatusCard(string $icon, string $title, array $checks): void
    {
        echo '<div class="rhbp-card">';
        echo '<div class="rhbp-card__head"><div class="rhbp-card__title">';
        echo $this->icon($icon) . '<strong>' . esc_html($title) . '</strong>';
        echo '</div></div>';
        echo '<dl class="rhbp-meta rhmon-meta">';
        foreach ($checks as $check) {
            echo '<dt>' . esc_html($check['label']) . '</dt>';
            echo '<dd>' . $this->pill($check['value'], $check['status']) . '</dd>';
        }
        echo '</dl>';
        echo '</div>';
    }

    private function renderLog(): void
    {
        $path = $this->log->path();
        $loggingOn = $path !== null && $this->log->isEnabled();
        $entries = $loggingOn ? $this->log->entries() : [];
        $canClear = $loggingOn && $this->log->exists() && $entries !== [];

        echo '<div class="rhbp-card rhmon-logcard">';

        // Kopf: Titel links, "Log leeren" rechts (wie sync/tracking).
        echo '<div class="rhbp-card__head">';
        echo '<div class="rhbp-card__title">' . $this->icon('alert') . '<strong>' . esc_html__('Fehler-Log', 'rh-monitor') . '</strong></div>';
        if ($canClear) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhmon-clear">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CLEAR) . '">';
            wp_nonce_field(self::NONCE_CLEAR);
            echo '<button type="submit" class="rhbp-btn rhbp-btn--ghost">' . $this->icon('trash', true) . ' ' . esc_html__('Log leeren', 'rh-monitor') . '</button>';
            echo '</form>';
        }
        echo '</div>';

        if (! $loggingOn) {
            echo '<div class="rhbp-callout rhbp-callout--warn">' . $this->icon('alert', true) . '<span>';
            echo esc_html__('Datei-Logging ist nicht aktiviert. Setze in der wp-config.php WP_DEBUG und WP_DEBUG_LOG auf true, damit hier Fehler erscheinen.', 'rh-monitor');
            echo '</span></div>';
            echo '</div>';
            return;
        }

        echo '<p class="rhmon-logmeta">';
        echo esc_html__('Quelle:', 'rh-monitor') . ' <code>' . esc_html((string) $path) . '</code>';
        if ($this->log->exists()) {
            echo ' &middot; ' . esc_html(size_format((float) $this->log->size(), 1));
        }
        echo '</p>';

        if ($entries === []) {
            echo '<div class="rhbp-empty">' . esc_html__('Keine Eintraege im Log. Sieht gut aus.', 'rh-monitor') . '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="rhmon-log" tabindex="0">';
        foreach ($entries as $entry) {
            echo '<div class="rhmon-entry rhmon-entry--' . esc_attr($entry['level']) . '">';
            echo '<div class="rhmon-entry__head">';
            echo '<span class="rhmon-badge">' . esc_html($this->levelLabel($entry['level'])) . '</span>';
            if ($entry['time'] !== '') {
                echo '<span class="rhmon-time">' . esc_html($entry['time']) . '</span>';
            }
            echo '</div>';
            echo '<pre class="rhmon-body">' . esc_html($entry['text']) . '</pre>';
            echo '</div>';
        }
        echo '</div>';

        echo '</div>'; // .rhmon-logcard
    }

    public function handleClear(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-monitor'));
        }
        check_admin_referer(self::NONCE_CLEAR);

        $ok = $this->log->clear();
        $this->redirect($ok ? 'monitor_log_cleared' : 'monitor_log_failed');
    }

    private function redirect(string $message): never
    {
        $url = add_query_arg(
            [
                'page' => SettingsPage::MENU_SLUG,
                'tab' => self::TAB,
                'rhbp_message' => $message,
            ],
            admin_url('admin.php'),
        );
        wp_safe_redirect($url);
        exit;
    }

    private function pill(string $value, string $status): string
    {
        $map = ['ok' => 'rhbp-pill--ok', 'warn' => 'rhbp-pill--warn', 'err' => 'rhbp-pill--err'];
        $class = 'rhbp-pill';
        if (isset($map[$status])) {
            $class .= ' ' . $map[$status];
        }

        return '<span class="' . esc_attr($class) . '">' . esc_html($value) . '</span>';
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'fatal' => __('Fatal', 'rh-monitor'),
            'db' => __('DB', 'rh-monitor'),
            'warning' => __('Warning', 'rh-monitor'),
            'deprecated' => __('Deprecated', 'rh-monitor'),
            'notice' => __('Notice', 'rh-monitor'),
            default => __('Log', 'rh-monitor'),
        };
    }

    /**
     * Internes SVG-Icon (feather-Stil, stroke = currentColor), wie in rh-tracking.
     */
    private function icon(string $name, bool $small = false): string
    {
        $paths = [
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        ];
        $path = $paths[$name] ?? '';
        $class = $small ? 'rhbp-ico rhbp-ico--sm' : 'rhbp-ico';

        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
