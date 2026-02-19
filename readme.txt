=== OpenClaw API ===
Contributors: openclaw
Tags: api, rest, remote, management, openclaw
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 2.6.43
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API for OpenClaw remote site management with fine-grained capability controls and plugin integrations.

== Description ==

OpenClaw API provides a secure REST API for remote management of your WordPress site. 
Designed to work with OpenClaw AI assistants, it enables remote content creation, 
plugin management, and site administration.

**Features:**

* **Content Management** - Create, update, and delete posts, pages, categories, and tags
* **Plugin Management** - Search, install, activate, deactivate, update, and delete plugins
* **Plugin Integrations** - Auto-detecting modules for FluentCRM, FluentForms, FluentCommunity, FluentProject, FluentSupport, PublishPress
* **Fine-grained Permissions** - Enable only the capabilities you need
* **Token-based Authentication** - Secure API token system (works behind Cloudflare)
* **WordPress.org Integration** - Search and install plugins directly from the repository

**Integration Modules:**

Modules automatically activate when their required plugins are installed:

* **FluentForms** - Forms, entries, submissions
* **FluentCommunity** - Posts, groups, members
* **FluentCRM** - Subscribers, lists, campaigns
* **FluentProject** - Projects, tasks, boards
* **FluentSupport** - Tickets, responses, customers
* **PublishPress Statuses** - Custom post statuses

**Security:**

* All endpoints require authentication via `X-OpenClaw-Token` header
* Tokens are hashed for secure storage
* Dangerous actions (plugin install, activate, delete) are disabled by default
* Token can be regenerated or deleted at any time
* Works with Cloudflare and other CDNs that strip Authorization headers
* Timing-safe token comparison prevents timing attacks

== Installation ==

1. Upload the `openclaw-api` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → OpenClaw API
4. Generate an API token
5. Enable the capabilities you need
6. Use the token in your API requests with the `X-OpenClaw-Token` header

== Frequently Asked Questions ==

= Why not use the WordPress REST API? =

The standard WordPress REST API uses the Authorization header, which is stripped by 
Cloudflare and some other CDNs. OpenClaw API uses a custom header (`X-OpenClaw-Token`) 
that works reliably behind these services.

= Is this plugin secure? =

Yes. All endpoints require a valid API token. Tokens are hashed using WordPress's 
secure hashing. Dangerous operations are disabled by default and must be explicitly 
enabled in the settings. Tokens can be regenerated or revoked at any time.

= Can I use this with other tools besides OpenClaw? =

Yes! Any tool that can make HTTP requests with custom headers can use this API.

== Usage ==

**Authentication:**

```bash
curl -H "X-OpenClaw-Token: YOUR_TOKEN" \
    https://yoursite.com/wp-json/openclaw/v1/site
```

**List Posts:**

```bash
curl -H "X-OpenClaw-Token: YOUR_TOKEN" \
    https://yoursite.com/wp-json/openclaw/v1/posts
```

**Create a Post:**

```bash
curl -X POST \
    -H "X-OpenClaw-Token: YOUR_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"title":"My Post","content":"Post content","status":"draft"}' \
    https://yoursite.com/wp-json/openclaw/v1/posts
```

**Search Plugins:**

```bash
curl -H "X-OpenClaw-Token: YOUR_TOKEN" \
    "https://yoursite.com/wp-json/openclaw/v1/plugins/search?q=seo"
```

**Install and Activate a Plugin:**

```bash
curl -X POST \
    -H "X-OpenClaw-Token: YOUR_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"slug":"wordpress-seo","activate":true}' \
    https://yoursite.com/wp-json/openclaw/v1/plugins/install
```

== API Endpoints ==

| Method | Endpoint | Capability | Description |
|--------|----------|------------|-------------|
| GET | `/ping` | - | Health check (no auth) |
| GET | `/site` | site_info | Site information |
| GET | `/posts` | posts_read | List posts |
| POST | `/posts` | posts_create | Create post |
| PUT | `/posts/{id}` | posts_update | Update post |
| DELETE | `/posts/{id}` | posts_delete | Delete post |
| GET | `/pages` | pages_read | List pages |
| POST | `/pages` | pages_create | Create page |
| GET | `/categories` | categories_read | List categories |
| POST | `/categories` | categories_create | Create category |
| GET | `/tags` | tags_read | List tags |
| POST | `/tags` | tags_create | Create tag |
| GET | `/media` | media_read | List media |
| GET | `/users` | users_read | List users |
| GET | `/plugins` | plugins_read | List installed plugins |
| GET | `/plugins/search` | plugins_search | Search WordPress.org |
| POST | `/plugins/install` | plugins_install | Install plugin |
| POST | `/plugins/{slug}/activate` | plugins_activate | Activate plugin |
| POST | `/plugins/{slug}/deactivate` | plugins_deactivate | Deactivate plugin |
| POST | `/plugins/{slug}/update` | plugins_update | Update plugin |
| DELETE | `/plugins/{slug}` | plugins_delete | Delete plugin |

== Changelog ==

= 2.6.43 =
* Fixed: FluentCRM campaign recipients showing as "unknown" in fallback mode
* Fixed: FluentCRM campaign emails failing to send in fallback mode
* Improvement: Manual fallback now populates email, first_name, and last_name in campaign email records
* Improvement: send_campaign fallback now fires 'fluentcrm_campaign_status_changed' action to trigger processing

= 2.3.3 =
* Fixed: Core capabilities form no longer clears module capability settings
* Root cause: Core handler was using filtered function that included module caps
* Solution: New openclaw_get_core_capabilities() returns only core capabilities

= 2.3.2 =
* Fixed: Core and Module capabilities forms now save independently without clearing each other
* Two separate save handlers - each preserves the other section's values

= 2.3.1 =
* Fixed: Capabilities form now preserves both core and module settings when saving either section
* Security: Mass assignment protection in FluentCRM subscriber updates (field whitelist)
* Security: Input validation in FluentProject updates (status/priority allowlists)
* Security: Content sanitization in FluentProject comments (wp_kses_post)
* Security: User ID validation in assignment functions
* Security: CSV injection prevention in FluentForms export

= 2.3.0 =
* Added plugin/theme ZIP upload endpoint (POST /plugins/upload)
* Added automatic update checking from GitHub (no configuration needed)
* Added one-click update button when newer version available
* Added dynamic module capability registration via filter hook
* Added "Detected Integrations" section showing active module capabilities
* Fixed module detection for FluentCommunity, FluentProject, FluentSupport
* Added `plugins_upload` capability for ZIP uploads (off by default)
* Module capabilities: each detected plugin shows its own capability toggles
* Security: ZIP upload validates structure, scans for suspicious files
* Security: Max 10MB upload size for plugins/themes

= 2.2.1 =
* Fixed module detection timing (routes now register correctly at plugins_loaded)
* Fixed FluentProject detection (Fluent Boards plugin slug)
* Fixed FluentSupport detection (fluent-support plugin slug)
* Added GitHub auto-updater (updates from github.com/openclaw/openclaw-api)
* Added `/self-update` endpoint for remote plugin updates
* Fixed syntax error in FluentSupport module (extra closing brace)

= 2.2.0 =
* Added modular integration system with auto-detection
* Added FluentForms module (forms, entries, submissions)
* Added FluentCommunity module (posts, groups, members)
* Added FluentCRM module (subscribers, lists, campaigns)
* Added FluentProject module (projects, tasks, boards)
* Added FluentSupport module (tickets, responses)
* Added PublishPress Statuses module (custom statuses API)
* Modules auto-activate when their plugins are installed
* Settings page shows active/inactive module status

= 2.0.5 =
* SECURITY: Added input sanitization for API token header (wp_unslash, sanitize_text_field)
* SECURITY: Added output escaping for JavaScript in admin page (esc_js)
* Fixed WordPress coding standards compliance warnings

= 2.0.4 =
* Removed .gitignore (WordPress.org directory requirement)
* Updated "Tested up to" to WordPress 6.9

= 2.0.3 =
* Changed license from AGPLv3.0 to GPLv2 or later for WordPress compatibility

= 2.0.2 =
* SECURITY: Token now hashed before storage (tokens shown once on generation)
* SECURITY: Fixed post type validation (can only modify 'post' type)
* SECURITY: Added post existence check before update/delete
* SECURITY: Removed email from users endpoint (privacy protection)
* Added null checks in format_post function
* Added missing term validation in category/tag creation

= 2.0.1 =
* SECURITY: Fixed timing attack vulnerability in token verification
* SECURITY: Added post status validation (only draft, pending, private, publish allowed)
* SECURITY: Added author ID validation (validates user exists before assignment)
* Added plugin slug validation (lowercase alphanumeric with hyphens only)
* Added search query length limits (max 200 characters)
* Added pagination limits (max 100 per page, min page 1)

= 2.0.0 =
* Renamed from Lilith API to OpenClaw API
* New API namespace: `/wp-json/openclaw/v1/`
* New auth header: `X-OpenClaw-Token`
* Added fine-grained capability controls
* Added plugin management endpoints

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.3 =
License changed to GPLv2 or later. No functional changes.

= 2.0.2 =
**Important:** Token storage has changed. After updating, you will need to regenerate 
your API token in Settings → OpenClaw API. The new token will be shown ONCE - save it 
securely.

= 2.0.0 =
Breaking change: API namespace changed from `lilith/v1` to `openclaw/v1`. 
The auth header changed from `X-Lilith-Token` to `X-OpenClaw-Token`.
You will need to regenerate your API token after upgrading.