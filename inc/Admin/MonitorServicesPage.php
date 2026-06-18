<?php

declare(strict_types=1);

namespace RhMonitor\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhMonitor\Providers;

/**
 * Rendert den oberen Teil des Monitor-Tabs als Dienst-Reihen im Look der
 * Tracking-Seite: je eine Reihe pro Error-Tracking-Anbieter (GlitchTip, Sentry,
 * Bugsink) plus Health-Endpoint, jede mit Status-Pill, An/Aus-Schalter und
 * Zahnrad-Modal.
 *
 * Anbieter laufen PARALLEL: jeder hat ein eigenes enabled-Flag und eigene DSNs
 * ({id}_enabled / {id}_dsn / {id}_browser_dsn). Fehler gehen an jeden aktiven
 * Anbieter, das Multi-DSN-Senden steckt in Monitor.php. Environment automatisch,
 * Release optional über RH_MONITOR_RELEASE.
 *
 * Kein GroupInterface, geschrieben/gelesen wird die Option 'monitor'.
 */
final class MonitorServicesPage
{
    public const TAB = 'monitor';
    public const CAPABILITY = 'manage_options';
    private const GROUP = MonitorGroup::GROUP_ID;
    private const NONCE_TOGGLE = 'rhbp_monitor_toggle';
    private const NONCE_SAVE = 'rhbp_monitor_save';

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render'], 5);
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('admin_post_rhbp_monitor_toggle', [$this, 'handleToggle']);
        add_action('admin_post_rhbp_monitor_save', [$this, 'handleSave']);
    }

    private function logoUrl(string $file): string
    {
        return RHMONITOR_PLUGIN_URL . 'assets/logos/' . $file;
    }

    public function renderInlineMessage(string $tab): void
    {
        if ($tab !== self::TAB) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige nach Redirect, keine zustandsändernde Aktion.
        $message = isset($_GET['rhbp_message']) ? sanitize_key(wp_unslash($_GET['rhbp_message'])) : '';
        $map = [
            'monitor_saved' => [__('Einstellungen gespeichert.', 'rh-monitor'), 'success'],
            'monitor_enabled' => [__('Dienst aktiviert.', 'rh-monitor'), 'success'],
            'monitor_disabled' => [__('Dienst deaktiviert.', 'rh-monitor'), 'success'],
        ];
        if (! isset($map[$message])) {
            return;
        }

        [$text, $variant] = $map[$message];
        printf('<div class="rhbp-callout rhbp-callout--%s">%s</div>', esc_attr($variant), esc_html($text));
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        echo '<div class="rhbp-monitor-services">';
        echo '<p class="rhbp-pane-intro">';
        echo esc_html__('Aktiviere die Dienste, die du nutzen willst. Beim Error-Tracking kannst du mehrere Anbieter gleichzeitig laufen lassen, Fehler gehen dann an alle aktiven. Konfiguration jeweils über das Zahnrad. Die letzten Fehler und der Live-Status stehen weiter unten.', 'rh-monitor');
        echo '</p>';

        echo '<div class="rhbp-monitor-services__list">';

        $meta = Providers::meta();
        foreach (Providers::IDS as $id) {
            $p = $meta[$id];
            $enabled = $this->boolSetting(Providers::enabledKey($id), false);
            echo '<div class="rhbp-card rhmon-service">';
            echo '<div class="rhmon-service__brand">';
            printf('<img class="rhmon-service__logo" src="%s" alt="%s">', esc_url($this->logoUrl($p['logo'])), esc_attr($p['name']));
            echo '<div class="rhmon-service__text"><strong>' . esc_html($p['name']) . '</strong><span>' . esc_html__('Server- und Browser-Fehler melden.', 'rh-monitor') . '</span></div>';
            echo '</div>';
            echo $this->providerPill($id, $enabled);
            $this->actions($id, $enabled, $enabled ? __('Anbieter deaktivieren', 'rh-monitor') : __('Anbieter aktivieren', 'rh-monitor'), 'rhbp-modal-' . $id);
            echo '</div>';
        }

        // Health-Reihe.
        $healthOn = $this->boolSetting(MonitorGroup::FIELD_HEALTH_ENABLED, true);
        echo '<div class="rhbp-card rhmon-service">';
        echo '<div class="rhmon-service__brand">';
        echo $this->icon('heart', 'rhmon-service__icon');
        echo '<div class="rhmon-service__text"><strong>' . esc_html__('Health-Endpoint', 'rh-monitor') . '</strong><span>' . esc_html__('JSON-Endpoint für Uptime-Checks (prüft u.a. die DB).', 'rh-monitor') . '</span></div>';
        echo '</div>';
        echo $healthOn
            ? '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('Aktiv', 'rh-monitor') . '</span>'
            : '<span class="rhbp-pill">' . esc_html__('Inaktiv', 'rh-monitor') . '</span>';
        $this->actions('health', $healthOn, $healthOn ? __('Health-Endpoint deaktivieren', 'rh-monitor') : __('Health-Endpoint aktivieren', 'rh-monitor'), 'rhbp-modal-health');
        echo '</div>';

        echo '</div>';
        echo '</div>';

        foreach (Providers::IDS as $id) {
            $this->renderProviderModal($id, $meta[$id]);
        }
        $this->renderHealthModal();
    }

    private function actions(string $service, bool $toggleOn, string $toggleLabel, string $modalId): void
    {
        echo '<div class="rhmon-service__actions">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-toggle-form">';
        wp_nonce_field(self::NONCE_TOGGLE);
        echo '<input type="hidden" name="action" value="rhbp_monitor_toggle">';
        echo '<input type="hidden" name="service" value="' . esc_attr($service) . '">';
        printf(
            '<label class="rhbp-switch" title="%s"><input type="checkbox" name="enabled" value="1" %s onchange="this.form.submit()"><span class="rhbp-switch__track" aria-hidden="true"></span></label>',
            esc_attr($toggleLabel),
            checked($toggleOn, true, false),
        );
        echo '</form>';

        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-open="' . esc_attr($modalId) . '" title="' . esc_attr__('Konfigurieren', 'rh-monitor') . '" aria-label="' . esc_attr__('Konfigurieren', 'rh-monitor') . '">' . $this->icon('gear') . '</button>';

        echo '</div>';
    }

    /**
     * @param array{name: string, logo: string, note: string, host: string} $p
     */
    private function renderProviderModal(string $id, array $p): void
    {
        $logo = sprintf('<img class="rhmon-modal-logo" src="%s" alt="%s">', esc_url($this->logoUrl($p['logo'])), esc_attr($p['name']));
        $this->modalOpen('rhbp-modal-' . $id, $logo, $p['name'], $p['note'], $id);

        echo '<div class="rhbp-modal__body">';
        $this->field(
            Providers::dsnKey($id),
            'url',
            __('DSN (Server)', 'rh-monitor'),
            sprintf(
                /* translators: 1: provider name, 2: example DSN host */
                __('Aus dem %1$s-Projekt für PHP/Server, Form https://<key>@%2$s/<id>. Leer = aus.', 'rh-monitor'),
                $p['name'],
                $p['host'],
            ),
        );
        $this->field(
            Providers::browserDsnKey($id),
            'url',
            __('DSN (Browser)', 'rh-monitor'),
            sprintf(
                /* translators: %s: provider name */
                __('Eigenes %s-Projekt für Browser-Fehler. SDK wird lokal ausgeliefert (kein CDN, DSGVO). Leer = aus.', 'rh-monitor'),
                $p['name'],
            ),
        );
        echo '<div class="rhbp-callout rhbp-callout--info">' . $this->icon('info', 'rhbp-ico--sm') . '<span>' . esc_html__('Environment wird automatisch erkannt (production/staging). Release optional über die Konstante RH_MONITOR_RELEASE in der wp-config.php.', 'rh-monitor') . '</span></div>';
        echo '</div>';

        $this->modalClose();
    }

    private function renderHealthModal(): void
    {
        $this->modalOpen('rhbp-modal-health', $this->icon('heart'), __('Health-Endpoint', 'rh-monitor'), __('JSON-Endpoint für externes Uptime-Monitoring.', 'rh-monitor'), 'health');

        echo '<div class="rhbp-modal__body">';
        $this->field(MonitorGroup::FIELD_HEALTH_PATH, 'text', __('Health-Pfad', 'rh-monitor'), __('URL-Pfad des Endpoints, Standard /health.', 'rh-monitor'), '/health');

        $tokenId = 'rhmon-' . MonitorGroup::FIELD_HEALTH_TOKEN;
        $tokenVal = (string) rhbp_setting(self::GROUP, MonitorGroup::FIELD_HEALTH_TOKEN, '');
        echo '<div class="rhbp-field">';
        echo '<label for="' . esc_attr($tokenId) . '">' . esc_html__('Health-Token (optional)', 'rh-monitor') . '</label>';
        echo '<div class="rhmon-token">';
        printf('<input type="text" id="%s" name="%s" value="%s" class="regular-text" data-rhmon-token>', esc_attr($tokenId), esc_attr(MonitorGroup::FIELD_HEALTH_TOKEN), esc_attr($tokenVal));
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhmon-token-generate>' . $this->icon('refresh', 'rhbp-ico--sm') . ' ' . esc_html__('Generieren', 'rh-monitor') . '</button>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-copy="#' . esc_attr($tokenId) . '" title="' . esc_attr__('Kopieren', 'rh-monitor') . '" aria-label="' . esc_attr__('Token kopieren', 'rh-monitor') . '">' . $this->icon('copy') . '</button>';
        echo '</div>';
        echo '<p class="rhbp-hint">' . esc_html__('Wenn gesetzt, muss der Endpoint mit ?token=... aufgerufen werden. Schützt vor öffentlichem Zugriff.', 'rh-monitor') . '</p>';
        echo '</div>';

        echo '</div>';
        $this->modalClose();
    }

    private function modalOpen(string $id, string $visual, string $title, string $sub, string $service): void
    {
        echo '<div class="rhbp-modal-backdrop" id="' . esc_attr($id) . '" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true" aria-label="' . esc_attr($title) . '">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l">' . $visual;
        echo '<div><h3 class="rhbp-modal__title">' . esc_html($title) . '</h3><p class="rhbp-modal__sub">' . esc_html($sub) . '</p></div>';
        echo '</div>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-monitor') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_SAVE);
        echo '<input type="hidden" name="action" value="rhbp_monitor_save">';
        echo '<input type="hidden" name="service" value="' . esc_attr($service) . '">';
    }

    private function modalClose(): void
    {
        echo '<div class="rhbp-modal__foot">';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhbp-modal-close>' . esc_html__('Abbrechen', 'rh-monitor') . '</button>';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Speichern', 'rh-monitor') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div></div>';
    }

    private function field(string $key, string $type, string $label, string $hint, string $default = ''): void
    {
        $id = 'rhmon-' . $key;
        $value = (string) rhbp_setting(self::GROUP, $key, $default);
        echo '<div class="rhbp-field">';
        echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" class="regular-text">',
            esc_attr($type === 'url' ? 'url' : 'text'),
            esc_attr($id),
            esc_attr($key),
            esc_attr($value),
        );
        if ($hint !== '') {
            echo '<p class="rhbp-hint">' . esc_html($hint) . '</p>';
        }
        echo '</div>';
    }

    private function providerPill(string $id, bool $enabled): string
    {
        if (! $enabled) {
            return '<span class="rhbp-pill">' . esc_html__('Inaktiv', 'rh-monitor') . '</span>';
        }

        $serverDsn = trim((string) rhbp_setting(self::GROUP, Providers::dsnKey($id), ''));
        $browserDsn = trim((string) rhbp_setting(self::GROUP, Providers::browserDsnKey($id), ''));
        if ($serverDsn === '' && $browserDsn === '') {
            return '<span class="rhbp-pill rhbp-pill--warn">' . esc_html__('Unvollständig', 'rh-monitor') . '</span>';
        }

        return '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('Aktiv', 'rh-monitor') . '</span>';
    }

    public function handleToggle(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-monitor'));
        }
        check_admin_referer(self::NONCE_TOGGLE);

        $service = isset($_POST['service']) ? sanitize_key(wp_unslash($_POST['service'])) : '';
        $on = isset($_POST['enabled']);

        if (Providers::exists($service)) {
            // Unabhängig: jeder Anbieter eigener Schalter, parallel möglich.
            rhbp_update_setting(self::GROUP, Providers::enabledKey($service), $on);
        } elseif ($service === 'health') {
            rhbp_update_setting(self::GROUP, MonitorGroup::FIELD_HEALTH_ENABLED, $on);
        }

        $this->redirect($on ? 'monitor_enabled' : 'monitor_disabled');
    }

    public function handleSave(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-monitor'));
        }
        check_admin_referer(self::NONCE_SAVE);

        $service = isset($_POST['service']) ? sanitize_key(wp_unslash($_POST['service'])) : '';

        if (Providers::exists($service)) {
            rhbp_update_settings(self::GROUP, [
                Providers::dsnKey($service) => $this->postUrl(Providers::dsnKey($service)),
                Providers::browserDsnKey($service) => $this->postUrl(Providers::browserDsnKey($service)),
            ]);
        } elseif ($service === 'health') {
            rhbp_update_settings(self::GROUP, [
                MonitorGroup::FIELD_HEALTH_PATH => $this->postText(MonitorGroup::FIELD_HEALTH_PATH),
                MonitorGroup::FIELD_HEALTH_TOKEN => $this->postText(MonitorGroup::FIELD_HEALTH_TOKEN),
            ]);
        }

        $this->redirect('monitor_saved');
    }

    private function postText(string $key): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    private function postUrl(string $key): string
    {
        return isset($_POST[$key]) ? esc_url_raw(trim((string) wp_unslash($_POST[$key]))) : '';
    }

    private function boolSetting(string $key, bool $default): bool
    {
        return (bool) rhbp_setting(self::GROUP, $key, $default);
    }

    private function redirect(string $message): never
    {
        wp_safe_redirect(add_query_arg(
            ['page' => SettingsPage::MENU_SLUG, 'tab' => self::TAB, 'rhbp_message' => $message],
            admin_url('admin.php'),
        ));
        exit;
    }

    private function icon(string $name, string $extraClass = ''): string
    {
        $paths = [
            'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
            'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'refresh' => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        ];
        $path = $paths[$name] ?? '';
        $class = 'rhbp-ico' . ($extraClass !== '' ? ' ' . $extraClass : '');

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
