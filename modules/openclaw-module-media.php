<?php
/**
 * OpenClaw API - Media Module
 * 
 * Provides REST endpoints for media management.
 * Supports file upload, listing, retrieval, and deletion.
 * 
 * Capabilities:
 * - media_read: View media library
 * - media_upload: Upload new media files
 * - media_delete: Delete media files
 * - media_edit: Edit media metadata
 */

if (!defined('ABSPATH')) exit;

/**
 * Security Constants
 */
if (!defined('OPENCLAW_MEDIA_MAX_UPLOAD_SIZE')) {
    define('OPENCLAW_MEDIA_MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB default
}

/**
 * Security: Generate CSRF token for upload forms
 */
function openclaw_generate_media_csrf_token() {
    return wp_create_nonce('openclaw_media_upload');
}

/**
 * Security: Verify CSRF token for upload requests
 */
function openclaw_verify_media_csrf($request) {
    $token = $request->get_header('X-CSRF-Token') ?: $request->get_param('csrf_token');
    if (empty($token) || !wp_verify_nonce($token, 'openclaw_media_upload')) {
        return false;
    }
    return true;
}

/**
 * Register Media API routes
 */
add_action('rest_api_init', function() {
    // List media
    register_rest_route('openclaw/v1', '/media', [
        'methods' => 'GET',
        'callback' => 'openclaw_media_list',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_read'); },
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
                'validate_callback' => function($val) {
                    // Limit per_page to prevent DoS via large result sets
                    return $val > 0 && $val <= 100;
                },
            ],
            'mime_type' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($val) {
                    return in_array($val, ['image', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf']);
                },
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'parent' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'author' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
    
    // Upload media - CSRF protected
    register_rest_route('openclaw/v1', '/media', [
        'methods' => 'POST',
        'callback' => 'openclaw_media_upload',
        'permission_callback' => function() { 
            // Verify CSRF token if provided (required for form uploads)
            $token = null;
            if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $token = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CSRF_TOKEN']));
            } elseif (isset($_REQUEST['csrf_token'])) {
                $token = sanitize_text_field($_REQUEST['csrf_token']);
            }
            
            // For API calls, we check capability but allow requests without CSRF
            // CSRF is primarily for form-based uploads from trusted sources
            if (!empty($token) && !wp_verify_nonce($token, 'openclaw_media_upload')) {
                return new WP_Error('csrf_invalid', 'CSRF token validation failed', ['status' => 403]);
            }
            
            return openclaw_verify_token_and_can('media_upload'); 
        },
    ]);
    
    // Get single media
    register_rest_route('openclaw/v1', '/media/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'openclaw_media_get',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_read'); },
        'args' => [
            'id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
    
    // Update media metadata
    register_rest_route('openclaw/v1', '/media/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'openclaw_media_update',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_edit'); },
        'args' => [
            'id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
    
    // Delete media
    register_rest_route('openclaw/v1', '/media/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_media_delete',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_delete'); },
        'args' => [
            'id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'force' => [
                'type' => 'boolean',
                'default' => false,
            ],
        ],
    ]);
});

/**
 * Register media capabilities
 */
add_filter('openclaw_module_capabilities', function($caps) {
    return array_merge($caps, [
        'media_read' => ['label' => 'View Media Library', 'default' => true, 'group' => 'Media'],
        'media_upload' => ['label' => 'Upload Media Files', 'default' => false, 'group' => 'Media'],
        'media_edit' => ['label' => 'Edit Media Metadata', 'default' => false, 'group' => 'Media'],
        'media_delete' => ['label' => 'Delete Media Files', 'default' => false, 'group' => 'Media'],
    ]);
});

/**
 * Get allowed MIME types for upload
 * 
 * @return array Array of allowed MIME types
 */
function openclaw_get_allowed_mime_types() {
    // Whitelist of safe mime types for upload
    $allowed = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml', // Requires sanitization
        // Documents
        'application/pdf',
        // Max file size per type (in bytes)
    ];
    
    return apply_filters('openclaw_allowed_mime_types', $allowed);
}

/**
 * Get maximum upload size
 * 
 * @return int Maximum size in bytes
 */
function openclaw_get_max_upload_size() {
    // Default 10MB, filterable
    $max_size = apply_filters('openclaw_max_upload_size', 10 * 1024 * 1024);
    
    // Respect server limits
    $server_max = wp_max_upload_size();
    return min($max_size, $server_max);
}

/**
 * Validate and sanitize a filename for upload
 * Prevents path traversal and other malicious filename tricks
 * 
 * @param string $filename Original filename
 * @return string|WP_Error Sanitized filename or error
 */
function openclaw_sanitize_filename($filename) {
    // CRITICAL: Path traversal prevention - remove directory separators
    $filename = str_replace(['../', '..\\', './', '\\'], '', $filename);
    
    // Remove leading dots (hidden files)
    $filename = ltrim($filename, '.');
    
    // Remove special characters but allow safe ones (._-)
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename);
    
    // Remove multiple consecutive dashes
    $filename = preg_replace('/-+/', '-', $filename);
    
    // Remove multiple consecutive dots
    $filename = preg_replace('/\.+/', '.', $filename);
    
    // Trim dashes and dots from ends
    $filename = trim($filename, '-.');
    
    if (empty($filename)) {
        // Default safe filename if sanitization removes everything
        $filename = 'upload-' . md5(uniqid()) . '.dat';
    }
    
    // Ensure extension is lowercase
    $parts = explode('.', $filename);
    $ext = array_pop($parts);
    
    // Validate extension length (prevent buffer overflow style attacks)
    if (strlen($ext) > 10) {
        $ext = 'bin'; // Force to binary if extension is suspiciously long
    }
    
    if (!empty($parts)) {
        $base = implode('.', $parts);
        
        // Limit base name length
        if (strlen($base) > 200) {
            $base = substr($base, 0, 200);
        }
        
        $filename = $base . '.' . strtolower($ext);
    } else {
        $filename = strtolower($ext);
    }
    
    // WordPress also sanitizes on save, but we do our check here
    if (preg_match('/\.(php\d?|phtml|phps|php3|php4|php5|php7|phpt|pht|phar)$/i', $filename)) {
        // Replace dangerous extensions with .txt (safe fallback)
        $filename = preg_replace('/\.(php\d?|phtml|phps|php3|php4|php5|php7|phpt|pht|phar)$/i', '.txt', $filename);
    }
    
    return $filename;
}

/**
 * Validate SVG file for security
 * 
 * @param string $file_path Path to uploaded file
 * @return bool|WP_Error True if valid, WP_Error if not
 */
function openclaw_validate_svg($file_path) {
    // SVGs can contain malicious scripts, validate carefully
    // CRITICAL: Limit file read to prevent DoS via huge files
    $max_size = apply_filters('openclaw_svg_validation_max_size', 256 * 1024); // 256KB max for SVG scanning
    $file_size = filesize($file_path);
    
    if ($file_size > $max_size) {
        return new WP_Error(
            'svg_too_large',
            sprintf(__('SVG file exceeds maximum size of %s for validation.', 'jrb-remote-site-api-openclaw'), size_format($max_size))
        );
    }
    
    $content = file_get_contents($file_path, false, null, 0, $max_size);
    
    if ($content === false) {
        return new WP_Error(
            'svg_read_failed',
            __('Failed to read SVG file for security validation.', 'jrb-remote-site-api-openclaw')
        );
    }
    
    // Dangerous patterns - comprehensive XSS prevention
    $dangerous = [
        // Script tags
        '/<script\b/i',
        '/<\s*script\b/i',
        '/<\/\s*script\s*>/i',
        
        // Event handlers (XSS vectors)
        '/on\w+\s*=\s*["\']?[^"\'\s>]+/i',
        '/on\w+\s*=/i',
        
        // Dangerous JavaScript protocols
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/data\s*:\s*text\/html/i',
        
        // Object/embed/iframe (potential XSS/vector execution)
        '/<\s*(object|embed|iframe)\b/i',
        
        // PHP execution vectors (if server misconfigured)
        '/<\?php/i',
        '/<\?=/i',
        
        // Dangerous function calls
        '/eval\s*\(/i',
        '/exec\s*\(/i',
        '/system\s*\(/i',
        '/passthru\s*\(/i',
        '/shell_exec\s*\(/i',
        '/call_user_func/i',
        
        // Base64 encoded scripts (obfuscation)
        '/base64_decode\s*\(/i',
        
        // DOM manipulation via data URIs
        '/document\s*\.\s*(write|writeln|cookie|location|domain|redirect|assign)/i',
    ];
    
    foreach ($dangerous as $pattern) {
        if (preg_match($pattern, $content)) {
            return new WP_Error(
                'unsafe_svg',
                __('This SVG file contains potentially unsafe content that could lead to XSS attacks.', 'jrb-remote-site-api-openclaw')
            );
        }
    }
    
    // Additional SVG security: limit elements to prevent XML-based DoS
    $xml_elements = preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9]*)\b/', $content, $matches);
    
    // Warning but not blocking - configurable
    if ($xml_elements > 5000) {
        // Log warning but allow (could be legitimate complex SVG)
        error_log(sprintf('OpenClaw Media: Large SVG detected with %d elements', $xml_elements));
    }
    
    return true;
}

/**
 * List media items
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function openclaw_media_list($request) {
    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $request->get_param('per_page') ?: 20,
        'paged' => $request->get_param('page') ?: 1,
    ];
    
    // Filter by mime type
    if ($mime_type = $request->get_param('mime_type')) {
        if ($mime_type === 'image') {
            // All image types
            $args['post_mime_type'] = 'image';
        } else {
            $args['post_mime_type'] = $mime_type;
        }
    }
    
    // Search
    if ($search = $request->get_param('search')) {
        $args['s'] = $search;
    }
    
    // Parent post
    if ($parent = $request->get_param('parent')) {
        $args['post_parent'] = $parent;
    }
    
    // Author
    if ($author = $request->get_param('author')) {
        $args['author'] = $author;
    }
    
    $query = new WP_Query($args);
    $items = [];
    
    foreach ($query->posts as $post) {
        $items[] = openclaw_format_media_item($post);
    }
    
    return new WP_REST_Response([
        'items' => $items,
        'total' => $query->found_posts,
        'pages' => $query->max_num_pages,
        'page' => $request->get_param('page') ?: 1,
    ]);
}

/**
 * Format a media item for API response
 * 
 * @param WP_Post $post Attachment post
 * @return array Formatted item
 */
function openclaw_format_media_item($post) {
    $meta = wp_get_attachment_metadata($post->ID);
    $url = wp_get_attachment_url($post->ID);
    
    $item = [
        'id' => $post->ID,
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'url' => $url,
        'mime_type' => $post->post_mime_type,
        'alt_text' => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
        'caption' => $post->post_excerpt,
        'description' => $post->post_content,
        'date' => $post->post_date,
        'modified' => $post->post_modified,
        'author_id' => $post->post_author,
        'parent_id' => $post->post_parent,
    ];
    
    // Add image sizes if available
    if ($meta && isset($meta['sizes'])) {
        $sizes = [];
        foreach ($meta['sizes'] as $size_name => $size_data) {
            $src = wp_get_attachment_image_src($post->ID, $size_name);
            if ($src) {
                $sizes[$size_name] = [
                    'url' => $src[0],
                    'width' => $src[1],
                    'height' => $src[2],
                ];
            }
        }
        $item['sizes'] = $sizes;
        
        if (isset($meta['width'], $meta['height'])) {
            $item['width'] = $meta['width'];
            $item['height'] = $meta['height'];
        }
    }
    
    // Add file size
    $file_path = get_attached_file($post->ID);
    if ($file_path && file_exists($file_path)) {
        $item['file_size'] = filesize($file_path);
    }
    
    return $item;
}

/**
 * Upload media file
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function openclaw_media_upload($request) {
    
    // Get file from request
    $files = $request->get_file_params();
    
    if (empty($files)) {
        // Try alternative file upload methods
        $file = false;
        
        // Check for raw body with filename header
        $disposition = $request->get_header('content_disposition');
        if ($disposition && $request->get_body()) {
            // Parse filename from Content-Disposition
            if (preg_match('/filename="?([^";]+)"?/i', $disposition, $matches)) {
                $filename = $matches[1];
                
                // CRITICAL: Validate Content-Length header to prevent DoS
                $content_length = $request->get_header('content_length');
                if ($content_length) {
                    $content_length = (int) $content_length;
                    $max_upload = openclaw_get_max_upload_size();
                    
                    if ($content_length > $max_upload) {
                        return new WP_Error(
                            'content_too_large',
                            __('Request body exceeds maximum allowed size.', 'jrb-remote-site-api-openclaw'),
                            ['status' => 413]
                        );
                    }
                }
                
                // Need to save body to temp file first
                $temp_path = wp_tempnam($filename);
                $body_data = $request->get_body();
                
                // Additional size check on raw body
                if (strlen($body_data) > $max_upload) {
                    @unlink($temp_path);
                    return new WP_Error(
                        'body_too_large',
                        __('Request body exceeds maximum allowed size.', 'jrb-remote-site-api-openclaw'),
                        ['status' => 413]
                    );
                }
                
                file_put_contents($temp_path, $body_data);
                
                $file = [
                    'name' => $filename,
                    'tmp_name' => $temp_path,
                    'size' => strlen($body_data),
                    'error' => 0,
                ];
            }
        }
    } else {
        // Standard multipart upload
        $file = array_shift($files);
    }
    
    if (empty($file) || $file['error'] !== 0) {
        return new WP_Error(
            'upload_error',
            __('No file uploaded or upload error.', 'jrb-remote-site-api-openclaw'),
            ['status' => 400]
        );
    }
    
    // Validate file size (real size, not user-provided)
    $file_size = filesize($file['tmp_name']);
    $max_size = openclaw_get_max_upload_size();
    
    if ($file_size === false || $file_size > $max_size) {
        return new WP_Error(
            'file_too_large',
            sprintf(
                __('File size exceeds maximum of %s.', 'jrb-remote-site-api-openclaw'),
                size_format($max_size)
            ),
            ['status' => 413]
        );
    }
    
    // Validate MIME type using multiple methods
    $allowed_types = openclaw_get_allowed_mime_types();
    
    // Method 1: WordPress internal check
    $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    
    // Method 2: Finfo for content-based verification
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actual_mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    // Validate mime type and extension match
    if ($file_type['type'] !== $actual_mime && !empty($actual_mime)) {
        // MIME type mismatch - could be a polyglot attack
        return new WP_Error(
            'mime_mismatch',
            __('File MIME type verification failed.', 'jrb-remote-site-api-openclaw'),
            ['status' => 400]
        );
    }
    
    if (!in_array($file_type['type'], $allowed_types, true)) {
        return new WP_Error(
            'invalid_file_type',
            sprintf(
                __('File type "%s" is not allowed. Allowed types: %s', 'jrb-remote-site-api-openclaw'),
                $file_type['type'],
                implode(', ', $allowed_types)
            ),
            ['status' => 400]
        );
    }
    
    // Validate internal file extension matches
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];
    
    if (isset($mime_to_ext[$file_type['type']])) {
        $expected_ext = $mime_to_ext[$file_type['type']];
        if ($ext !== $expected_ext && $ext !== strtoupper($expected_ext)) {
            // Extension mismatch - update to safe extension
            $file['name'] = preg_replace('/\.[^.]+$/i', '.' . $expected_ext, $file['name']);
        }
    }
    
    // Additional SVG validation
    if ($file_type['type'] === 'image/svg+xml') {
        $svg_check = openclaw_validate_svg($file['tmp_name']);
        if (is_wp_error($svg_check)) {
            return $svg_check;
        }
    }
    
    // Final security check: scan file content for PHP tags
    $file_content = file_get_contents($file['tmp_name'], false, null, 0, 4096);
    if ($file_content && preg_match('/<\?php|<\?=|<\s*script/i', $file_content)) {
        return new WP_Error(
            'suspicious_content',
            __('File contains suspicious code that could be executed on the server.', 'jrb-remote-site-api-openclaw'),
            ['status' => 400]
        );
    }
    
    // Sanitize filename (path traversal prevention)
    $filename = openclaw_sanitize_filename($file['name']);
    
    // Prepare upload
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    
    // Move to uploads directory with WordPress's security checks
    $upload = wp_handle_upload($file, [
        'test_form' => false,
        'action' => 'openclaw_upload_media',
    ]);
    
    if (isset($upload['error'])) {
        return new WP_Error(
            'upload_failed',
            $upload['error'],
            ['status' => 500]
        );
    }
    
    // Verify uploaded file is in the correct directory
    $upload_dir = wp_upload_dir();
    $relative_path = str_replace($upload_dir['basedir'] . '/', '', $upload['file']);
    if (strpos($relative_path, '..') !== false || strpos($relative_path, '/') === 0) {
        @unlink($upload['file']);
        return new WP_Error(
            'upload_security',
            __('Upload directory traversal attempt detected.', 'jrb-remote-site-api-openclaw'),
            ['status' => 403]
        );
    }
    
    // Get additional metadata from request
    $title = $request->get_param('title') ?: sanitize_file_name(basename($filename));
    $alt_text = $request->get_param('alt_text') ?: '';
    $caption = $request->get_param('caption') ?: '';
    $description = $request->get_param('description') ?: '';
    $parent_id = $request->get_param('parent_id') ? absint($request->get_param('parent_id')) : 0;
    
    // Create attachment post
    $attachment = [
        'post_mime_type' => $upload['type'],
        'post_title' => wp_strip_all_tags($title),
        'post_excerpt' => wp_strip_all_tags($caption),
        'post_content' => $description,
        'post_status' => 'inherit',
        'post_parent' => $parent_id,
    ];
    
    $attachment_id = wp_insert_attachment($attachment, $upload['file'], $parent_id);
    
    if (is_wp_error($attachment_id)) {
        return new WP_Error(
            'attachment_failed',
            $attachment_id->get_error_message(),
            ['status' => 500]
        );
    }
    
    // Generate metadata and thumbnails for images
    if (strpos($upload['type'], 'image/') === 0) {
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    
    // Set alt text
    if ($alt_text) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }
    
    // Get the created attachment
    $post = get_post($attachment_id);
    
    return new WP_REST_Response(
        openclaw_format_media_item($post),
        201
    );
}

/**
 * Get single media item
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function openclaw_media_get($request) {
    $id = $request->get_param('id');
    $post = get_post($id);
    
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error(
            'not_found',
            __('Media item not found.', 'jrb-remote-site-api-openclaw'),
            ['status' => 404]
        );
    }
    
    return new WP_REST_Response(openclaw_format_media_item($post));
}

/**
 * Update media metadata
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function openclaw_media_update($request) {
    $id = $request->get_param('id');
    $post = get_post($id);
    
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error(
            'not_found',
            __('Media item not found.', 'jrb-remote-site-api-openclaw'),
            ['status' => 404]
        );
    }
    
    $args = ['ID' => $id];
    
    if ($title = $request->get_param('title')) {
        $args['post_title'] = sanitize_text_field($title);
    }
    
    if ($caption = $request->get_param('caption')) {
        $args['post_excerpt'] = sanitize_textarea_field($caption);
    }
    
    if ($description = $request->get_param('description')) {
        $args['post_content'] = wp_kses_post($description);
    }
    
    if ($parent_id = $request->get_param('parent_id')) {
        $args['post_parent'] = absint($parent_id);
    }
    
    // Update post
    $result = wp_update_post($args, true);
    
    if (is_wp_error($result)) {
        return new WP_Error(
            'update_failed',
            $result->get_error_message(),
            ['status' => 500]
        );
    }
    
    // Update alt text separately
    if ($alt_text = $request->get_param('alt_text')) {
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }
    
    // Get updated post
    $post = get_post($id);
    
    return new WP_REST_Response(openclaw_format_media_item($post));
}

/**
 * Delete media item
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function openclaw_media_delete($request) {
    $id = $request->get_param('id');
    $force = $request->get_param('force');
    
    $post = get_post($id);
    
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error(
            'not_found',
            __('Media item not found.', 'jrb-remote-site-api-openclaw'),
            ['status' => 404]
        );
    }
    
    // Check if file is used in content
    $url = wp_get_attachment_url($id);
    $usage_count = 0;
    
    if ($url) {
        global $wpdb;
        // Check posts content for this URL
        $usage_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_content LIKE %s 
             AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like($url) . '%'
        ));
    }
    
    if ($usage_count > 0 && !$force) {
        return new WP_Error(
            'media_in_use',
            sprintf(
                _n(
                    'This media is used in %d post. Set force=true to delete anyway.',
                    'This media is used in %d posts. Set force=true to delete anyway.',
                    $usage_count,
                    'jrb-remote-site-api-openclaw'
                ),
                $usage_count
            ),
            ['status' => 409, 'usage_count' => $usage_count]
        );
    }
    
    // Delete the attachment
    $result = wp_delete_attachment($id, true); // true = skip trash, delete file
    
    if (!$result) {
        return new WP_Error(
            'delete_failed',
            __('Failed to delete media item.', 'jrb-remote-site-api-openclaw'),
            ['status' => 500]
        );
    }
    
    return new WP_REST_Response([
        'deleted' => true,
        'id' => $id,
        'previous' => openclaw_format_media_item($post),
    ]);
}