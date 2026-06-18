<?php

declare(strict_types=1);

namespace RhMonitor\Admin;

use RhBlueprint\Core\Settings\GroupInterface;
use RhBlueprint\Core\Settings\SettingField;

/**
 * Settings-Gruppe für das Monitoring (Error-Tracking + Health-Endpoint).
 *
 * Error-Tracking ist erst aktiv, wenn eine DSN gesetzt ist (ohne DSN passiert
 * nichts, kein externer Verkehr). Environment und Release fallen ohne Angabe auf
 * sinnvolle Werte zurück (wp_get_environment_type bzw. die Domain).
 *
 * Zwei Fehlerquellen, ein Modul: PHP-Fehler über das Server-SDK, JavaScript-Fehler
 * der Besucher über das lokal ausgelieferte Sentry-Browser-SDK. Beide melden an
 * GlitchTip, mit getrennten DSNs (idealerweise getrennte GlitchTip-Projekte).
 */
final class MonitorGroup implements GroupInterface
{
    public const GROUP_ID = 'monitor';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_DSN = 'dsn';
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_RELEASE = 'release';
    public const FIELD_TRACES = 'traces_sample_rate';
    public const FIELD_BROWSER_ENABLED = 'browser_enabled';
    public const FIELD_BROWSER_DSN = 'browser_dsn';
    public const FIELD_HEALTH_ENABLED = 'health_enabled';
    public const FIELD_HEALTH_PATH = 'health_path';
    public const FIELD_HEALTH_TOKEN = 'health_token';

    public function id(): string
    {
        return self::GROUP_ID;
    }

    public function tab(): string
    {
        return 'monitor';
    }

    public function title(): string
    {
        return __('Monitoring', 'rh-monitor');
    }

    public function description(): string
    {
        return __('PHP-Fehler an GlitchTip melden und einen Health-Endpoint für Uptime-Checks bereitstellen.', 'rh-monitor');
    }

    public function fields(): array
    {
        return [
            new SettingField(
                id: self::FIELD_ENABLED,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Error-Tracking aktivieren', 'rh-monitor'),
                description: __('Meldet unbehandelte PHP-Fehler und Exceptions an GlitchTip. Wirkt nur, wenn unten eine DSN gesetzt ist.', 'rh-monitor'),
                default: true,
                keywords: ['error', 'tracking', 'sentry', 'glitchtip', 'fehler'],
            ),
            new SettingField(
                id: self::FIELD_DSN,
                type: SettingField::TYPE_URL,
                label: __('GlitchTip DSN (PHP)', 'rh-monitor'),
                description: __('Die DSN deines GlitchTip-Projekts für Server-Fehler, z.B. https://<key>@errors.deine-domain.de/<id>. Leer = aus. Empfehlung: ein eigenes GlitchTip-Projekt für PHP/Server, getrennt vom Browser-Tracking unten, sonst mischen sich Server- und Client-Fehler im selben Projekt.', 'rh-monitor'),
                default: '',
                keywords: ['dsn', 'glitchtip', 'sentry', 'endpoint'],
            ),
            new SettingField(
                id: self::FIELD_ENVIRONMENT,
                type: SettingField::TYPE_TEXT,
                label: __('Environment', 'rh-monitor'),
                description: __('z.B. production, staging. Leer = automatisch (WordPress-Umgebungstyp).', 'rh-monitor'),
                default: '',
                keywords: ['environment', 'umgebung', 'staging', 'production'],
            ),
            new SettingField(
                id: self::FIELD_RELEASE,
                type: SettingField::TYPE_TEXT,
                label: __('Release', 'rh-monitor'),
                description: __('Versions-/Release-Kennung für die Zuordnung der Fehler. Leer = automatisch (Domain).', 'rh-monitor'),
                default: '',
                keywords: ['release', 'version'],
            ),
            new SettingField(
                id: self::FIELD_TRACES,
                type: SettingField::TYPE_TEXT,
                label: __('Traces Sample Rate', 'rh-monitor'),
                description: __('Performance-Tracing-Anteil von 0 bis 1. Standard 0 (nur Fehler, kein Tracing).', 'rh-monitor'),
                default: '0',
                keywords: ['traces', 'performance', 'sample'],
            ),
            new SettingField(
                id: self::FIELD_BROWSER_ENABLED,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Browser-Error-Tracking aktivieren', 'rh-monitor'),
                description: __('Meldet JavaScript-Fehler der Besucher an GlitchTip. Das Sentry-Browser-SDK wird lokal ausgeliefert (kein CDN, DSGVO). Wirkt nur, wenn unten eine Browser-DSN gesetzt ist.', 'rh-monitor'),
                default: false,
                keywords: ['browser', 'javascript', 'client', 'error', 'glitchtip', 'sentry'],
            ),
            new SettingField(
                id: self::FIELD_BROWSER_DSN,
                type: SettingField::TYPE_URL,
                label: __('GlitchTip DSN (Browser)', 'rh-monitor'),
                description: __('Die DSN deines GlitchTip-Projekts für Browser-Fehler. Leer = aus. Environment und Release teilt sich das Browser-Tracking mit dem PHP-Tracking oben.', 'rh-monitor'),
                default: '',
                keywords: ['dsn', 'browser', 'glitchtip', 'sentry'],
            ),
            new SettingField(
                id: self::FIELD_HEALTH_ENABLED,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Health-Endpoint aktivieren', 'rh-monitor'),
                description: __('Stellt einen JSON-Endpoint für Uptime-Monitoring bereit (prüft u.a. die DB-Verbindung).', 'rh-monitor'),
                default: true,
                keywords: ['health', 'uptime', 'monitoring', 'status'],
            ),
            new SettingField(
                id: self::FIELD_HEALTH_PATH,
                type: SettingField::TYPE_TEXT,
                label: __('Health-Pfad', 'rh-monitor'),
                description: __('URL-Pfad des Endpoints, Standard /health.', 'rh-monitor'),
                default: '/health',
                keywords: ['health', 'pfad', 'path', 'url'],
            ),
            new SettingField(
                id: self::FIELD_HEALTH_TOKEN,
                type: SettingField::TYPE_TEXT,
                label: __('Health-Token (optional)', 'rh-monitor'),
                description: __('Wenn gesetzt, muss der Endpoint mit ?token=... aufgerufen werden. Schützt vor öffentlichem Zugriff.', 'rh-monitor'),
                default: '',
                keywords: ['token', 'secret', 'health'],
            ),
        ];
    }
}
