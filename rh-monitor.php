<?php

/**
 * Plugin Name:       RH Monitor
 * Plugin URI:        https://github.com/herbeckrobin/rh-monitor
 * Update URI:        https://github.com/herbeckrobin/rh-monitor
 * Description:       Error-Tracking zu GlitchTip für PHP (Server-SDK) und Browser (lokales Sentry-Browser-SDK) plus Health-Endpoint. Teil der rh-blueprint Kollektion.
 * Version:           0.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-monitor
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHMONITOR_VERSION', '0.1.4');
define('RHMONITOR_PLUGIN_FILE', __FILE__);
define('RHMONITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RHMONITOR_PLUGIN_URL', plugin_dir_url(__FILE__));

$rhmonitor_autoload = RHMONITOR_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhmonitor_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Monitor:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhmonitor_autoload;

RhMonitor\Plugin::boot();
