=== RH Monitor ===
Contributors: robinherbeck
Tags: error tracking, sentry, glitchtip, monitoring, health check
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side PHP error tracking to GlitchTip (Sentry SDK) plus a JSON health endpoint for uptime monitoring.

== Description ==

RH Monitor reports unhandled PHP errors and exceptions to a self-hosted GlitchTip instance via the official Sentry PHP SDK, and exposes a small health endpoint for uptime checks.

= Features =

* Error tracking: unhandled errors, exceptions and fatals are sent to GlitchTip. Inactive until a DSN is set (no DSN, no traffic)
* Environment and release fall back to sensible values (WordPress environment type, site domain) when left empty
* Health endpoint: a JSON status endpoint (default /health) that checks the database connection, with an optional token to keep it private
* before_send filter (rh-blueprint/monitor/before_send) to scrub or drop events

Sentry initialises early on plugins_loaded so most errors are captured. The health endpoint answers before templates run.

Part of the rh-blueprint collection. Settings live under RH Blueprint > Monitoring.

== Changelog ==

= 0.1.0 =
* Initial release: Sentry-to-GlitchTip error tracking, configurable health endpoint with DB check and optional token.
