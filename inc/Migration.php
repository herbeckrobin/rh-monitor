<?php

declare(strict_types=1);

namespace RhMonitor;

use RhMonitor\Admin\MonitorGroup;

/**
 * Einmalige Migration vom alten Einzel-Anbieter-Modell (exklusiver 'provider' +
 * gespiegelte enabled/dsn-Keys) auf das parallele Modell ({id}_enabled je Anbieter).
 *
 * Läuft früh auf plugins_loaded (vor Monitor::initSentry), damit der bisher aktive
 * Anbieter ohne Unterbrechung weitertrackt. Idempotent über ein Flag in der Option.
 */
final class Migration
{
    public static function maybeRun(): void
    {
        if (! function_exists('rhbp_setting') || ! function_exists('rhbp_update_settings')) {
            return;
        }

        $group = MonitorGroup::GROUP_ID;
        if ((bool) rhbp_setting($group, 'parallel_migrated', false)) {
            return;
        }

        $updates = ['parallel_migrated' => true];

        // Der bisher exklusiv aktive Anbieter wird im neuen Modell eingeschaltet.
        $old = trim((string) rhbp_setting($group, 'provider', ''));
        if ($old !== '' && Providers::exists($old)) {
            $updates[Providers::enabledKey($old)] = true;
        }

        rhbp_update_settings($group, $updates);
    }
}
