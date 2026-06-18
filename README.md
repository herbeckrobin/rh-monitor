# RH Monitor

Error-Tracking zu GlitchTip (PHP und Browser) plus Health-Endpoint. Teil der rh-blueprint Kollektion.

Meldet PHP-Fehler über das Sentry-PHP-SDK und JavaScript-Fehler der Besucher über das lokal ausgelieferte Sentry-Browser-SDK an eine selbst gehostete GlitchTip-Instanz und stellt einen JSON-Health-Endpoint für Uptime-Checks bereit.

## Was es macht

- **PHP-Error-Tracking**: unbehandelte Fehler, Exceptions und Fatals gehen an GlitchTip. Inaktiv, solange keine DSN gesetzt ist (kein externer Verkehr ohne DSN). Init läuft früh auf `plugins_loaded`, damit möglichst viel erfasst wird.
- **Browser-Error-Tracking**: JavaScript-Fehler der Besucher gehen an GlitchTip. Das Sentry-Browser-SDK wird **lokal** ausgeliefert (kein CDN), also kein Drittanbieter-Request und kein IP-Leak. Inaktiv ohne Browser-DSN. Empfehlung: getrennte GlitchTip-Projekte für Server und Browser, sonst mischen sich die Fehler.
- **Sinnvolle Defaults**: Environment fällt ohne Angabe auf den WordPress-Umgebungstyp zurück, Release auf die Domain. Browser und PHP teilen sich beide Werte.
- **Health-Endpoint**: ein JSON-Status (Standard `/health`), der die DB-Verbindung prüft (200 ok / 503 degraded), optional per Token geschützt. Funktioniert ohne Rewrite (roher Request-Pfad).

## Einstellungen

Im Backend unter **RH Blueprint → Monitoring**: PHP-Error-Tracking an/aus, GlitchTip-DSN (PHP), Environment, Release, Traces-Sample-Rate, Browser-Error-Tracking an/aus, GlitchTip-DSN (Browser), Health-Endpoint an/aus, Health-Pfad und optionaler Health-Token.

## Für Entwickler

Filter `rh-blueprint/monitor/before_send` ($event): Events vor dem Senden anpassen oder verwerfen (z.B. PII scrubben, `null` zurückgeben unterdrückt).

## Installation

ZIP hochladen, aktivieren, unter Monitoring die GlitchTip-DSN eintragen. Der geteilte Core und das Sentry-SDK sind gebündelt.

## Voraussetzungen

WordPress 6.5+, PHP 8.1+.
