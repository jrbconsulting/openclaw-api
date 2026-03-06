<?php
/**
 * Mock WordPress environment for testing
 */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');

$GLOBALS['wp_options'] = [];

function register_rest_route($ns, $route, $args) {}
function get_bloginfo($key) { return 'Mock Blog'; }
function add_action($hook, $callback) {}
function add_filter($hook, $callback) {}

function get_option($key, $default = false) {
    return isset($GLOBALS['wp_options'][$key]) ? $GLOBALS['wp_options'][$key] : $default;
}

function update_option($key, $value) {
    $GLOBALS['wp_options'][$key] = $value;
    return true;
}

function delete_option($key) {
    unset($GLOBALS['wp_options'][$key]);
}

function checked($checked, $current = true, $echo = true) {
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) echo $result;
    return $result;
}

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) {
        $this->data = $data;
        $this->status = $status;
    }
}

class WP_Error {
    public $errors = [];
    public function __construct($code = '', $message = '', $data = '') {
        $this->errors[$code][] = $message;
    }
}
function is_wp_error($thing) { return ($thing instanceof WP_Error); }

function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return $v; }
function wp_hash($v) { return md5($v); }
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) { return $a === $b; }
}

function current_time($type) { return date('Y-m-d H:i:s'); }
function wp_generate_password($length, $special) { return bin2hex(random_bytes($length/2)); }
function check_admin_referer($a) { return true; }
function wp_nonce_field($a) {}
function esc_attr($val) { return $val; }
function esc_html($val) { return $val; }
function settings_fields($g) {}
function submit_button() {}
function current_user_can($p) { return true; }
function add_options_page() {}
function register_setting() {}
