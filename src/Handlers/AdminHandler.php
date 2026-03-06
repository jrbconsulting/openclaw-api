<?php
namespace JRB\RemoteApi\Handlers;

if (!defined('ABSPATH')) exit;

/**
 * 🛠️ AdminHandler
 * Guaranteed 1:1 Feature Registry from Reference Source (r3470814).
 * Conditional Display logic applied (Clean UI).
 */
class AdminHandler {
    
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu() {
        add_options_page(
            'JRB Remote API',
            'JRB Remote API',
            'manage_options',
            'jrb-remote-site-api-for-openclaw',
            [self::class, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        // 1. Handling Token Generation
        if (isset($_POST['jrbremote_generate']) && check_admin_referer('jrbremote_settings')) {
            $token = wp_generate_password(64, false);
            update_option('jrbremote_api_token_hash', wp_hash($token));
            delete_option('jrbremote_api_token');
            echo '<div class="notice notice-warning is-dismissible"><p><strong>★ NEW API TOKEN GENERATED:</strong><br><code style="font-size:1.2em; display:block; padding:10px; background:#f0f0f1; border:1px solid #ccc; margin-top:10px;">' . esc_html($token) . '</code><br><em>Save this now. It is stored using secure hashing and cannot be recovered.</em></p></div>';
        }

        // 2. Handling Capability Saves
        if (isset($_POST['jrbremote_save_caps']) && check_admin_referer('jrbremote_capabilities')) {
            $to_save = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'cap_') === 0) {
                    $cap = substr($key, 4);
                    $to_save[$cap] = true;
                }
            }
            update_option('jrbremote_api_capabilities', $to_save);
            echo '<div class="notice notice-success is-dismissible"><p>Strategic capabilities updated successfully.</p></div>';
        }

        $has_token = (bool)get_option('jrbremote_api_token_hash');
        $saved_caps = get_option('jrbremote_api_capabilities', []);
        $groups = self::get_conditional_granular_capabilities();

        ?>
        <div class="wrap">
            <h1>JRB Remote Site API Settings</h1>
            
            <div class="card" style="padding:20px; margin-top:20px; background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top:0;">1. Authentication</h2>
                <p>Connection: <?php echo $has_token ? '<span style="color:green; font-weight:bold;">🟢 ACTIVE (Hashed Security)</span>' : '<span style="color:red; font-weight:bold;">🔴 DISCONNECTED (No Token)</span>'; ?></p>
                <form method="post">
                    <?php wp_nonce_field('jrbremote_settings'); ?>
                    <input type="submit" name="jrbremote_generate" class="button button-primary" value="<?php echo $has_token ? 'Regenerate API Token' : 'Generate API Token'; ?>">
                </form>
                <p class="description">Requires <code>X-JRB-Token</code> header for all requests.</p>
            </div>

            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field('jrbremote_capabilities'); ?>
                <h2 style="margin-bottom:10px;">2. Dynamic Capability Matrix</h2>
                <p class="description" style="margin-bottom:20px;">Granular permissions are automatically synchronized with installed plugins. Deselect all to fail-close access.</p>
                
                <?php foreach ($groups as $group_name => $caps) : ?>
                    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; border-radius:4px;">
                        <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:12px; font-size:1.1em;"><?php echo esc_html($group_name); ?></h3>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                        <?php foreach ($caps as $slug => $info) : ?>
                            <label style="display:flex; align-items:center; cursor:pointer;" title="<?php echo esc_attr($slug); ?>">
                                <input type="checkbox" name="cap_<?php echo esc_attr($slug); ?>" value="1" <?php checked(!empty($saved_caps[$slug])); ?> style="margin-right:8px;">
                                <span style="font-size:13px;"><?php echo esc_html($info['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div style="position: sticky; bottom: 20px; background: rgba(255,255,255,0.95); padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); z-index:99;">
                    <?php submit_button('Save Strategic Permissions', 'primary', 'jrbremote_save_caps', false); ?>
                    <span class="description" style="margin-left:15px; vertical-align:middle;">Policy changes strike all endpoints immediately.</span>
                </div>
            </form>
        </div>
        <?php
    }

    private static function get_conditional_granular_capabilities() {
        $groups = [];

        // === SYSTEM & WORDPRESS CORE ===
        $groups['System & Governance'] = [
            'site_info' => ['label' => 'Read Site Info'],
            'plugins_inspect' => ['label' => 'List Active Plugins'],
            'diagnostics' => ['label' => 'Health & Env Status'],
            'menus_read' => ['label' => 'List Nav Menus'],
            'menus_create' => ['label' => 'Create Nav Menus'],
            'themes_read' => ['label' => 'List Support Themes'],
        ];

        $groups['WordPress Content'] = [
            'posts_read' => ['label' => 'Read Posts'],
            'posts_create' => ['label' => 'Create Posts'],
            'posts_update' => ['label' => 'Update Posts'],
            'posts_delete' => ['label' => 'Delete Posts'],
            'posts_set_author' => ['label' => 'Set Post Author'],
            'pages_read' => ['label' => 'Read Pages'],
            'pages_create' => ['label' => 'Create Pages'],
            'pages_update' => ['label' => 'Update Pages'],
            'pages_delete' => ['label' => 'Delete Pages']
        ];

        // === MEDIA LIBRARY ===
        $groups['Media Management'] = [
            'media_read' => ['label' => 'View Media Library'],
            'media_upload' => ['label' => 'Upload Media Files'],
            'media_edit' => ['label' => 'Edit Media Metadata'],
            'media_delete' => ['label' => 'Hard Delete Media']
        ];

        // === FLUENTCRM (Conditional) ===
        if (defined('FLUENTCRM') || class_exists('\FluentCrm\App\App')) {
            $groups['FluentCRM (Strategic Marketing)'] = [
                'crm_subscribers_read' => ['label' => 'Read CRM Subscribers'],
                'crm_subscribers_create' => ['label' => 'Create New Contacts'],
                'crm_subscribers_update' => ['label' => 'Update Contact Info'],
                'crm_subscribers_delete' => ['label' => 'Delete Contacts'],
                'crm_lists_read' => ['label' => 'Read Mailing Lists'],
                'crm_lists_manage' => ['label' => 'Manage Lists (Add/Rem)'],
                'crm_tags_read' => ['label' => 'Read CRM Tags'],
                'crm_tags_manage' => ['label' => 'Manage Tags (Add/Rem)'],
                'crm_campaigns_read' => ['label' => 'List Email Campaigns'],
                'crm_campaigns_create' => ['label' => 'Draft New Campaigns'],
                'crm_campaigns_send' => ['label' => 'TRIGGER Email Sends'],
                'crm_reports_read' => ['label' => 'Access CRM Reports']
            ];
        }

        // === FLUENTSUPPORT (Conditional) ===
        if (class_exists('\FluentSupport\App\App')) {
            $groups['FluentSupport (Consulting Service)'] = [
                'support_tickets_read' => ['label' => 'Read Active Tickets'],
                'support_tickets_create' => ['label' => 'Open New Tickets'],
                'support_tickets_update' => ['label' => 'Update Ticket Status'],
                'support_tickets_delete' => ['label' => 'Purge Support Tickets'],
                'support_tickets_assign' => ['label' => 'Change Ticket Owners'],
                'support_responses_read' => ['label' => 'Read Support Thread'],
                'support_responses_create' => ['label' => 'Post Agent Replies'],
                'support_customers_read' => ['label' => 'Read Support Customers'],
                'support_customers_manage' => ['label' => 'Manage Support Profiles'],
                'support_sync' => ['label' => 'Sync Forms to Tickets']
            ];
        }

        // === FLUENTFORMS (Conditional) ===
        if (defined('FLUENTFORMS')) {
            $groups['FluentForms (Data Acquisition)'] = [
                'forms_read' => ['label' => 'Read Form Schema'],
                'forms_entries_read' => ['label' => 'Read Entry Data'],
                'forms_submissions_read' => ['label' => 'Read Submissions'],
                'forms_create' => ['label' => 'Create New Forms'],
                'forms_update' => ['label' => 'Update Existing Forms'],
                'forms_submit' => ['label' => 'Submit Entries via API'],
                'forms_entries_export' => ['label' => 'Export Entry Data'],
                'forms_delete' => ['label' => 'Delete Form Definitions'],
                'forms_entries_delete' => ['label' => 'Clear Entry History']
            ];
        }

        // === FLUENTPROJECT (Conditional) ===
        if (class_exists('\FluentProject\App\App')) {
            $groups['FluentProject (Internal Ops)'] = [
                'project_boards_read' => ['label' => 'Read Project Boards'],
                'project_tasks_read' => ['label' => 'Read Project Tasks'],
                'project_tasks_create' => ['label' => 'Create New Tasks'],
                'project_tasks_update' => ['label' => 'Update Task Progress'],
                'project_tasks_delete' => ['label' => 'Delete Project Tasks'],
                'project_comments_read' => ['label' => 'Read Task Comments'],
                'project_comments_create' => ['label' => 'Post Task Comments'],
                'project_boards_manage' => ['label' => 'Administer Task Boards'],
                'project_assign' => ['label' => 'Assign Task Owners']
            ];
        }

        // === FLUENTCOMMUNITY (Conditional) ===
        if (defined('FLUENT_COMMUNITY') || class_exists('\FluentCommunity\App\App')) {
            $groups['FluentCommunity (Engagement)'] = [
                'community_posts_read' => ['label' => 'Read Forum Posts'],
                'community_posts_create' => ['label' => 'Create Forum Posts'],
                'community_posts_update' => ['label' => 'Update Forum Content'],
                'community_posts_delete' => ['label' => 'Delete Forum Content'],
                'community_groups_read' => ['label' => 'Read Comm. Groups'],
                'community_groups_manage' => ['label' => 'Manage Comm. Groups'],
                'community_members_read' => ['label' => 'Read Comm. Members'],
                'community_members_manage' => ['label' => 'Manage Comm. Members'],
                'community_comments_read' => ['label' => 'Read Comm. Comments'],
                'community_comments_create' => ['label' => 'Post Comm. Comments']
            ];
        }

        // === PUBLISHPRESS ===
        if (defined('PP_VERSION') || class_exists('PPServices')) {
            $groups['PublishPress (CMS Workflow)'] = [
                'statuses_read' => ['label' => 'Read Editorial Statuses']
            ];
        }

        return $groups;
    }
}
