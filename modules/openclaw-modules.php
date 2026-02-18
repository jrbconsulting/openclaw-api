<?php
/**
 * OpenClaw API - Module Loader
 * 
 * Loads optional modules for third-party plugin integrations.
 * Each module auto-detects if its required plugin is installed.
 */

if (!defined('ABSPATH')) exit;

// Load modules immediately - they'll register their own hooks
// Auth helper must load first
require_once __DIR__ . '/openclaw-module-auth.php';

$modules = [
    'openclaw-module-media.php',
    'openclaw-module-fluentforms.php',
    'openclaw-module-fluentcommunity.php',
    'openclaw-module-fluentcrm.php',
    'openclaw-module-fluentproject.php',
    'openclaw-module-fluentsupport.php',
    'openclaw-module-publishpress.php',
];

foreach ($modules as $module) {
    $path = __DIR__ . '/' . $module;
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Admin UI for modules - check status when page renders (after init)
 */
add_action('admin_footer-settings_page_openclaw-api', function() {
    // Use centralized detection function
    $modules = [
        'Media' => [
            'active' => true, // Always active - core WordPress feature
            'endpoints' => 'Upload, list, update, delete media files',
        ],
        'FluentForms' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentforms'),
            'endpoints' => 'Forms, entries, submissions',
        ],
        'FluentCommunity' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentcommunity'),
            'endpoints' => 'Posts, groups, members',
        ],
        'FluentCRM' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentcrm'),
            'endpoints' => 'Subscribers, lists, campaigns',
        ],
        'FluentProject' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentboards'),
            'endpoints' => 'Projects, tasks, boards',
        ],
        'FluentSupport' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentsupport'),
            'endpoints' => 'Tickets, responses',
        ],
        'PublishPress Statuses' => [
            'active' => function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('publishpress-statuses'),
            'endpoints' => 'Custom post statuses',
        ],
    ];
    ?>
    <script>
    jQuery(function($) {
        var modulesHtml = '<h2 style="margin-top:30px;">Integration Modules</h2>' +
            '<p style="color:#666;">Modules activate automatically when their required plugin is installed.</p>' +
            '<table class="widefat" style="max-width:600px;"><thead><tr><th>Module</th><th>Status</th><th>Endpoints</th></tr></thead><tbody>';
        
        <?php foreach ($modules as $name => $info): ?>
            var statusHtml = <?php echo $info['active'] ? "'<span style=\"color:green;font-weight:bold;\">✓ Active</span>'" : "'<span style=\"color:#999;\">Not installed</span>'"; ?>;
            modulesHtml += '<tr>' +
                '<td><strong><?php echo esc_js($name); ?></strong></td>' +
                '<td>' + statusHtml + '</td>' +
                '<td><?php echo esc_js($info['endpoints']); ?></td>' +
                '</tr>';
        <?php endforeach; ?>
        
        modulesHtml += '</tbody></table>';
        $('.wrap h1').after(modulesHtml);
    });
    </script>
    <?php
});