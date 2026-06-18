<?php

declare(strict_types=1);

namespace RhMonitor\Log;

/**
 * Liest das WordPress-Debug-Log (wp-content/debug.log bzw. den per WP_DEBUG_LOG
 * gesetzten Pfad) für die Anzeige im Monitoring-Tab.
 *
 * Bewusst read-only und genügsam: bei großen Logs wird nur das Ende der Datei
 * gelesen (fseek vom Dateiende, kein Vollscan), die Zeilen werden zu Einträgen
 * gruppiert, damit mehrzeilige Stacktraces zusammenbleiben, und nach Aktualität
 * sortiert (neueste zuerst). Kein externer Verkehr, keine DB.
 */
final class DebugLogReader
{
    /** Wie viele Bytes vom Dateiende gelesen werden (eine Riesendatei wird nicht komplett geladen). */
    private const TAIL_BYTES = 65536;

    /** Obergrenze an Einträgen für die Anzeige (DOM-Schutz). */
    private const MAX_ENTRIES = 200;

    /**
     * Der konfigurierte Log-Pfad, auch wenn die Datei (noch) nicht existiert.
     * Reihenfolge: WP_DEBUG_LOG (Custom-Pfad) > Standard wp-content/debug.log >
     * php.ini error_log. Null wenn nichts auflösbar.
     */
    public function path(): ?string
    {
        if (defined('WP_DEBUG_LOG')) {
            $configured = constant('WP_DEBUG_LOG');
            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
            if ($configured === true && defined('WP_CONTENT_DIR')) {
                return rtrim((string) constant('WP_CONTENT_DIR'), '/\\') . '/debug.log';
            }
        }

        $iniLog = (string) ini_get('error_log');
        if ($iniLog !== '' && $iniLog !== 'syslog') {
            return $iniLog;
        }

        return null;
    }

    /**
     * Ist das Datei-Logging überhaupt eingeschaltet? (WP_DEBUG + WP_DEBUG_LOG)
     */
    public function isEnabled(): bool
    {
        $debug = defined('WP_DEBUG') && (bool) constant('WP_DEBUG');
        $log = defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG') !== false;

        // Auch ohne WP_DEBUG kann ein php.ini error_log existieren, das zeigen wir trotzdem.
        return ($debug && $log) || $this->path() !== null;
    }

    public function exists(): bool
    {
        $path = $this->path();
        if ($path === null) {
            return false;
        }
        clearstatcache(true, $path);

        return is_file($path) && is_readable($path);
    }

    public function size(): int
    {
        $path = $this->path();
        if ($path === null || ! is_file($path)) {
            return 0;
        }

        return (int) filesize($path);
    }

    /**
     * Liest das Dateiende und gruppiert es zu Einträgen, neueste zuerst.
     *
     * @return list<array{level: string, time: string, text: string}>
     */
    public function entries(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $path = (string) $this->path();
        $size = $this->size();
        if ($size === 0) {
            return [];
        }

        $read = min($size, self::TAIL_BYTES);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($read < $size) {
            fseek($handle, -$read, SEEK_END);
        }
        $data = (string) fread($handle, $read);
        fclose($handle);

        $truncated = $read < $size;
        $lines = explode("\n", $data);

        // Erste (angeschnittene) Zeile verwerfen, wenn wir mitten in einer Zeile begonnen haben.
        if ($truncated && $lines !== []) {
            array_shift($lines);
        }

        return $this->group($lines);
    }

    /**
     * @param list<string> $lines
     * @return list<array{level: string, time: string, text: string}>
     */
    private function group(array $lines): array
    {
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Ein neuer Eintrag beginnt mit dem WP-Log-Zeitstempel "[dd-Mon-yyyy HH:MM:SS UTC] ".
            if (preg_match('/^\[(?<time>[^\]]+)\]\s?(?<rest>.*)$/', $line, $m) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'level' => $this->levelFor($m['rest']),
                    'time' => trim($m['time']),
                    'text' => $line,
                ];
                continue;
            }

            // Fortsetzungszeile (Stacktrace, "thrown in ...") an den laufenden Eintrag hängen.
            if ($current !== null) {
                $current['text'] .= "\n" . $line;
                continue;
            }

            // Vorspann ohne Zeitstempel (z.B. angeschnitten): als eigener Eintrag ohne Zeit.
            $current = [
                'level' => $this->levelFor($line),
                'time' => '',
                'text' => $line,
            ];
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        $entries = array_reverse($entries);

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /**
     * Grobe Einstufung anhand des WP/PHP-Log-Präfixes.
     */
    private function levelFor(string $text): string
    {
        $t = strtolower($text);

        if (str_contains($t, 'fatal error') || str_contains($t, 'parse error')) {
            return 'fatal';
        }
        if (str_contains($t, 'database error') || str_contains($t, 'wordpress database')) {
            return 'db';
        }
        if (str_contains($t, 'warning') || str_contains($t, 'recoverable')) {
            return 'warning';
        }
        if (str_contains($t, 'deprecated')) {
            return 'deprecated';
        }
        if (str_contains($t, 'notice')) {
            return 'notice';
        }

        return 'other';
    }

    /**
     * Leert das Log (Truncate). Gibt false zurück, wenn das nicht möglich war.
     */
    public function clear(): bool
    {
        $path = $this->path();
        if ($path === null || ! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        clearstatcache(true, $path);

        return true;
    }
}
