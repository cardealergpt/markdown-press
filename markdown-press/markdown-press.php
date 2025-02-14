<?php
/**
 * Plugin Name: Markdown Press
 * Plugin URI: https://example.com/markdown-press
 * Description: Export WordPress posts and pages as Markdown files individually or in bulk
 * Version: 1.0.1
 * Author: CarDealerGPT
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-press
 * Domain Path: /languages
 *
 * @package MarkdownPress
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for handling Markdown exports.
 */
class MarkdownPress {
    /**
     * Singleton instance
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', [$this, 'loadTextdomain']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_action('wp_ajax_markdown_press_export', [$this, 'handleExport']);
        
        // Add bulk and row actions for posts
        add_filter('bulk_actions-edit-post', [$this, 'addBulkAction']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handleBulkAction'], 10, 3);
        
        // Add bulk and row actions for pages
        add_filter('bulk_actions-edit-page', [$this, 'addBulkAction']);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handleBulkAction'], 10, 3);
        
        // Add row actions for all public post types
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_filter($post_type . '_row_actions', [$this, 'addRowAction'], 10, 2);
        }
        
        // Add admin notices for bulk export results
        add_action('admin_notices', [$this, 'showExportNotice']);
    }

    /**
     * Load plugin textdomain
     */
    public function loadTextdomain(): void {
        load_plugin_textdomain(
            'markdown-press',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Add admin menu item
     */
    public function addAdminMenu(): void {
        add_management_page(
            __('Markdown Export', 'markdown-press'),
            __('Markdown Export', 'markdown-press'),
            'manage_options',
            'markdown-press',
            [$this, 'renderAdminPage']
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAdminScripts(string $hook): void {
        // Get all public post type screens
        $post_type_screens = array_map(
            function($post_type) {
                return 'edit.php' . ($post_type === 'post' ? '' : '?post_type=' . $post_type);
            },
            get_post_types(['public' => true], 'names')
        );

        // Only load on our admin page or post type list screens
        if (!in_array($hook, array_merge(['tools_page_markdown-press'], $post_type_screens))) {
            return;
        }

        wp_enqueue_script(
            'markdown-press-admin',
            plugins_url('js/admin.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        // Localize the script with data needed for AJAX
        wp_localize_script(
            'markdown-press-admin',
            'markdownPressAdmin',
            [
                'nonce' => wp_create_nonce('markdown_press_export'),
                'i18n' => [
                    'exportFailed' => __('Export failed. Please try again.', 'markdown-press'),
                    'exporting' => __('Exporting...', 'markdown-press'),
                    'download' => __('Download Files', 'markdown-press'),
                    'selectContent' => __('Please select at least one item to export', 'markdown-press'),
                    'selectTaxonomy' => __('Please select both a taxonomy and term', 'markdown-press')
                ]
            ]
        );
    }

    /**
     * Handle AJAX export request
     */
    public function handleExport(): void {
        try {
            if (!check_ajax_referer('markdown_press_export', 'nonce', false)) {
                error_log('[Markdown Press] Nonce verification failed');
                wp_send_json_error([
                    'message' => __('Security check failed. Please refresh the page and try again.', 'markdown-press')
                ]);
                return;
            }

            // Check if user can export the requested posts
            $can_export = false;
            $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) 
                     ? array_map('absint', $_POST['post_ids']) 
                     : [];

            if (!empty($post_ids)) {
                foreach ($post_ids as $post_id) {
                    if ($this->userCanExportPost($post_id)) {
                        $can_export = true;
                        break;
                    }
                }
            } else {
                // For non-specific exports, check if user can edit posts in general
                $can_export = current_user_can('edit_posts');
            }

            if (!$can_export) {
                error_log('[Markdown Press] User lacks required capabilities');
                wp_send_json_error([
                    'message' => __('You do not have permission to export these posts.', 'markdown-press')
                ]);
                return;
            }

            $allowed_types = ['selected', 'taxonomy', 'all'];
            $type = sanitize_text_field($_POST['export_type'] ?? '');
            
            if (!in_array($type, $allowed_types, true)) {
                throw new \Exception(__('Invalid export type', 'markdown-press'));
            }

            $taxonomy = '';
            if (isset($_POST['taxonomy'])) {
                $taxonomy = sanitize_key($_POST['taxonomy']);
                if (!taxonomy_exists($taxonomy)) {
                    throw new \Exception(__('Invalid taxonomy', 'markdown-press'));
                }
            }

            $term_id = absint($_POST['term_id'] ?? 0);
            if ($taxonomy && $term_id && !term_exists($term_id, $taxonomy)) {
                throw new \Exception(__('Invalid term', 'markdown-press'));
            }

            $posts = $this->getPostsToExport($type, $post_ids, $taxonomy, $term_id);
            
            if (empty($posts)) {
                throw new \Exception(__('No posts found to export', 'markdown-press'));
            }

            // Filter out posts the user can't export
            $posts = array_filter($posts, function($post) {
                return $this->userCanExportPost($post->ID);
            });

            if (empty($posts)) {
                throw new \Exception(__('No posts available for export with your permissions', 'markdown-press'));
            }

            $exported = $this->exportPosts($posts);

            if (empty($exported)) {
                throw new \Exception(__('Failed to export posts', 'markdown-press'));
            }

            wp_send_json_success([
                'message' => sprintf(
                    _n(
                        'Successfully exported %d post',
                        'Successfully exported %d posts',
                        count($exported),
                        'markdown-press'
                    ),
                    count($exported)
                ),
                'download_url' => $this->getDownloadUrl($exported)
            ]);

        } catch (\Exception $e) {
            error_log('[Markdown Press] Export error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get posts to export based on criteria
     *
     * @param string $type Export type (selected, taxonomy, all)
     * @param array $ids Post IDs for selected export
     * @param string $taxonomy Taxonomy name for taxonomy export
     * @param int $term_id Term ID for taxonomy export
     * @return array Array of WP_Post objects
     */
    private function getPostsToExport(string $type, array $ids = [], string $taxonomy = '', int $term_id = 0): array {
        try {
            error_log('[Markdown Press] Export request - Type: ' . $type . ', IDs: ' . implode(',', $ids));
            
            // Get all public post types
            $post_types = get_post_types(['public' => true], 'names');
            
            /**
             * Filter the post types that can be exported
             *
             * @param array $post_types Array of post type names
             * @param string $type Export type (selected, taxonomy, all)
             */
            $post_types = apply_filters('markdown_press_exportable_post_types', $post_types, $type);
            
            error_log('[Markdown Press] Available post types: ' . implode(',', $post_types));
            
            // Default post statuses
            $post_statuses = ['publish', 'draft', 'pending', 'private', 'future'];
            
            /**
             * Filter the post statuses that can be exported
             *
             * @param array $post_statuses Array of post status names
             * @param string $type Export type (selected, taxonomy, all)
             */
            $post_statuses = apply_filters('markdown_press_exportable_post_statuses', $post_statuses, $type);
            
            $args = [
                'post_type' => array_values($post_types),
                'posts_per_page' => -1,
                'post_status' => $post_statuses,
                'orderby' => 'title',
                'order' => 'ASC',
                'suppress_filters' => false
            ];

            switch ($type) {
                case 'selected':
                    if (!empty($ids)) {
                        $args['post__in'] = array_map('absint', $ids);
                        // When specific posts are selected, get their post types
                        $selected_types = [];
                        foreach ($ids as $post_id) {
                            $post = get_post($post_id);
                            if ($post instanceof \WP_Post) {
                                $selected_types[] = $post->post_type;
                                error_log(sprintf(
                                    '[Markdown Press] Post %d has type: %s, status: %s',
                                    $post_id,
                                    $post->post_type,
                                    $post->post_status
                                ));
                            }
                        }
                        $args['post_type'] = array_unique($selected_types);
                        // For selected posts, we want to get them regardless of status
                        $args['post_status'] = 'any';
                    }
                    break;
                    
                case 'taxonomy':
                    if ($taxonomy && $term_id) {
                        $args['tax_query'] = [[
                            'taxonomy' => $taxonomy,
                            'field' => 'term_id',
                            'terms' => $term_id,
                        ]];
                    }
                    break;
                    
                case 'all':
                    // Default args are fine
                    break;
            }

            /**
             * Filter the WP_Query arguments for post export
             *
             * @param array $args WP_Query arguments
             * @param string $type Export type (selected, taxonomy, all)
             * @param array $ids Selected post IDs
             * @param string $taxonomy Taxonomy name
             * @param int $term_id Term ID
             */
            $args = apply_filters('markdown_press_export_query_args', $args, $type, $ids, $taxonomy, $term_id);

            error_log('[Markdown Press] Query args: ' . print_r($args, true));

            $query = new \WP_Query($args);
            $posts = $query->posts;

            error_log('[Markdown Press] Found ' . count($posts) . ' posts');
            
            if (empty($posts)) {
                error_log('[Markdown Press] SQL Query: ' . $query->request);
            }

            return $posts;

        } catch (\Exception $e) {
            error_log('[Markdown Press] Error in getPostsToExport: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if a user can export a specific post
     *
     * @param int $post_id Post ID to check
     * @return bool Whether the user can export the post
     */
    private function userCanExportPost(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        // Allow super admins and administrators to export any post
        if (is_super_admin() || current_user_can('manage_options')) {
            return true;
        }

        // For other users, check specific capabilities
        $post_type_obj = get_post_type_object($post->post_type);
        if (!$post_type_obj) {
            return false;
        }

        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            return false;
        }

        // For private posts, ensure user can read private posts
        if ($post->post_status === 'private' && !current_user_can($post_type_obj->cap->read_private_posts)) {
            return false;
        }

        /**
         * Filter whether a user can export a specific post
         *
         * @param bool $can_export Whether the user can export the post
         * @param int $post_id Post ID
         * @param \WP_Post $post Post object
         */
        return apply_filters('markdown_press_user_can_export_post', true, $post_id, $post);
    }

    /**
     * Export posts to Markdown files
     *
     * @param array $posts Array of posts to export
     * @return array Array of exported file paths
     */
    private function exportPosts(array $posts): array {
        $exported = [];
        $export_dir = $this->getExportDir();

        error_log('[Markdown Press] Starting export to directory: ' . $export_dir);

        // Create export directory if it doesn't exist
        if (!wp_mkdir_p($export_dir)) {
            error_log('[Markdown Press] Failed to create export directory: ' . $export_dir);
            return [];
        }

        foreach ($posts as $post) {
            try {
                if (is_numeric($post)) {
                    $post = get_post($post);
                }
                
                if (!$post instanceof \WP_Post) {
                    error_log('[Markdown Press] Invalid post object: ' . print_r($post, true));
                    continue;
                }
                
                error_log('[Markdown Press] Processing post: ' . $post->ID . ' - ' . $post->post_title);
                
                $markdown = $this->convertToMarkdown($post);
                $filename = sanitize_file_name($post->post_name . '.md');
                
                // Add post type prefix for better organization
                $post_type_obj = get_post_type_object($post->post_type);
                $prefix = $post_type_obj ? sanitize_file_name($post_type_obj->labels->singular_name) . '-' : '';
                $path = $export_dir . '/' . $prefix . $filename;

                error_log('[Markdown Press] Writing to file: ' . $path);
                
                $bytes = file_put_contents($path, $markdown);
                if ($bytes === false) {
                    throw new \Exception(sprintf(
                        __('Failed to write file: %s', 'markdown-press'),
                        $path
                    ));
                }
                
                error_log('[Markdown Press] Successfully wrote ' . $bytes . ' bytes to file');
                $exported[] = $path;

            } catch (\Exception $e) {
                error_log('[Markdown Press] Export error for post ' . ($post->ID ?? 'unknown') . ': ' . $e->getMessage());
                continue;
            }
        }

        error_log('[Markdown Press] Export completed. Total files: ' . count($exported));
        return $exported;
    }

    /**
     * Convert a post to Markdown
     *
     * @param \WP_Post $post Post object to convert
     * @return string Markdown content
     */
    private function convertToMarkdown(\WP_Post $post): string {
        try {
            error_log('[Markdown Press] Converting post to Markdown: ' . $post->ID);
            
            $content = "---\n";
            $content .= "title: " . esc_yaml($post->post_title) . "\n";
            $content .= "date: " . esc_yaml($post->post_date) . "\n";
            $content .= "author: " . esc_yaml(get_the_author_meta('display_name', $post->post_author)) . "\n";
            $content .= "post_type: " . esc_yaml($post->post_type) . "\n";
            $content .= "status: " . esc_yaml($post->post_status) . "\n";

            // Get post type object for additional metadata
            $post_type_obj = get_post_type_object($post->post_type);
            if ($post_type_obj) {
                $content .= "post_type_label: " . esc_yaml($post_type_obj->labels->singular_name) . "\n";
            }

            // Add taxonomies
            $taxonomies = get_object_taxonomies($post->post_type);
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $content .= $taxonomy . ":\n";
                    foreach ($terms as $term) {
                        $content .= "  - " . esc_yaml($term) . "\n";
                    }
                }
            }

            // Add custom fields
            $custom_fields = get_post_custom($post->ID);
            if (!empty($custom_fields)) {
                $content .= "custom_fields:\n";
                foreach ($custom_fields as $key => $values) {
                    if (substr($key, 0, 1) !== '_') { // Skip hidden fields
                        $safe_key = esc_yaml($key);
                        $safe_value = esc_yaml($values[0]);
                        $content .= "  " . $safe_key . ": " . $safe_value . "\n";
                    }
                }
            }

            $content .= "---\n\n";
            
            // Convert post content to Markdown
            $markdown_content = $post->post_content;
            
            // Convert HTML to Markdown
            // Replace <br> tags with newlines
            $markdown_content = str_replace(['<br>', '<br/>', '<br />'], "\n", $markdown_content);
            
            // Convert headers
            $markdown_content = preg_replace_callback('/<h([1-6])>(.*?)<\/h[1-6]>/i', 
                function($matches) {
                    return str_repeat('#', (int)$matches[1]) . ' ' . wp_strip_all_tags($matches[2]) . "\n\n";
                }, 
                $markdown_content
            );
            
            // Convert paragraphs
            $markdown_content = preg_replace_callback('/<p>(.*?)<\/p>/i',
                function($matches) {
                    return wp_strip_all_tags($matches[1]) . "\n\n";
                },
                $markdown_content
            );
            
            // Convert links
            $markdown_content = preg_replace_callback('/<a\s+href=["\'](.*?)["\'].*?>(.*?)<\/a>/i',
                function($matches) {
                    return '[' . wp_strip_all_tags($matches[2]) . '](' . esc_url($matches[1]) . ')';
                },
                $markdown_content
            );
            
            // Convert lists
            $markdown_content = preg_replace_callback('/<li>(.*?)<\/li>/i',
                function($matches) {
                    return "- " . wp_strip_all_tags($matches[1]) . "\n";
                },
                $markdown_content
            );
            
            // Strip remaining HTML tags
            $markdown_content = wp_strip_all_tags($markdown_content);
            
            // Clean up multiple newlines
            $markdown_content = preg_replace('/\n{3,}/', "\n\n", $markdown_content);
            
            $content .= trim($markdown_content) . "\n";

            error_log('[Markdown Press] Successfully converted post to Markdown');
            return $content;

        } catch (\Exception $e) {
            error_log('[Markdown Press] Error converting post to Markdown: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get export directory path
     *
     * @return string Export directory path
     */
    private function getExportDir(): string {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/markdown-exports';

        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        return $export_dir;
    }

    /**
     * Get download URL for exported files
     *
     * @param array $files Array of exported file paths
     * @return string Download URL
     */
    private function getDownloadUrl(array $files): string {
        // Clean up old export files
        $this->cleanupOldExports();

        if (empty($files)) {
            return '';
        }

        if (count($files) === 1) {
            return str_replace(
                wp_upload_dir()['basedir'],
                wp_upload_dir()['baseurl'],
                $files[0]
            );
        }

        // Create ZIP file for multiple files
        $zip = new \ZipArchive();
        $timestamp = current_time('timestamp');
        $zip_file = $this->getExportDir() . '/markdown-export-' . date('Y-m-d-H-i-s', $timestamp) . '.zip';

        if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $zip->addFile($file, basename($file));
                }
            }
            $zip->close();

            // Delete individual markdown files after zipping
            foreach ($files as $file) {
                wp_delete_file($file);
            }

            return str_replace(
                wp_upload_dir()['basedir'],
                wp_upload_dir()['baseurl'],
                $zip_file
            );
        }

        return '';
    }

    /**
     * Clean up old export files
     */
    private function cleanupOldExports(): void {
        $export_dir = $this->getExportDir();
        $files = glob($export_dir . '/*');
        $now = current_time('timestamp');
        
        foreach ($files as $file) {
            // Delete files older than 1 hour
            if (filemtime($file) < ($now - HOUR_IN_SECONDS)) {
                wp_delete_file($file);
            }
        }
    }

    /**
     * Add bulk action to post and page edit screens
     *
     * @param array $bulk_actions Bulk actions array
     * @return array Updated bulk actions array
     */
    public function addBulkAction(array $bulk_actions): array {
        if (current_user_can('manage_options')) {
            $bulk_actions['export_to_markdown'] = __('Export to Markdown', 'markdown-press');
        }
        return $bulk_actions;
    }

    /**
     * Handle bulk action for post and page edit screens
     *
     * @param string $redirect_to Redirect URL
     * @param string $doaction Action to perform
     * @param array $post_ids Post IDs to export
     * @return string Updated redirect URL
     */
    public function handleBulkAction(string $redirect_to, string $doaction, array $post_ids): string {
        if ($doaction !== 'export_to_markdown' || !current_user_can('manage_options')) {
            return $redirect_to;
        }

        $posts = $this->getPostsToExport('selected', $post_ids);
        if (empty($posts)) {
            return add_query_arg('markdown_exported', '0', $redirect_to);
        }

        $exported = $this->exportPosts($posts);
        $download_url = $this->getDownloadUrl($exported);
        
        return add_query_arg(
            [
                'markdown_exported' => count($exported),
                'download_url' => $download_url
            ],
            $redirect_to
        );
    }

    /**
     * Add row action to post and page edit screens
     *
     * @param array $actions Row actions array
     * @param \WP_Post $post Post object
     * @return array Updated row actions array
     */
    public function addRowAction(array $actions, \WP_Post $post): array {
        // Show export action only if user can export this post
        if ($this->userCanExportPost($post->ID)) {
            $actions['export_markdown'] = sprintf(
                '<a href="#" class="markdown-press-export" data-post-id="%d">%s</a>',
                $post->ID,
                __('Export as Markdown', 'markdown-press')
            );
        }
        return $actions;
    }

    /**
     * Show export notice on admin screens
     */
    public function showExportNotice(): void {
        if (!empty($_GET['markdown_exported'])) {
            $count = intval($_GET['markdown_exported']);
            $message = sprintf(
                _n(
                    '%s post exported to Markdown successfully.',
                    '%s posts exported to Markdown successfully.',
                    $count,
                    'markdown-press'
                ),
                number_format_i18n($count)
            );
            
            if (!empty($_GET['download_url'])) {
                $message .= sprintf(
                    ' <a href="%s" class="button button-secondary">%s</a>',
                    esc_url($_GET['download_url']),
                    __('Download Files', 'markdown-press')
                );
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        }
    }

    /**
     * Render admin page
     */
    public function renderAdminPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'markdown-press'));
        }

        include plugin_dir_path(__FILE__) . 'templates/admin-page.php';
    }
}

// Initialize the plugin
MarkdownPress::getInstance();

// Helper function for YAML escaping
function esc_yaml($string) {
    return str_replace(
        ['\\', '"', "\n", "\r", "\t"],
        ['\\\\', '\\"', '\\n', '\\r', '\\t'],
        $string
    );
}
