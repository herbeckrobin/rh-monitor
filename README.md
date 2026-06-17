# RH Monitor

Server-seitiges PHP-Error-Tracking zu GlitchTip plus Health-Endpoint. Teil der rh-blueprint Kollektion.

Meldet unbehandelte PHP-Fehler und Exceptions über das offizielle Sentry-PHP-SDK an eine selbst gehostete GlitchTip-Instanz und stellt einen JSON-Health-Endpoint für Uptime-Checks bereit.

## Was es macht

- **Error-Tracking**: unbehandelte Fehler, Exceptions und Fatals gehen an GlitchTip. Inaktiv, solange keine DSN gesetzt ist (kein externer Verkehr ohne DSN). Init läuft früh auf `plugins_loaded`, damit möglichst viel erfasst wird.
- **Sinnvolle Defaults**: Environment fällt ohne Angabe auf den WordPress-Umgebungstyp zurück, Release auf die Domain.
- **Health-Endpoint**: ein JSON-Status (Standard `/health`), der die DB-Verbindung prüft (200 ok / 503 degraded), optional per Token geschützt. Funktioniert ohne Rewrite (roher Request-Pfad).

## Einstellungen

Im Backend unter **RH Blueprint → Monitoring**: Error-Tracking an/aus, GlitchTip-DSN, Environment, Release, Traces-Sample-Rate, Health-Endpoint an/aus, Health-Pfad und optionaler Health-Token.

## Für Entwickler

Filter `rh-blueprint/monitor/before_send` ($event): Events vor dem Senden anpassen oder verwerfen (z.B. PII scrubben, `null` zurückgeben unterdrückt).

## Installation

ZIP hochladen, aktivieren, unter Monitoring die GlitchTip-DSN eintragen. Der geteilte Core und das Sentry-SDK sind gebündelt.

## Voraussetzungen

WordPress 6.5+, PHP 8.1+.
