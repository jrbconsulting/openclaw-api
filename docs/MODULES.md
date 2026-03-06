# Module Architecture

**Version:** 6.5.0  
**Last Updated:** March 6, 2026

This document explains the module architecture of the JRB Remote Site API, including how modules are loaded, activated, and how to create new modules.

---

## Table of Contents

1. [How Modules Work](#how-modules-work)
2. [Module Lifecycle](#module-lifecycle)
3. [Module List](#module-list)
4. [Module Structure](#module-structure)
5. [Adding New Modules](#adding-new-modules)
6. [Module Dependencies](#module-dependencies)
7. [Performance Optimization](#performance-optimization)
8. [Troubleshooting](#troubleshooting)

---

## How Modules Work

The JRB Remote Site API uses a modular architecture to integrate with various WordPress plugins and provide REST API endpoints. Each module is responsible for a specific integration (FluentCRM, FluentSupport, Media, etc.).

### Key Principles

1. **Auto-Detection:** Modules automatically detect if their dependency plugin is installed
2. **Conditional Loading:** Only modules with active dependencies are loaded
3. **Isolated Capabilities:** Each module registers its own granular permissions
4. **Independent Routes:** Modules register their own REST API endpoints
5. **Centralized Logging:** All modules use consistent logging with unique prefixes

### Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│              JRB Remote Site API Plugin                  │
├─────────────────────────────────────────────────────────┤
│  Main Plugin File                                        │
│  - Authentication (X-JRBRemoteSite-Token)               │
│  - Token Verification                                    │
│  - Capability Management                                 │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────┐
│              Module Loader (modules-loader.php)          │
├─────────────────────────────────────────────────────────┤
│  - Checks plugin dependencies                            │
│  - Conditionally loads module files                      │
│  - Logs loading decisions                                │
└─────────────────────────────────────────────────────────┘
                          │
          ┌───────────────┼───────────────┐
          ▼               ▼               ▼
    ┌──────────┐   ┌──────────┐   ┌──────────┐
    │ FluentCRM│   │  Media   │   │Diagnostics│
    │  Module  │   │  Module  │   │  Module   │
    └──────────┘   └──────────┘   └──────────┘
          │               │               │
          ▼               ▼               ▼
    ┌──────────┐   ┌──────────┐   ┌──────────┐
    │  Routes  │   │  Routes  │   │  Routes  │
    │  Caps    │   │  Caps    │   │  Caps    │
    └──────────┘   └──────────┘   └──────────┘
```

---

## Module Lifecycle

### 1. Plugin Initialization

When WordPress loads the JRB Remote Site API plugin:

```php
// In main plugin file
add_action('plugins_loaded', 'jrb_remote_init', 10);

function jrb_remote_init() {
    // 1. Load module loader
    require_once plugin_dir_path(__FILE__) . 'modules/modules-loader.php';
    
    // 2. Initialize module loader (checks dependencies, loads modules)
    JRB_Remote_Module_Loader::init();
    
    // 3. Register core routes (auth, diagnostics, etc.)
    jrb_register_core_routes();
}
```

### 2. Module Loading

The module loader checks each module's dependency:

```php
class JRB_Remote_Module_Loader {
    private static $modules = [
        'fluentcrm' => [
            'class' => 'JRB_FluentCRM_Module',
            'dependency' => 'fluentcrm/fluentcrm.php'
        ],
        'fluentsupport' => [
            'class' => 'JRB_FluentSupport_Module',
            'dependency' => 'fluent-support/fluent-support.php'
        ],
        'media' => null, // Always loaded (WordPress core)
        'diagnostics' => null, // Always loaded (internal tool)
    ];

    public static function init() {
        $module_path = plugin_dir_path(__FILE__);
        
        foreach (self::$modules as $module_name => $config) {
            // Always load core modules
            if ($config === null) {
                self::load_module($module_path, $module_name);
                continue;
            }

            // Check if dependency is active BEFORE loading
            if (self::dependency_is_active($config['dependency'])) {
                self::load_module($module_path, $module_name);
            } else {
                self::log("Skipped module {$module_name}: dependency not active");
            }
        }
    }
}
```

### 3. Module Activation

Each module checks its dependency again and activates:

```php
class JRB_FluentCRM_Module {
    private static $active = false;

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'check_and_activate'], 15);
    }

    public static function check_and_activate() {
        if (!self::is_fluentcrm_active()) {
            return; // Exit early if dependency not active
        }

        self::$active = true;
        
        // Register REST API routes
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        
        // Register capabilities
        add_filter('jrb_module_capabilities', [__CLASS__, 'register_capabilities']);
        
        self::log('Module activated - FluentCRM integration enabled');
    }
}
```

### 4. Route Registration

Modules register their REST API endpoints:

```php
public static function register_routes() {
    register_rest_route('jrb/v1', '/crm/subscribers', [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'list_subscribers'],
        'permission_callback' => function() {
            return jrb_verify_token_and_can('crm_subscribers_read');
        }
    ]);
}
```

### 5. Capability Registration

Modules register their granular permissions:

```php
public static function register_capabilities($caps) {
    return array_merge($caps, [
        'crm_subscribers_read' => [
            'label' => 'Read Subscribers',
            'default' => true,
            'group' => 'FluentCRM'
        ],
        'crm_subscribers_create' => [
            'label' => 'Create Subscribers',
            'default' => false,
            'group' => 'FluentCRM'
        ],
        // ... more capabilities
    ]);
}
```

---

## Module List

### Core Modules (Always Loaded)

| Module | File | Dependency | Routes | Capabilities | Size |
|--------|------|------------|--------|--------------|------|
| **Media** | `module-media.php` | WordPress core | 5 | 4 | 27 KB |
| **Diagnostics** | `module-diagnostics.php` | WordPress core | 2 | 2 | 2.5 KB |

### Fluent Suite Modules

| Module | File | Dependency | Routes | Capabilities | Size |
|--------|------|------------|--------|--------------|------|
| **FluentCRM** | `module-fluentcrm.php` | FluentCRM | 16 | 12 | 42 KB |
| **FluentSupport** | `module-fluentsupport.php` | Fluent Support | 11 | 9 | 35 KB |
| **FluentForms** | `module-fluentforms.php` | Fluent Forms | 10 | 8 | 15 KB |
| **FluentProject** | `module-fluentproject.php` | Fluent Boards | 13 | 8 | 22 KB |
| **FluentCommunity** | `module-fluentcommunity.php` | Fluent Community | 10 | 10 | 10 KB |

### Third-Party Modules

| Module | File | Dependency | Routes | Capabilities | Size |
|--------|------|------------|--------|--------------|------|
| **PublishPress** | `module-publishpress.php` | PublishPress Statuses | 1 | 1 | 5.5 KB |

### Auth Module (Helper)

| Module | File | Purpose | Size |
|--------|------|---------|------|
| **Auth** | `module-auth.php` | Authentication helpers (legacy support) | 3.7 KB |

**Total:** 9 modules, 68+ routes, 54+ capabilities, ~163 KB

---

## Module Structure

Every module follows a consistent structure:

### Required Components

1. **Header Comment**
   - Module name
   - Description
   - Auto-activation behavior

2. **Security Check**
   ```php
   if (!defined('ABSPATH')) exit;
   ```

3. **Module Class**
   - Static `$active` property
   - `init()` method
   - `check_and_activate()` method
   - Dependency check method
   - `register_routes()` method
   - `register_capabilities()` method
   - Logging method

4. **Route Implementations**
   - Callback methods for each endpoint
   - Input validation and sanitization
   - Proper error handling with WP_Error
   - Consistent response format

### Template Module

```php
<?php
/**
 * JRB Remote Site API - {Module Name} Module
 * 
 * Auto-activates when {Dependency} plugin is installed.
 * Provides REST API access to {functionality}.
 */

if (!defined('ABSPATH')) exit;

class JRB_{Module_Name}_Module {

    private static $active = false;

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'check_and_activate'], 15);
    }

    public static function check_and_activate() {
        if (!self::is_{dependency}_active()) {
            return;
        }

        self::$active = true;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('jrb_module_capabilities', [__CLASS__, 'register_capabilities']);
        self::log('Module activated - {Module Name} integration enabled');
    }

    private static function is_{dependency}_active() {
        if (function_exists('jrb_is_plugin_active') && jrb_is_plugin_active('{dependency}')) {
            return true;
        }
        return class_exists('{Dependency_Class}');
    }
    
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            '{capability}_read' => ['label' => 'Read {Resource}', 'default' => true, 'group' => '{Module}'],
            '{capability}_create' => ['label' => 'Create {Resource}', 'default' => false, 'group' => '{Module}'],
        ]);
    }

    public static function register_routes() {
        register_rest_route('jrb/v1', '/{module}/{resource}', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_{resource}'],
            'permission_callback' => function() {
                return jrb_verify_token_and_can('{capability}_read');
            }
        ]);
    }

    public static function list_{resource}($request) {
        // Implementation here
        return new WP_REST_Response($data, 200);
    }

    private static function log($message) {
        error_log('[JRB {Module} Module] ' . $message);
    }
}

// Initialize module
JRB_{Module_Name}_Module::init();
```

---

## Adding New Modules

### Step 1: Create Module File

Create `modules/module-{name}.php`:

```php
<?php
/**
 * JRB Remote Site API - Example Module
 * 
 * Auto-activates when Example Plugin is installed.
 */

if (!defined('ABSPATH')) exit;

class JRB_Example_Module {

    private static $active = false;

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'check_and_activate'], 15);
    }

    public static function check_and_activate() {
        if (!self::is_example_active()) {
            return;
        }

        self::$active = true;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('jrb_module_capabilities', [__CLASS__, 'register_capabilities']);
        self::log('Module activated - Example integration enabled');
    }

    private static function is_example_active() {
        if (function_exists('jrb_is_plugin_active') && jrb_is_plugin_active('example-plugin')) {
            return true;
        }
        return class_exists('Example_Plugin_Class');
    }
    
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            'example_read' => ['label' => 'Read Example Data', 'default' => true, 'group' => 'Example'],
            'example_create' => ['label' => 'Create Example Data', 'default' => false, 'group' => 'Example'],
            'example_update' => ['label' => 'Update Example Data', 'default' => false, 'group' => 'Example'],
            'example_delete' => ['label' => 'Delete Example Data', 'default' => false, 'group' => 'Example'],
        ]);
    }

    public static function register_routes() {
        register_rest_route('jrb/v1', '/example/items', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_items'],
            'permission_callback' => function() {
                return jrb_verify_token_and_can('example_read');
            },
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ],
            ]
        ]);

        register_rest_route('jrb/v1', '/example/items', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_item'],
            'permission_callback' => function() {
                return jrb_verify_token_and_can('example_create');
            },
        ]);
    }

    public static function list_items($request) {
        try {
            $page = max(1, (int)($request->get_param('page') ?: 1));
            $per_page = min((int)($request->get_param('per_page') ?: 20), 100);
            
            // Your implementation here
            $items = []; // Fetch items from database
            
            return new WP_REST_Response([
                'data' => $items,
                'meta' => [
                    'total' => count($items),
                    'page' => $page,
                    'per_page' => $per_page,
                ]
            ], 200);
        } catch (Exception $e) {
            self::log('Error listing items: ' . $e->getMessage());
            return new WP_REST_Response([
                'error' => 'Failed to list items',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public static function create_item($request) {
        try {
            $params = $request->get_json_params();
            
            // Validate input
            if (empty($params['title'])) {
                return new WP_REST_Response([
                    'error' => 'Title is required'
                ], 400);
            }
            
            // Sanitize input
            $title = sanitize_text_field($params['title']);
            
            // Your implementation here
            $item_id = 123; // Create item
            
            self::log('Created item: ' . $item_id);
            
            return new WP_REST_Response([
                'success' => true,
                'id' => $item_id
            ], 201);
        } catch (Exception $e) {
            self::log('Error creating item: ' . $e->getMessage());
            return new WP_REST_Response([
                'error' => 'Failed to create item',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private static function log($message) {
        error_log('[JRB Example Module] ' . $message);
    }
}

// Initialize module
JRB_Example_Module::init();
```

### Step 2: Add to Module Loader

Edit `modules/modules-loader.php`:

```php
class JRB_Remote_Module_Loader {
    private static $modules = [
        // Existing modules...
        
        // Add your new module
        'example' => [
            'class' => 'JRB_Example_Module',
            'dependency' => 'example-plugin/example-plugin.php'
        ],
    ];
    
    // ... rest of the loader code
}
```

### Step 3: Test Module Activation

1. **With dependency inactive:**
   - Module should NOT load
   - Routes should NOT be registered
   - Capabilities should NOT be registered

2. **With dependency active:**
   - Module SHOULD load
   - Routes SHOULD be registered
   - Capabilities SHOULD be registered

3. **Test endpoints:**
   ```bash
   # Test with valid token and capability
   curl -H "X-JRBRemoteSite-Token: your-token" \
        https://yoursite.com/wp-json/jrb/v1/example/items
   
   # Test without capability (should return 403)
   curl -H "X-JRBRemoteSite-Token: read-only-token" \
        -X POST \
        -d '{"title":"Test"}' \
        https://yoursite.com/wp-json/jrb/v1/example/items
   ```

### Step 4: Document the Module

Add to `docs/MODULES.md`:
- Module name and description
- Dependency plugin
- Number of routes and capabilities
- List of capabilities with descriptions

Add to `docs/PERMISSIONS.md`:
- All capabilities with labels, defaults, and descriptions
- Endpoints using each capability

Add to `docs/API.md`:
- All endpoints with examples
- Request/response formats

---

## Module Dependencies

### Dependency Detection

Modules use a two-layer detection strategy:

```php
private static function is_fluentcrm_active() {
    // Layer 1: Centralized detection (preferred)
    if (function_exists('jrb_is_plugin_active') && jrb_is_plugin_active('fluentcrm')) {
        return true;
    }
    
    // Layer 2: Direct class check (fallback)
    return class_exists('FluentCRM\App\Models\Subscriber') 
        || class_exists('FluentCrm\App\Models\Subscriber');
}
```

### Centralized Plugin Detection

The main plugin file provides a helper function:

```php
function jrb_is_plugin_active($plugin_slug) {
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $plugin_files = [
        'fluentcrm' => 'fluentcrm/fluentcrm.php',
        'fluentsupport' => 'fluent-support/fluent-support.php',
        'fluentforms' => 'fluentform/fluentform.php',
        'fluentboards' => 'fluentboard/fluentboard.php',
        'fluentcommunity' => 'fluent-community/fluent-community.php',
        'publishpress-statuses' => 'publishpress-statuses/publishpress-statuses.php',
    ];
    
    if (isset($plugin_files[$plugin_slug])) {
        return is_plugin_active($plugin_files[$plugin_slug]);
    }
    
    return false;
}
```

### Dependency Mapping

| Module | Plugin Slug | Plugin File | Class Check |
|--------|-------------|-------------|-------------|
| FluentCRM | `fluentcrm` | `fluentcrm/fluentcrm.php` | `FluentCRM\App\Models\Subscriber` |
| FluentSupport | `fluentsupport` | `fluent-support/fluent-support.php` | `FluentSupport\App\Models\Ticket` |
| FluentForms | `fluentforms` | `fluentform/fluentform.php` | `FluentForm\App\Models\Form` |
| FluentProject | `fluentboards` | `fluentboard/fluentboard.php` | N/A (uses custom post types) |
| FluentCommunity | `fluentcommunity` | `fluent-community/fluent-community.php` | N/A (uses custom post types) |
| PublishPress | `publishpress-statuses` | `publishpress-statuses/publishpress-statuses.php` | N/A (uses global `$wp_post_statuses`) |

---

## Performance Optimization

### Conditional Loading

The module loader only loads modules when dependencies are active:

```php
// BEFORE (v6.4.x): Loads ALL modules unconditionally
foreach ($modules as $module) {
    include_once $module; // ❌ Wasteful
}

// AFTER (v6.5.0): Only loads active modules
foreach (self::$modules as $module_name => $config) {
    if ($config === null || self::dependency_is_active($config['dependency'])) {
        self::load_module($module_path, $module_name); // ✅ Efficient
    }
}
```

### Performance Impact

| Scenario | Modules Loaded | File I/O | Memory Usage | TTFB |
|----------|----------------|----------|--------------|------|
| Fresh WordPress (no plugins) | 2 (media, diagnostics) | 2 files | ~2 MB | < 50ms |
| WordPress + FluentCRM only | 3 (+ fluentcrm) | 3 files | ~3 MB | < 75ms |
| WordPress + All Fluent Suite | 7 (all modules) | 7 files | ~5 MB | < 100ms |

### Best Practices

1. **Early Exit:** Check dependency before any processing
   ```php
   if (!self::is_fluentcrm_active()) {
       return; // Exit immediately
   }
   ```

2. **Lazy Loading:** Only include files when needed
   ```php
   if (!function_exists('is_plugin_active')) {
       include_once(ABSPATH . 'wp-admin/includes/plugin.php');
   }
   ```

3. **Minimal Routes:** Register only necessary endpoints
   ```php
   // Group related endpoints
   register_rest_route('jrb/v1', '/crm/subscribers', [...]);
   register_rest_route('jrb/v1', '/crm/subscribers/(?P<id>\d+)', [...]);
   ```

4. **Efficient Queries:** Use pagination and limits
   ```php
   $per_page = min((int)($request->get_param('per_page') ?: 20), 100);
   $offset = ($page - 1) * $per_page;
   ```

5. **Caching:** Cache expensive operations when appropriate
   ```php
   $cache_key = 'jrb_crm_lists';
   $lists = wp_cache_get($cache_key);
   if (false === $lists) {
       $lists = $this->fetch_lists();
       wp_cache_set($cache_key, $lists, '', 300); // 5 min cache
   }
   ```

---

## Troubleshooting

### Module Not Loading

**Symptoms:**
- Endpoints return 404
- Capabilities not available in admin

**Check:**
1. Is the dependency plugin installed and active?
2. Is the module file present in `modules/` directory?
3. Is the module registered in `modules-loader.php`?
4. Check error log for module loading messages:
   ```bash
   tail -f wp-content/debug.log | grep "JRB.*Module"
   ```

### Routes Not Registered

**Symptoms:**
- Module loads but endpoints return 404

**Check:**
1. Is `rest_api_init` action firing?
2. Is the module's `$active` flag set to `true`?
3. Check error log for route registration:
   ```php
   self::log('Registering routes for {module}');
   ```

### Capabilities Not Available

**Symptoms:**
- Endpoints return 403 Forbidden
- Capabilities not showing in admin

**Check:**
1. Is `register_capabilities()` method implemented?
2. Is the filter hook correct? (`jrb_module_capabilities`)
3. Are capability names consistent between registration and permission callbacks?

### Permission Callback Failing

**Symptoms:**
- Valid token returns 403

**Check:**
1. Is the capability enabled for the token?
2. Is the capability name spelled correctly?
3. Test with a token that has all capabilities (`*`)

### Memory Issues

**Symptoms:**
- PHP memory limit errors
- Slow response times

**Check:**
1. How many modules are loading?
2. Are dependencies actually needed?
3. Consider increasing memory limit in `wp-config.php`:
   ```php
   define('WP_MEMORY_LIMIT', '256M');
   ```

---

## Module Development Checklist

Before submitting a new module:

- [ ] Module file follows naming convention (`module-{name}.php`)
- [ ] Class name follows convention (`JRB_{Name}_Module`)
- [ ] Security check present (`if (!defined('ABSPATH')) exit;`)
- [ ] Dependency check implemented (two-layer detection)
- [ ] `check_and_activate()` method with early exit
- [ ] `register_routes()` method with all endpoints
- [ ] `register_capabilities()` method with all capabilities
- [ ] All endpoints have `permission_callback`
- [ ] All capabilities have label, default, and group
- [ ] Input validation and sanitization on all endpoints
- [ ] Error handling with WP_Error or WP_REST_Response
- [ ] Consistent logging with module prefix
- [ ] Added to module loader configuration
- [ ] Tested with dependency inactive
- [ ] Tested with dependency active
- [ ] Documentation updated (MODULES.md, PERMISSIONS.md, API.md)

---

**For more information:**
- [Permissions Reference](./PERMISSIONS.md)
- [API Documentation](./API.md)
- [Installation Guide](./INSTALLATION.md)
