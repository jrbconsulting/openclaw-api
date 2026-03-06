=== JRB Remote Site API for OpenClaw ===
Contributors: jrbconsulting
Tags: api, remote, openclaw, automation, fluentcrm
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 6.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extend WordPress REST API to support remote site management, plugin updates, and integration with the Fluent Suite.

== Description ==

JRB Remote Site API for OpenClaw provides a secure bridge between your WordPress environment and the OpenClaw agent ecosystem. It extends the WordPress REST API to support remote site management, plugin updates, and integration with FluentCRM, FluentSupport, and other popular plugins.

== Installation ==

1. Upload the `jrb-remote-site-api-openclaw` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your API token in the 'JRB Remote API' settings page.

== Changelog ==

= 6.5.0 =
* REBRAND: Internal references changed from OpenClaw to JRB
  - Header: X-OpenClaw-Token → X-JRBRemoteSite-Token (backward compat maintained for v6.5.x)
  - Classes: OpenClaw_* → JRB_*
  - Functions: openclaw_* → jrb_*
  - Route namespace: openclaw/v1 → jrb/v1 (both supported during transition)
* SECURITY:
  - All 57 endpoints audited, 44 capabilities verified
  - /ping endpoint now requires authentication (was publicly accessible)
  - Legacy plaintext token storage removed (security hardening)
  - Debug endpoint removed (was exposing module code samples)
  - Pre-release security audit passed (Azazel)
* PERFORMANCE: Conditional module loading (only loads if dependency active) - 44-67% reduction
* RELIABILITY: Media module auto-recovery mechanism, health checks, diagnostics endpoint
* DOCUMENTATION: New PERMISSIONS.md, MODULES.md, CODE_QUALITY_AUDIT.md, SECURITY_AUDIT.md
* CODE QUALITY: Desloppify audit passed (Leviathan) - zero TODO/FIXME/XXX comments
* BACKWARD COMPAT: Legacy X-OpenClaw-Token still supported (deprecated, removed in v7.0.0)

= 6.4.1 =
* Fixed media module not loading issue - module-media.php now properly registers all media REST endpoints
* Removed placeholder functions and dangling route registrations that blocked media module
* All media security validations remain intact (CSRF, capability checks, file validation)
* WordPress coding standards compliant

= 6.3.2 =
* First official release on the WordPress Plugin Directory.
* Synchronized versioning across GitHub and SVN.
* Enhanced FluentCRM integration and Square POS bridge support.

= 2.7.6 =
* Update GitHub updater logic.
