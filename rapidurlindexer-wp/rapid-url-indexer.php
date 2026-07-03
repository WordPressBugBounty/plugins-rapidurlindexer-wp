<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    die;
}

/**
 * Plugin Name: Rapid URL Indexer for WP
 * Description: Submit URLs to Rapid URL Indexer for fast and reliable Google indexing. Uses the Rapid URL Indexer API service.
 * Version: 1.1.9
 * Requires at least: 4.7
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: Rapid URL Indexer
 * Author URI: https://rapidurlindexer.com/
 * License: GPLv3
 * Text Domain: rapidurlindexer-wp
 * Domain Path: /languages
 * Uninstall: uninstall.php
 *
 * This plugin uses the Rapid URL Indexer API service (https://rapidurlindexer.com/) to submit and index URLs.
 * By using this plugin, you agree to the Terms of Service (https://rapidurlindexer.com/terms-of-service/)
 * and Privacy Policy (https://rapidurlindexer.com/privacy-policy/) of Rapid URL Indexer.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RUI_PLUGIN_VERSION')) {
    define('RUI_PLUGIN_VERSION', '1.1.9');
}

if (!class_exists('RUI_WordPress_Plugin')) {
    class RUI_WordPress_Plugin {
        private const STANDARD_CREDITS_PER_URL = 1;
        private const APEX_CREDITS_PER_URL = 3;
        private const SUBMISSION_WINDOW_SECONDS = 86400;
        private const SUBMISSION_LOCK_TTL = 300;
        private $auto_submitted_posts = array();
        private $publish_transition_states = array();
        private $rest_auto_submit_hooks_registered = false;
        private $registered_rest_auto_submit_post_types = array();

        public function __construct() {
            add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));

            // Automatic submissions and their per-post settings must run for every
            // WordPress execution context, including REST, XML-RPC, cron, and CLI.
            add_action('rest_api_init', array($this, 'register_rest_auto_submit_hooks'), 100);
            add_action('registered_post_type', array($this, 'register_rest_auto_submit_hook_for_post_type'), 10, 2);
            add_action('transition_post_status', array($this, 'on_post_status_change'), 10, 3);
            add_action('save_post', array($this, 'save_post_meta'), 10, 2);
            add_action('rui_process_submission_queue', array($this, 'process_submission_queue'));
            register_activation_hook(__FILE__, array($this, 'create_rapidurlindexer_logs_table'));
            $this->ensure_submission_queue_event();

            if (is_admin()) {
                add_action('admin_menu', array($this, 'add_plugin_page'));
                add_action('admin_init', array($this, 'page_init'), 1);
                add_action('admin_init', array($this, 'register_taxonomy_meta_hooks'));
                add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
                add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
                add_action('wp_ajax_rapidurlindexer_bulk_submit', array($this, 'rapidurlindexer_handle_bulk_submit'));
                add_action('wp_ajax_rui_clear_logs', array($this, 'clear_logs'));
                add_action('wp_ajax_rui_refresh_credits', array($this, 'handle_refresh_credits'));
                $this->prune_old_logs();
            }
        }

        public function load_plugin_textdomain() {
            load_plugin_textdomain('rapidurlindexer-wp', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        }

        private function get_api_base_url() {
            return apply_filters('rui_api_base_url', 'https://rapidurlindexer.com/wp-json/api/v1/');
        }

        private function prune_old_logs() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rui_logs';

            $settings = get_option('rui_settings', array());
            $max_logs = isset($settings['max_logs']) ? absint($settings['max_logs']) : 100;
            $count = wp_cache_get('rui_logs_count');
            if (false === $count) {
                $count = intval(get_option('rui_logs_count', 0));
                wp_cache_set('rui_logs_count', $count, '', 300); // Cache for 5 minutes
            }
        if ($count > $max_logs + 10) {
            $this->delete_old_logs($count - $max_logs - 10);
            wp_cache_delete('rui_logs_count');
        }
    }

    private function is_apex_mode_enabled() {
        $settings = get_option('rui_settings', array());
        return !empty($settings['apex_mode_enabled']);
    }

    private function get_required_credits_for_url_count($url_count, $apex_mode_enabled) {
        $url_count = max(0, absint($url_count));
        $credits_per_url = $apex_mode_enabled ? self::APEX_CREDITS_PER_URL : self::STANDARD_CREDITS_PER_URL;
        return $credits_per_url * $url_count;
    }

    private function get_current_timestamp() {
        return (int) apply_filters('rui_current_timestamp', current_time('timestamp', true));
    }

    private function get_max_submissions_per_24h() {
        $settings = get_option('rui_settings', array());
        return isset($settings['max_submissions_per_24h']) ? absint($settings['max_submissions_per_24h']) : 0;
    }

    private function is_submission_limit_enabled() {
        return $this->get_max_submissions_per_24h() > 0;
    }

    private function is_auto_submit_post_type($post_type) {
        $post_type_object = is_object($post_type) ? $post_type : get_post_type_object($post_type);
        if (!$post_type_object || empty($post_type_object->name)) {
            return false;
        }

        $excluded_post_types = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
        );

        if (in_array($post_type_object->name, $excluded_post_types, true)) {
            return false;
        }

        return !empty($post_type_object->public) || !empty($post_type_object->publicly_queryable);
    }

    private function get_auto_submit_post_types($output = 'names') {
        $post_types = get_post_types(array(), 'objects');
        $eligible_post_types = array();

        foreach ($post_types as $post_type) {
            if (!$this->is_auto_submit_post_type($post_type)) {
                continue;
            }

            $eligible_post_types[$post_type->name] = $post_type;
        }

        if ('objects' === $output) {
            return $eligible_post_types;
        }

        return array_keys($eligible_post_types);
    }

    public function register_rest_auto_submit_hooks() {
        if ($this->rest_auto_submit_hooks_registered) {
            return;
        }

        $this->rest_auto_submit_hooks_registered = true;
        foreach ($this->get_auto_submit_post_types('names') as $post_type) {
            $this->register_rest_auto_submit_hook_for_post_type($post_type);
        }
    }

    public function register_rest_auto_submit_hook_for_post_type($post_type, $post_type_object = null) {
        $post_type_object = $post_type_object ? $post_type_object : get_post_type_object($post_type);
        if (!$this->is_auto_submit_post_type($post_type_object)) {
            return;
        }

        if (isset($this->registered_rest_auto_submit_post_types[$post_type])) {
            return;
        }

        $this->registered_rest_auto_submit_post_types[$post_type] = true;
        add_action('rest_after_insert_' . $post_type, array($this, 'on_rest_after_insert_post'), 10, 3);
    }

    private function get_submission_history($now = null) {
        $now = null === $now ? $this->get_current_timestamp() : (int) $now;
        $history = get_option('rui_submission_history', array());
        if (!is_array($history)) {
            $history = array();
        }

        $cutoff = $now - self::SUBMISSION_WINDOW_SECONDS;
        $pruned = array();
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $submitted_at = isset($entry['submitted_at']) ? (int) $entry['submitted_at'] : 0;
            $count = isset($entry['count']) ? absint($entry['count']) : 0;
            if ($count > 0 && $submitted_at > $cutoff) {
                $pruned[] = array(
                    'submitted_at' => $submitted_at,
                    'count' => $count,
                );
            }
        }

        if ($pruned !== $history) {
            update_option('rui_submission_history', $pruned, false);
        }

        return $pruned;
    }

    private function get_submission_count_in_window($now = null) {
        $count = 0;
        foreach ($this->get_submission_history($now) as $entry) {
            $count += isset($entry['count']) ? absint($entry['count']) : 0;
        }
        return $count;
    }

    private function get_remaining_submission_capacity($now = null) {
        $max_submissions = $this->get_max_submissions_per_24h();
        if ($max_submissions <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $max_submissions - $this->get_submission_count_in_window($now));
    }

    private function record_submission_history($count, $now = null) {
        $count = absint($count);
        if ($count <= 0 || !$this->is_submission_limit_enabled()) {
            return;
        }

        $now = null === $now ? $this->get_current_timestamp() : (int) $now;
        $history = $this->get_submission_history($now);
        $history[] = array(
            'submitted_at' => $now,
            'count' => $count,
        );
        update_option('rui_submission_history', $history, false);
    }

    private function get_submission_queue() {
        $queue = get_option('rui_submission_queue', array());
        return is_array($queue) ? array_values($queue) : array();
    }

    private function update_submission_queue($queue) {
        update_option('rui_submission_queue', array_values($queue), false);
    }

    private function build_queue_item($url, $project_name, $apex_mode_enabled, $action_type) {
        return array(
            'url' => esc_url_raw($url),
            'project_name' => sanitize_text_field($project_name),
            'apex_mode_enabled' => (bool) $apex_mode_enabled,
            'action_type' => sanitize_key($action_type),
            'queued_at' => $this->get_current_timestamp(),
        );
    }

    private function add_submission_queue_items($items) {
        if (empty($items)) {
            return 0;
        }

        $queue = $this->get_submission_queue();
        foreach ($items as $item) {
            if (!empty($item['url'])) {
                $queue[] = $item;
            }
        }
        $this->update_submission_queue($queue);
        return count($items);
    }

    private function acquire_submission_lock() {
        $now = $this->get_current_timestamp();
        $lock = get_option('rui_submission_queue_lock', 0);
        if ($lock && ($now - (int) $lock) > self::SUBMISSION_LOCK_TTL) {
            delete_option('rui_submission_queue_lock');
            $lock = 0;
        }

        if ($lock) {
            return false;
        }

        return add_option('rui_submission_queue_lock', $now, '', false);
    }

    private function release_submission_lock() {
        delete_option('rui_submission_queue_lock');
    }

    private function ensure_submission_queue_event() {
        if (!wp_next_scheduled('rui_process_submission_queue')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'rui_process_submission_queue');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('rui_process_submission_queue');
    }

    private function is_successful_submission_response($response) {
        return isset($response['project_id']) || (isset($response['code']) && in_array((int) $response['code'], array(200, 201), true)) || (isset($response['message']) && 'Project created and submitted' === $response['message']);
    }

    private function submit_single_url_now($full_url, $project_name, $apex_mode_enabled, $action_type) {
        $credits_info = $this->get_credits_balance();
        $required_credits = $this->get_required_credits_for_url_count(1, $apex_mode_enabled);

        if (isset($credits_info['error'])) {
            $this->log_api_error($credits_info['error']);
            return false;
        }

        if (!isset($credits_info['credits']) || $credits_info['credits'] < $required_credits) {
            $admin_email = get_option('admin_email');
            $subject = __('Out of Rapid URL Indexer Credits', 'rapidurlindexer-wp');
            /* translators: %s: URL to buy more credits */
            $message = sprintf(
                esc_html__('You do not have enough Rapid URL Indexer credits. Please visit %s to buy more.', 'rapidurlindexer-wp'),
                'https://rapidurlindexer.com/my-account/rui-buy-credits/'
            );
            wp_mail($admin_email, $subject, $message);
            return false;
        }

        $result = $this->submit_url($full_url, $project_name, $apex_mode_enabled);

        if ($this->is_successful_submission_response($result)) {
            $this->record_submission_history(1);
            $this->log_submission($full_url, $action_type);
            return true;
        }

        $this->log_submission($full_url, 'error');
        return false;
    }

    private function queue_url_submission($url, $project_name, $apex_mode_enabled, $action_type) {
        $queued = $this->add_submission_queue_items(array(
            $this->build_queue_item($url, $project_name, $apex_mode_enabled, $action_type),
        ));
        if ($queued) {
            $this->log_submission($url, 'queued');
        }
        return $queued;
    }

    private function queue_bulk_url_submissions($urls, $project_name, $apex_mode_enabled) {
        $items = array();
        foreach ($urls as $url) {
            $items[] = $this->build_queue_item($url, $project_name, $apex_mode_enabled, 'bulk');
        }

        $queued = $this->add_submission_queue_items($items);
        foreach ($items as $item) {
            if (!empty($item['url'])) {
                $this->log_submission($item['url'], 'queued');
            }
        }

        return $queued;
    }

    public function clear_logs() {
        check_ajax_referer('rui_clear_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Insufficient permissions', 'rapidurlindexer-wp'));
        }

        $this->truncate_logs_table();

        wp_cache_delete('rui_logs_count');
        wp_cache_delete('rui_logs');

        wp_send_json_success(esc_html__('Logs cleared successfully', 'rapidurlindexer-wp'));
    }

    private function log_submission($url, $action_type) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rui_logs';

        $result = $wpdb->insert(
            $table_name,
            array(
                'url' => $url,
                'date_time' => current_time('mysql'),
                'action_type' => $action_type,
            ),
            array('%s', '%s', '%s')
        );

        if ($result === false) {
            error_log("Failed to insert log entry: " . $wpdb->last_error);
        } else {
            wp_cache_delete('rui_logs_count');
            wp_cache_delete('rui_logs');
            $this->update_logs_count();
            $this->prune_old_logs();
        }
        error_log("Log submission: URL - $url, Action - $action_type, Result - " . ($result ? 'Success' : 'Failure'));
    }

    private function update_logs_count() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rui_logs");
        update_option('rui_logs_count', $count);
    }

    public function create_rapidurlindexer_logs_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rui_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            date_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            action_type varchar(10) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function register_taxonomy_meta_hooks() {
        foreach ($this->get_admin_auto_submit_taxonomies() as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', array($this, 'add_category_meta_fields'));
            add_action($taxonomy . '_edit_form_fields', array($this, 'edit_category_meta_fields'));
            add_action('created_' . $taxonomy, array($this, 'save_category_meta'), 10, 2);
            add_action('edited_' . $taxonomy, array($this, 'save_category_meta'), 10, 2);
        }
    }

    private function get_admin_auto_submit_taxonomies() {
        $taxonomies = get_taxonomies(array(), 'objects');
        $eligible_taxonomies = array();

        foreach ($taxonomies as $taxonomy) {
            if (empty($taxonomy->name) || empty($taxonomy->object_type)) {
                continue;
            }

            if (empty($taxonomy->show_ui) && empty($taxonomy->public)) {
                continue;
            }

            if ($this->taxonomy_has_auto_submit_post_type($taxonomy->object_type)) {
                $eligible_taxonomies[] = $taxonomy->name;
            }
        }

        return $eligible_taxonomies;
    }

    private function taxonomy_has_auto_submit_post_type($object_types) {
        foreach ((array) $object_types as $post_type) {
            $post_type_object = get_post_type_object($post_type);
            if ($post_type_object && (!empty($post_type_object->public) || !empty($post_type_object->publicly_queryable))) {
                return true;
            }
        }

        return false;
    }

    private function get_taxonomy_from_current_term_hook() {
        $hook = current_filter();
        foreach (array('created_', 'edited_') as $prefix) {
            if (0 === strpos($hook, $prefix)) {
                return sanitize_key(substr($hook, strlen($prefix)));
            }
        }

        return '';
    }

    public function add_category_meta_fields($taxonomy) {
        ?>
        <?php wp_nonce_field('rui_save_category_meta', 'rui_category_nonce'); ?>
        <div class="form-field">
            <label for="rui_submit_on_publish"><?php esc_html_e('Submit on Publish', 'rapidurlindexer-wp'); ?></label>
            <input type="checkbox" name="rui_submit_on_publish" id="rui_submit_on_publish" value="1">
        </div>
        <div class="form-field">
            <label for="rui_submit_on_update"><?php esc_html_e('Submit on Update', 'rapidurlindexer-wp'); ?></label>
            <input type="checkbox" name="rui_submit_on_update" id="rui_submit_on_update" value="1">
        </div>
        <?php
    }

    public function edit_category_meta_fields($term) {
        $submit_on_publish = get_term_meta($term->term_id, '_rui_submit_on_publish', true);
        $submit_on_update = get_term_meta($term->term_id, '_rui_submit_on_update', true);
        wp_nonce_field('rui_save_category_meta', 'rui_category_nonce');
        ?>
        <tr class="form-field">
            <th scope="row" valign="top"><label for="rui_submit_on_publish"><?php esc_html_e('Submit on Publish', 'rapidurlindexer-wp'); ?></label></th>
            <td>
                <input type="checkbox" name="rui_submit_on_publish" id="rui_submit_on_publish" value="1" <?php checked($submit_on_publish, 1); ?>>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row" valign="top"><label for="rui_submit_on_update"><?php esc_html_e('Submit on Update', 'rapidurlindexer-wp'); ?></label></th>
            <td>
                <input type="checkbox" name="rui_submit_on_update" id="rui_submit_on_update" value="1" <?php checked($submit_on_update, 1); ?>>
            </td>
        </tr>
        <?php
    }

    public function save_category_meta($term_id) {
        if (!isset($_POST['rui_category_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rui_category_nonce'])), 'rui_save_category_meta')) {
            wp_die(esc_html__('Security check failed', 'rapidurlindexer-wp'));
            return;
        }

        $taxonomy = $this->get_taxonomy_from_current_term_hook();
        $taxonomy_object = $taxonomy ? get_taxonomy($taxonomy) : null;
        $manage_terms_capability = ($taxonomy_object && !empty($taxonomy_object->cap->manage_terms)) ? $taxonomy_object->cap->manage_terms : 'manage_categories';

        if (!current_user_can($manage_terms_capability)) {
            wp_die(esc_html__('Insufficient permissions', 'rapidurlindexer-wp'));
            return;
        }

        $submit_on_publish = isset($_POST['rui_submit_on_publish']) ? 1 : 0;
        $submit_on_update = isset($_POST['rui_submit_on_update']) ? 1 : 0;

        update_term_meta($term_id, '_rui_submit_on_publish', $submit_on_publish);
        update_term_meta($term_id, '_rui_submit_on_update', $submit_on_update);
    }

    private function get_assigned_term_auto_submit_settings($post_id, $post_type) {
        $term_settings = array(
            'submit_on_publish' => false,
            'submit_on_update' => false,
        );

        $taxonomies = get_object_taxonomies($post_type, 'names');
        if (empty($taxonomies)) {
            return $term_settings;
        }

        $term_ids = wp_get_object_terms($post_id, $taxonomies, array('fields' => 'ids'));
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return $term_settings;
        }

        foreach ($term_ids as $term_id) {
            if (get_term_meta($term_id, '_rui_submit_on_publish', true)) {
                $term_settings['submit_on_publish'] = true;
            }

            if (get_term_meta($term_id, '_rui_submit_on_update', true)) {
                $term_settings['submit_on_update'] = true;
            }

            if ($term_settings['submit_on_publish'] && $term_settings['submit_on_update']) {
                break;
            }
        }

        return $term_settings;
    }

    public function on_rest_after_insert_post($post, $request, $creating) {
        if (!$post instanceof WP_Post || !$request instanceof WP_REST_Request) {
            return;
        }

        if ('publish' !== $post->post_status) {
            return;
        }

        if (isset($this->publish_transition_states[$post->ID])) {
            $is_new_post = (bool) $this->publish_transition_states[$post->ID];
        } else {
            $is_new_post = $creating || 'publish' === $request->get_param('status');
        }

        $this->maybe_auto_submit_post($post, $is_new_post);
    }

    // Save per-post automatic submission settings from the post editor meta box.

    public function save_post_meta($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if (!isset($_POST['rui_post_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rui_post_settings_nonce'])), 'rui_post_settings')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_rui_submit_on_publish', isset($_POST['rui_submit_on_publish']) ? 1 : 0);
        update_post_meta($post_id, '_rui_submit_on_update', isset($_POST['rui_submit_on_update']) ? 1 : 0);
    }
    
    public function on_post_status_change($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post) {
            return;
        }

        if ('publish' !== $new_status) {
            return;
        }

        $is_new_post = 'publish' !== $old_status;
        $this->publish_transition_states[$post->ID] = $is_new_post;
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $this->maybe_auto_submit_post($post, $is_new_post);
    }

    private function maybe_auto_submit_post($post, $is_new_post) {
        if (!$post instanceof WP_Post) {
            return;
        }

        if ('publish' !== $post->post_status || post_password_required($post)) {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        $submission_guard_key = $post->ID . ':' . ($is_new_post ? 'publish' : 'update');
        if (isset($this->auto_submitted_posts[$submission_guard_key])) {
            return;
        }

        $settings = get_option('rui_settings', array());
        $submit_on_publish = !empty($settings["submit_on_publish_{$post->post_type}"]);
        $submit_on_update = !empty($settings["submit_on_update_{$post->post_type}"]);
        $always_submit = !empty($settings['always_submit_on_publish']);

        $post_submit_on_publish = '1' === get_post_meta($post->ID, '_rui_submit_on_publish', true);
        $post_submit_on_update = '1' === get_post_meta($post->ID, '_rui_submit_on_update', true);

        $term_auto_submit_settings = $this->get_assigned_term_auto_submit_settings($post->ID, $post->post_type);
        $category_submit_on_publish = $term_auto_submit_settings['submit_on_publish'];
        $category_submit_on_update = $term_auto_submit_settings['submit_on_update'];

        if ($is_new_post) {
            $should_submit = $always_submit || $submit_on_publish || $category_submit_on_publish || $post_submit_on_publish;
        } else {
            $should_submit = $submit_on_update || $category_submit_on_update || $post_submit_on_update;
        }

        if (!$should_submit) {
            return;
        }

        $full_url = get_permalink($post->ID);
        if (!$full_url) {
            return;
        }

        $this->auto_submitted_posts[$submission_guard_key] = true;
        $domain = preg_replace('#^https?://#', '', get_site_url());
        $project_name = $domain . '-' . $post->post_name;
        $apex_mode_enabled = $this->is_apex_mode_enabled();
        $action_type = $is_new_post ? 'publish' : 'update';

        if (!$this->is_submission_limit_enabled()) {
            $this->submit_single_url_now($full_url, $project_name, $apex_mode_enabled, $action_type);
            return;
        }

        if (!$this->acquire_submission_lock()) {
            $this->queue_url_submission($full_url, $project_name, $apex_mode_enabled, $action_type);
            return;
        }

        $process_queue_after_release = false;
        try {
            if (!empty($this->get_submission_queue())) {
                $this->queue_url_submission($full_url, $project_name, $apex_mode_enabled, $action_type);
                $process_queue_after_release = true;
            } elseif ($this->get_remaining_submission_capacity() <= 0) {
                $this->queue_url_submission($full_url, $project_name, $apex_mode_enabled, $action_type);
            } else {
                $this->submit_single_url_now($full_url, $project_name, $apex_mode_enabled, $action_type);
            }
        } finally {
            $this->release_submission_lock();
        }

        if ($process_queue_after_release) {
            $this->process_submission_queue();
        }
    }

    public function process_submission_queue() {
        if (!$this->acquire_submission_lock()) {
            return 0;
        }

        $processed = 0;
        try {
            $queue = $this->get_submission_queue();
            if (empty($queue)) {
                return 0;
            }

            $capacity = $this->get_remaining_submission_capacity();
            if ($capacity <= 0) {
                return 0;
            }

            $remaining_queue = array();
            foreach ($queue as $index => $entry) {
                if ($processed >= $capacity) {
                    $remaining_queue = array_merge($remaining_queue, array_slice($queue, $index));
                    break;
                }

                $url = isset($entry['url']) ? esc_url_raw($entry['url']) : '';
                if ('' === $url) {
                    continue;
                }

                $project_name = isset($entry['project_name']) ? sanitize_text_field($entry['project_name']) : __('Queued URL', 'rapidurlindexer-wp');
                $apex_mode_enabled = !empty($entry['apex_mode_enabled']);
                $action_type = isset($entry['action_type']) ? sanitize_key($entry['action_type']) : 'queued';
                $required_credits = $this->get_required_credits_for_url_count(1, $apex_mode_enabled);
                $credits_info = $this->get_credits_balance();

                if (isset($credits_info['error'])) {
                    $this->log_api_error($credits_info['error']);
                    $remaining_queue = array_merge($remaining_queue, array_slice($queue, $index));
                    break;
                }

                if (!isset($credits_info['credits']) || $credits_info['credits'] < $required_credits) {
                    $remaining_queue = array_merge($remaining_queue, array_slice($queue, $index));
                    break;
                }

                $result = $this->submit_url($url, $project_name, $apex_mode_enabled);
                if ($this->is_successful_submission_response($result)) {
                    $this->record_submission_history(1);
                    $this->log_submission($url, $action_type);
                    $processed++;
                    continue;
                }

                $this->log_submission($url, 'error');
                $remaining_queue = array_merge($remaining_queue, array_slice($queue, $index));
                break;
            }

            $this->update_submission_queue($remaining_queue);
            return $processed;
        } finally {
            $this->release_submission_lock();
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'rui_post_settings',
            __('Rapid URL Indexer Settings', 'rapidurlindexer-wp'),
            array($this, 'render_post_settings_meta_box'),
            null,
            'side',
            'default'
        );
    }

    public function render_post_settings_meta_box($post) {
        $submit_on_publish = get_post_meta($post->ID, '_rui_submit_on_publish', true);
        $submit_on_update = get_post_meta($post->ID, '_rui_submit_on_update', true);
        $term_auto_submit_settings = $this->get_assigned_term_auto_submit_settings($post->ID, $post->post_type);
        $category_submit_on_publish = $term_auto_submit_settings['submit_on_publish'];
        $category_submit_on_update = $term_auto_submit_settings['submit_on_update'];

        include 'templates/post-settings.php';
    }

    public function add_plugin_page() {
        add_options_page(
            'Rapid URL Indexer Settings',
            'Rapid URL Indexer',
            'manage_options',
            'rapidurlindexer-wp',
            array($this, 'create_admin_page')
        );
    }

    public function create_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rapidurlindexer-wp'));
        }

        $apex_mode_enabled = $this->is_apex_mode_enabled();
        $logs = $this->get_logs_from_db(100);
        $max_submissions_per_24h = $this->get_max_submissions_per_24h();
        $submission_queue = $this->get_submission_queue();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors(); ?>

            <div class="notice notice-warning">
                <p>
                    <?php
                    /* translators: %1$s: URL to Terms of Service, %2$s: URL to Privacy Policy */
                    echo wp_kses(
                        sprintf(
                            __('This plugin uses the Rapid URL Indexer API service to submit and index your URLs. By using this plugin, you agree to send your website\'s URLs to this third-party service. Please review the <a href="%1$s" target="_blank">Terms of Service</a> and <a href="%2$s" target="_blank">Privacy Policy</a> before using this plugin.', 'rapidurlindexer-wp'),
                            'https://rapidurlindexer.com/terms-of-service/',
                            'https://rapidurlindexer.com/privacy-policy/'
                        ),
                        array(
                            'a' => array(
                                'href' => array(),
                                'target' => array()
                            )
                        )
                    );
                    ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('rui_settings');
                do_settings_sections('rapidurlindexer-wp');
                submit_button();
                ?>
            </form>
            
            <h2><?php esc_html_e('Bulk Submit URLs', 'rapidurlindexer-wp'); ?></h2>
            <form id="rapidurlindexer-bulk-submit-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rui-project-name"><?php esc_html_e('Project Name', 'rapidurlindexer-wp'); ?></label></th>
                        <td><input type="text" id="rui-project-name" name="project_name" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rui-apex-mode-enabled"><?php esc_html_e('Apex Mode', 'rapidurlindexer-wp'); ?></label></th>
                        <td>
                            <label>
                                <input type="hidden" name="apex_mode_enabled" value="0" />
                                <input type="checkbox" id="rui-apex-mode-enabled" name="apex_mode_enabled" value="1" <?php checked($apex_mode_enabled, true); ?> />
                                <?php esc_html_e('Enable Apex Mode for this bulk submission', 'rapidurlindexer-wp'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Apex Mode results in ~5min Googlebot crawl, up to 3 crawl attempts per URL, Bing indexing, and costs 3 credits per URL (1 refunded if not indexed).', 'rapidurlindexer-wp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rui-urls"><?php esc_html_e('URLs (one per line)', 'rapidurlindexer-wp'); ?></label></th>
                        <td><textarea id="rui-urls" name="urls" rows="10" class="large-text"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" id="rapidurlindexer-submit-urls" class="button-primary" value="<?php esc_attr_e('Submit URLs', 'rapidurlindexer-wp'); ?>" />
                </p>
                <div id="rui-bulk-submit-response"></div>
            </form>

            <?php if ($max_submissions_per_24h > 0 && !empty($submission_queue)): ?>
                <h2><?php esc_html_e('Current Submission Queue', 'rapidurlindexer-wp'); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %1$d: queued URL count, %2$d: maximum submissions per 24 hours */
                        esc_html__('%1$d URL(s) are waiting because the maximum submission limit is set to %2$d URLs per rolling 24 hours.', 'rapidurlindexer-wp'),
                        count($submission_queue),
                        $max_submissions_per_24h
                    );
                    ?>
                </p>
                <table class="wp-list-table widefat fixed striped rui-submission-queue">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('URL', 'rapidurlindexer-wp'); ?></th>
                            <th scope="col"><?php esc_html_e('Queued At', 'rapidurlindexer-wp'); ?></th>
                            <th scope="col"><?php esc_html_e('Action Type', 'rapidurlindexer-wp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submission_queue as $queue_item): ?>
                        <tr>
                            <td><?php echo esc_html(isset($queue_item['url']) ? $queue_item['url'] : ''); ?></td>
                            <td>
                                <?php
                                $queued_at = isset($queue_item['queued_at']) ? (int) $queue_item['queued_at'] : 0;
                                echo esc_html($queued_at ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $queued_at) : __('Unknown', 'rapidurlindexer-wp'));
                                ?>
                            </td>
                            <td><?php echo esc_html(isset($queue_item['action_type']) ? $queue_item['action_type'] : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Logs', 'rapidurlindexer-wp'); ?></h2>
            <p class="submit">
                <button type="button" id="rui-clear-logs" class="button button-secondary"><?php esc_html_e('Clear Logs', 'rapidurlindexer-wp'); ?></button>
            </p>
            <?php
            if (empty($logs)): ?>
                <p><?php esc_html_e('No logs available.', 'rapidurlindexer-wp'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('URL', 'rapidurlindexer-wp'); ?></th>
                            <th scope="col"><?php esc_html_e('Date and Time', 'rapidurlindexer-wp'); ?></th>
                            <th scope="col"><?php esc_html_e('Action Type', 'rapidurlindexer-wp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->url); ?></td>
                            <td><?php echo esc_html($log->date_time); ?></td>
                            <td><?php echo esc_html($log->action_type); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function page_init() {
        register_setting('rui_settings', 'rui_settings', array($this, 'sanitize_settings'));

        // API Settings
        add_settings_section('rui_api_settings', __('API Settings', 'rapidurlindexer-wp'), array($this, 'api_settings_section_callback'), 'rapidurlindexer-wp');
        add_settings_field('rui_api_key', __('API Key', 'rapidurlindexer-wp'), array($this, 'api_key_callback'), 'rapidurlindexer-wp', 'rui_api_settings');
        add_settings_field('rui_email_status_updates', __('Email Status Updates', 'rapidurlindexer-wp'), array($this, 'email_status_updates_callback'), 'rapidurlindexer-wp', 'rui_api_settings');
        add_settings_field('rui_apex_mode_enabled', __('Apex Mode', 'rapidurlindexer-wp'), array($this, 'apex_mode_enabled_callback'), 'rapidurlindexer-wp', 'rui_api_settings');
        add_settings_field('rui_remaining_credits', __('Remaining Credits', 'rapidurlindexer-wp'), array($this, 'remaining_credits_callback'), 'rapidurlindexer-wp', 'rui_api_settings');
        
        add_settings_field('rui_api_connection_test', __('API Connection Test', 'rapidurlindexer-wp'), array($this, 'api_connection_test_callback'), 'rapidurlindexer-wp', 'rui_api_settings');

        // Automatic Submission Settings
        add_settings_section('rui_automatic_submission_settings', __('Automatic Submission Settings', 'rapidurlindexer-wp'), null, 'rapidurlindexer-wp');
        $this->add_post_type_settings();
        add_settings_field('rui_always_submit_on_publish', __('Always Submit on Publish', 'rapidurlindexer-wp'), array($this, 'always_submit_on_publish_callback'), 'rapidurlindexer-wp', 'rui_automatic_submission_settings');
        add_settings_field('rui_max_submissions_per_24h', __('Maximum URL Submissions per 24 Hours', 'rapidurlindexer-wp'), array($this, 'max_submissions_per_24h_callback'), 'rapidurlindexer-wp', 'rui_automatic_submission_settings');

        // Log Settings
        add_settings_section('rui_log_settings', __('Log Settings', 'rapidurlindexer-wp'), null, 'rapidurlindexer-wp');
        add_settings_field('rui_max_logs', __('Maximum Logs', 'rapidurlindexer-wp'), array($this, 'max_logs_callback'), 'rapidurlindexer-wp', 'rui_log_settings');

        // Uninstall Settings
        add_settings_section('rui_uninstall_settings', __('Uninstall Settings', 'rapidurlindexer-wp'), null, 'rapidurlindexer-wp');
        add_settings_field('rui_remove_data_on_uninstall', __('Remove data on uninstall', 'rapidurlindexer-wp'), array($this, 'remove_data_on_uninstall_callback'), 'rapidurlindexer-wp', 'rui_uninstall_settings');
    }

    private function add_post_type_settings() {
        $post_types = $this->get_auto_submit_post_types('objects');
        foreach ($post_types as $post_type) {
            add_settings_field(
                'rui_submit_on_publish_' . $post_type->name,
                sprintf(__('Submit on Publish (%s)', 'rapidurlindexer-wp'), $post_type->labels->singular_name),
                array($this, 'post_type_checkbox_callback'),
                'rapidurlindexer-wp',
                'rui_automatic_submission_settings',
                array('post_type' => $post_type->name, 'action' => 'publish')
            );
            add_settings_field(
                'rui_submit_on_update_' . $post_type->name,
                sprintf(__('Submit on Update (%s)', 'rapidurlindexer-wp'), $post_type->labels->singular_name),
                array($this, 'post_type_checkbox_callback'),
                'rapidurlindexer-wp',
                'rui_automatic_submission_settings',
                array('post_type' => $post_type->name, 'action' => 'update')
            );
        }
    }

    public function post_type_checkbox_callback($args) {
        $settings = get_option('rui_settings');
        $field_name = 'rui_settings[submit_on_' . $args['action'] . '_' . $args['post_type'] . ']';
        $checked = isset($settings['submit_on_' . $args['action'] . '_' . $args['post_type']]) ? $settings['submit_on_' . $args['action'] . '_' . $args['post_type']] : 0;
        echo '<input type="checkbox" name="' . esc_attr($field_name) . '" value="1" ' . checked($checked, 1, false) . '/>';
    }

    public function sanitize_settings($input) {
        if (!is_array($input)) {
            $input = array();
        }

        $existing_settings = get_option('rui_settings', array());
        if (!is_array($existing_settings)) {
            $existing_settings = array();
        }

        $sanitized_input = array();
        $api_key = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        if ('' !== $api_key) {
            $sanitized_input['api_key'] = $api_key;
        } elseif (isset($existing_settings['api_key'])) {
            $sanitized_input['api_key'] = $existing_settings['api_key'];
        }
        
        if (isset($input['email_status_updates'])) {
            $sanitized_input['email_status_updates'] = (bool) $input['email_status_updates'];
        }

        $sanitized_input['apex_mode_enabled'] = isset($input['apex_mode_enabled']) ? 1 : 0;
        
        foreach ($existing_settings as $key => $value) {
            if (preg_match('/^submit_on_(publish|update)_[A-Za-z0-9_-]+$/', $key)) {
                $sanitized_input[$key] = (int) !empty($value);
            }
        }

        foreach ($input as $key => $value) {
            if (preg_match('/^submit_on_(publish|update)_[A-Za-z0-9_-]+$/', $key)) {
                $sanitized_input[$key] = (int) !empty($value);
            }
        }

        $post_types = $this->get_auto_submit_post_types('names');
        foreach ($post_types as $post_type) {
            $sanitized_input['submit_on_publish_' . $post_type] = isset($input['submit_on_publish_' . $post_type]) ? 1 : 0;
            $sanitized_input['submit_on_update_' . $post_type] = isset($input['submit_on_update_' . $post_type]) ? 1 : 0;
        }
        
        if (isset($input['max_logs'])) {
            $sanitized_input['max_logs'] = absint($input['max_logs']);
        }
        
        if (isset($input['remove_data_on_uninstall'])) {
            $sanitized_input['remove_data_on_uninstall'] = (bool) $input['remove_data_on_uninstall'];
        }
        
        if (isset($input['always_submit_on_publish'])) {
            $sanitized_input['always_submit_on_publish'] = (bool) $input['always_submit_on_publish'];
        }

        $sanitized_input['max_submissions_per_24h'] = isset($input['max_submissions_per_24h']) ? absint($input['max_submissions_per_24h']) : 0;
        
        // Remove error_log to prevent large amounts of data being written
        // error_log("Sanitized settings: " . print_r($sanitized_input, true));
        
        // Use update_option only once, outside of this function
        // update_option('rui_settings', $sanitized_input);
        
        return $sanitized_input;
    }

    public function always_submit_on_publish_callback() {
        $settings = get_option('rui_settings');
        $always_submit = isset($settings['always_submit_on_publish']) ? $settings['always_submit_on_publish'] : 0;
        echo "<input type='checkbox' name='rui_settings[always_submit_on_publish]' value='1' " . checked($always_submit, 1, false) . " />";
        echo "<p class='description'>" . esc_html__('Always submit URLs on publish, regardless of other settings.', 'rapidurlindexer-wp') . "</p>";
    }

    public function max_submissions_per_24h_callback() {
        $settings = get_option('rui_settings', array());
        $max_submissions = isset($settings['max_submissions_per_24h']) ? absint($settings['max_submissions_per_24h']) : 0;
        echo "<input type='number' name='rui_settings[max_submissions_per_24h]' value='" . esc_attr($max_submissions) . "' min='0' />";
        echo "<p class='description'>" . esc_html__('Set the maximum number of URLs to submit in any rolling 24-hour period. Use 0 for unlimited submissions. Overflow URLs are queued and submitted later.', 'rapidurlindexer-wp') . "</p>";
    }

    public function submit_on_publish_callback() {
        $settings = get_option('rui_settings', array());
        $post_types = $this->get_auto_submit_post_types('objects');
        echo '<fieldset>';
        foreach ($post_types as $post_type) {
            $option_name = "submit_on_publish_{$post_type->name}";
            $checked = isset($settings[$option_name]) ? $settings[$option_name] : 0;
            echo '<label>';
            echo wp_kses(
                sprintf(
                    '<input type="checkbox" name="rui_settings[%s]" value="1" %s>',
                    esc_attr($option_name),
                    checked($checked, 1, false)
                ),
                array(
                    'input' => array(
                        'type' => array(),
                        'name' => array(),
                        'value' => array(),
                        'checked' => array()
                    )
                )
            );
            echo esc_html($post_type->labels->singular_name);
            echo '</label><br>';
        }
        echo '</fieldset>';
    }

    public function submit_on_update_callback() {
        $settings = get_option('rui_settings', array());
        $post_types = $this->get_auto_submit_post_types('objects');
        echo '<fieldset>';
        foreach ($post_types as $post_type) {
            $option_name = "submit_on_update_{$post_type->name}";
            $checked = isset($settings[$option_name]) ? $settings[$option_name] : 0;
            echo '<label>';
            echo wp_kses(
                sprintf(
                    '<input type="checkbox" name="rui_settings[%s]" value="1" %s>',
                    esc_attr($option_name),
                    checked($checked, 1, false)
                ),
                array(
                    'input' => array(
                        'type' => array(),
                        'name' => array(),
                        'value' => array(),
                        'checked' => array()
                    )
                )
            );
            echo esc_html($post_type->labels->singular_name);
            echo '</label><br>';
        }
        echo '</fieldset>';
    }

    // This method is no longer needed as we've incorporated its functionality directly into the HTML
    // public function post_type_checkbox_callback($args) {
    //     // Method content removed
    // }

    public function remove_data_on_uninstall_callback() {
        $settings = get_option('rui_settings', array());
        $remove_data = isset($settings['remove_data_on_uninstall']) ? $settings['remove_data_on_uninstall'] : 0;
        echo "<input type='checkbox' name='rui_settings[remove_data_on_uninstall]' value='1' " . checked($remove_data, 1, false) . " />";
        echo "<p class='description'>" . esc_html__('All settings and logs will be removed when the plugin is uninstalled.', 'rapidurlindexer-wp') . "</p>";
    }

    public function post_types_callback() {
        $settings = get_option('rui_settings');
        $selected_post_types = isset($settings['post_types']) ? $settings['post_types'] : array();
        $post_types = $this->get_auto_submit_post_types('objects');

        echo '<select name="rui_settings[post_types][]" multiple="multiple" style="height: 100px;">';
        foreach ($post_types as $post_type) {
            $selected = in_array($post_type->name, $selected_post_types) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($post_type->name) . '" ' . esc_attr($selected) . '>' . esc_html($post_type->labels->singular_name) . '</option>';
        }
        echo '</select>';
    }

    public function max_logs_callback() {
        $settings = get_option('rui_settings');
        $max_logs = isset($settings['max_logs']) ? $settings['max_logs'] : 100;
        echo "<input type='number' name='rui_settings[max_logs]' value='" . esc_attr($max_logs) . "' min='10' />";
        echo "<p class='description'>" . esc_html__('Maximum number of logs to keep. Older logs will be automatically deleted.', 'rapidurlindexer-wp') . "</p>";
    }

    public function api_settings_section_callback() {
        echo '<p>' . esc_html__('Configure your Rapid URL Indexer API settings here.', 'rapidurlindexer-wp') . '</p>';
    }

    public function remaining_credits_callback() {
        echo '<span class="rui-credits-display">' . esc_html__('Fetching...', 'rapidurlindexer-wp') . '</span>';
        echo '<p class="description">' . esc_html__('This balance is fetched in real-time from the API.', 'rapidurlindexer-wp') . '</p>';
        echo '<button type="button" id="rui-refresh-credits" class="button button-secondary">' . esc_html__('Refresh Credits', 'rapidurlindexer-wp') . '</button>';
    }

    public function api_connection_test_callback() {
        $connection_test = $this->test_api_connection();
        if (isset($connection_test['success'])) {
            echo '<span style="color: green;">' . esc_html__('Connection successful', 'rapidurlindexer-wp') . '</span>';
        } elseif (isset($connection_test['error'])) {
            echo '<span style="color: red;">' . esc_html__('Connection failed: ', 'rapidurlindexer-wp') . esc_html($connection_test['error']) . '</span>';
        }
    }

    public function api_key_callback() {
        $settings = get_option('rui_settings', array());
        $placeholder = !empty($settings['api_key']) ? __('API key saved. Leave blank to keep it unchanged.', 'rapidurlindexer-wp') : '';
        echo "<input type='password' name='rui_settings[api_key]' value='' autocomplete='new-password' placeholder='" . esc_attr($placeholder) . "' />";
        echo "<p class='description'>" . wp_kses(__('To find your API key, scroll down on your <a href="https://rapidurlindexer.com/my-account/rui-projects/" target="_blank">My Projects</a> page. Saved API keys are hidden; enter a new key only when replacing it.', 'rapidurlindexer-wp'), array('a' => array('href' => array(), 'target' => array()))) . "</p>";
    }

    public function email_status_updates_callback() {
        $settings = get_option('rui_settings');
        $email_status_updates = isset($settings['email_status_updates']) ? $settings['email_status_updates'] : 0;
        echo "<input type='checkbox' name='rui_settings[email_status_updates]' value='1' " . checked($email_status_updates, 1, false) . " />";
        echo "<p class='description'>" . esc_html__('Enable email notifications for project status updates.', 'rapidurlindexer-wp') . "</p>";
    }

    public function apex_mode_enabled_callback() {
        $settings = get_option('rui_settings', array());
        $apex_mode_enabled = !empty($settings['apex_mode_enabled']);
        echo "<input type='checkbox' name='rui_settings[apex_mode_enabled]' value='1' " . checked($apex_mode_enabled, true, false) . " />";
        echo "<p class='description'>" . esc_html__('If enabled, new projects will use Apex Mode (~5min Googlebot crawl, up to 3 crawl attempts per URL, Bing indexing). Costs 3 credits per URL (1 refunded if not indexed).', 'rapidurlindexer-wp') . "</p>";
    }


    public function enqueue_scripts($hook) {
        if ($hook === 'settings_page_rapidurlindexer-wp') {
            wp_enqueue_script('jquery');
            wp_register_script('rui-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery'), RUI_PLUGIN_VERSION, true);
            wp_localize_script('rui-admin-js', 'rui_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rui_bulk_submit'),
                'refresh_credits_nonce' => wp_create_nonce('rui_refresh_credits'),
                'clear_logs_nonce' => wp_create_nonce('rui_clear_logs'),
                'confirm_clear_logs' => __('Are you sure you want to clear all logs?', 'rapidurlindexer-wp'),
                'logs_cleared' => __('Logs cleared successfully', 'rapidurlindexer-wp'),
                'error_clearing_logs' => __('Error clearing logs', 'rapidurlindexer-wp'),
                'error_fetching_credits' => __('Error fetching credits', 'rapidurlindexer-wp'),
                'submitting_urls' => __('Submitting URLs...', 'rapidurlindexer-wp'),
                'remaining_credits' => __('Remaining credits:', 'rapidurlindexer-wp'),
                'unknown_error' => __('Unknown error occurred', 'rapidurlindexer-wp'),
                'error_prefix' => __('Error:', 'rapidurlindexer-wp')
            ));
            wp_enqueue_script('rui-admin-js');
            
            wp_register_style('rui-admin-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css', array(), RUI_PLUGIN_VERSION);
            wp_enqueue_style('rui-admin-css');
        }
    }

    private function send_bulk_submission_response($urls_to_submit, $project_name, $apex_mode_enabled, $queued_count = 0) {
        $required_credits = $this->get_required_credits_for_url_count(count($urls_to_submit), $apex_mode_enabled);
        $available_credits = $this->get_credits_balance();
        if (isset($available_credits['error'])) {
            error_log("Failed to check credits balance: " . $available_credits['error']);
            wp_send_json_error(esc_html__('Unable to verify credits. Please try again later.', 'rapidurlindexer-wp'));
        }

        if (!isset($available_credits['credits']) || $available_credits['credits'] < $required_credits) {
            $admin_email = get_option('admin_email');
            $subject = esc_html__('Out of Rapid URL Indexer Credits', 'rapidurlindexer-wp');
            /* translators: %s: URL to buy more credits */
            $message = sprintf(
                esc_html__('You are out of Rapid URL Indexer credits. Please visit %s to buy more.', 'rapidurlindexer-wp'),
                'https://rapidurlindexer.com/my-account/rui-buy-credits/'
            );
            wp_mail($admin_email, $subject, $message);

            wp_send_json_error(sprintf(
                /* translators: %d: Required credits for submission */
                esc_html__('Not enough credits available. %d credits are required for this submission.', 'rapidurlindexer-wp'),
                $required_credits
            ));
        }

        $response = $this->submit_urls($urls_to_submit, $project_name, $apex_mode_enabled);

        if ($this->is_successful_submission_response($response)) {
            $this->record_submission_history(count($urls_to_submit));
            $new_balance = $this->get_credits_balance();

            $message = isset($response['project_id'])
                ? sprintf(
                    /* translators: %d: Project ID */
                    esc_html__('Project created successfully. Project ID: %d', 'rapidurlindexer-wp'),
                    $response['project_id']
                )
                : esc_html__('Project created and submitted successfully.', 'rapidurlindexer-wp');

            if ($queued_count > 0) {
                $message .= ' ' . sprintf(
                    /* translators: %d: queued URL count */
                    esc_html__('%d URL(s) were queued due to the 24-hour submission limit.', 'rapidurlindexer-wp'),
                    $queued_count
                );
            }

            wp_send_json_success(array(
                'message' => $message,
                'credits' => isset($new_balance['credits']) ? $new_balance['credits'] : esc_html__('Unable to fetch balance', 'rapidurlindexer-wp')
            ));
        }

        $error_message = isset($response['message']) ? $response['message'] : esc_html__('Unknown error occurred', 'rapidurlindexer-wp');
        wp_send_json_error(sprintf(
            /* translators: %s: Error message */
            esc_html__('Error submitting URLs: %s', 'rapidurlindexer-wp'),
            $error_message
        ));
    }

    public function rapidurlindexer_handle_bulk_submit() {
        check_ajax_referer('rui_bulk_submit', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Insufficient permissions', 'rapidurlindexer-wp'));
        }

        $urls = isset($_POST['urls']) ? explode("\n", sanitize_textarea_field(wp_unslash($_POST['urls']))) : array();
        $urls = array_filter(array_map('esc_url_raw', $urls));

        if (empty($urls)) {
            wp_send_json_error(esc_html__('No valid URLs provided', 'rapidurlindexer-wp'));
        }

        $project_name = isset($_POST['project_name']) ? sanitize_text_field(wp_unslash($_POST['project_name'])) : esc_html__('Bulk Submit', 'rapidurlindexer-wp');
        $apex_mode_enabled = $this->is_apex_mode_enabled();
        if (array_key_exists('apex_mode_enabled', $_POST)) {
            $apex_mode_enabled = (bool) absint(wp_unslash($_POST['apex_mode_enabled']));
        }

        if (!$this->is_submission_limit_enabled()) {
            $this->send_bulk_submission_response($urls, $project_name, $apex_mode_enabled);
        }

        if (!$this->acquire_submission_lock()) {
            $queued_count = $this->queue_bulk_url_submissions($urls, $project_name, $apex_mode_enabled);
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %d: queued URL count */
                    esc_html__('Submission limit is currently busy. %d URL(s) have been queued for later submission.', 'rapidurlindexer-wp'),
                    $queued_count
                ),
                'credits' => esc_html__('Unchanged', 'rapidurlindexer-wp'),
            ));
        }

        $queued_count = 0;
        $queue_was_non_empty = false;
        $urls_to_submit = array();
        try {
            if (!empty($this->get_submission_queue())) {
                $queue_was_non_empty = true;
                $queued_count = $this->queue_bulk_url_submissions($urls, $project_name, $apex_mode_enabled);
            } else {
                $capacity = $this->get_remaining_submission_capacity();
                $urls_to_submit = array_slice($urls, 0, $capacity);
                $urls_to_queue = array_slice($urls, $capacity);
                $queued_count = $this->queue_bulk_url_submissions($urls_to_queue, $project_name, $apex_mode_enabled);
            }

            if (!$queue_was_non_empty && !empty($urls_to_submit)) {
                $this->send_bulk_submission_response($urls_to_submit, $project_name, $apex_mode_enabled, $queued_count);
            }
        } finally {
            $this->release_submission_lock();
        }

        if ($queue_was_non_empty) {
            $processed_count = $this->process_submission_queue();
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: 1: queued URL count, 2: processed URL count */
                    esc_html__('%1$d URL(s) were queued behind the existing submission queue. %2$d older queued URL(s) were submitted first.', 'rapidurlindexer-wp'),
                    $queued_count,
                    $processed_count
                ),
                'credits' => esc_html__('Unchanged', 'rapidurlindexer-wp'),
            ));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: queued URL count */
                esc_html__('Daily submission limit reached. %d URL(s) have been queued for later submission.', 'rapidurlindexer-wp'),
                $queued_count
            ),
            'credits' => esc_html__('Unchanged', 'rapidurlindexer-wp'),
        ));
    }

    private function submit_url($url, $project_name, $apex_mode_enabled) {
        $settings = get_option('rui_settings');
        $email_status_updates = isset($settings['email_status_updates']) ? $settings['email_status_updates'] : 0;

        $response = $this->make_api_request('POST', 'projects', array(
            'project_name' => $project_name,
            'urls' => array($url),
            'notify_on_status_change' => $email_status_updates == 1,
            'apex_mode_enabled' => (bool) $apex_mode_enabled
        ));

        if (is_wp_error($response)) {
            $this->log_api_error($response);
            return array('code' => 1, 'message' => esc_html__('Error communicating with API', 'rapidurlindexer-wp'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($response_body)) {
            $response_body = array();
        }

        if ($response_code === 201 || $response_code === 200) {
            $this->log_api_response($response_body);
            return array_merge(array('code' => $response_code), $response_body);
        } else {
            /* translators: %s: Error message */
            $error_message = isset($response_body['message']) ? $response_body['message'] : esc_html__('Unknown API error', 'rapidurlindexer-wp');
            $this->log_api_error($error_message);
            return array('code' => $response_code, 'message' => $error_message);
        }
    }

    private function make_api_request($method, $endpoint, $body = null) {
        $args = array(
            'headers' => array(
                'X-API-Key' => $this->get_api_key(),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $url = $this->get_api_base_url() . $endpoint;

        switch ($method) {
            case 'GET':
                return wp_remote_get($url, $args);
            case 'POST':
                return wp_remote_post($url, $args);
            default:
                return new WP_Error('invalid_method', esc_html__('Invalid HTTP method', 'rapidurlindexer-wp'));
        }
    }

    private function log_api_error($error) {
        if (is_wp_error($error)) {
            $error_message = $error->get_error_message();
        } else {
            $error_message = $error;
        }
        error_log("Rapid URL Indexer API Error: $error_message");
    }

    private function log_api_response($response) {
        error_log("Rapid URL Indexer API Response: " . wp_json_encode($response));
    }

    private function submit_urls($urls, $project_name, $apex_mode_enabled) {
        $settings = get_option('rui_settings');
        $email_status_updates = isset($settings['email_status_updates']) ? $settings['email_status_updates'] : 0;

        $response = $this->make_api_request('POST', 'projects', array(
            'project_name' => $project_name,
            'urls' => $urls,
            'notify_on_status_change' => $email_status_updates == 1,
            'apex_mode_enabled' => (bool) $apex_mode_enabled
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("API Error: $error_message");
            return array('code' => 1, 'message' => esc_html__('Error communicating with API', 'rapidurlindexer-wp'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 201) {
            return $response_body;
        } else {
            /* translators: %s: Error message */
            $error_message = isset($response_body['message']) ? $response_body['message'] : esc_html__('Unknown API error', 'rapidurlindexer-wp');
            error_log("API Error: $error_message");
            return array('message' => $error_message);
        }
    }

    private function get_api_key() {
        $settings = get_option('rui_settings');
        return isset($settings['api_key']) ? $settings['api_key'] : '';
    }

    public function get_credits_balance() {
        if ('' === $this->get_api_key()) {
            return array('error' => esc_html__('Rapid URL Indexer API key is missing.', 'rapidurlindexer-wp'));
        }

        $response = $this->make_api_request('GET', 'credits/balance');

        if (is_wp_error($response)) {
            $this->log_api_error($response);
            return array('error' => esc_html__('Error communicating with API', 'rapidurlindexer-wp'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 200 && isset($response_body['credits'])) {
            return array('credits' => (int)$response_body['credits']);
        } else {
            $error_message = isset($response_body['message']) ? $response_body['message'] : esc_html__('Unknown API error', 'rapidurlindexer-wp');
            $this->log_api_error($error_message);
            return array('error' => $error_message);
        }
    }

    public function test_api_connection() {
        if ('' === $this->get_api_key()) {
            return array('error' => esc_html__('Rapid URL Indexer API key is missing.', 'rapidurlindexer-wp'));
        }

        $response = $this->make_api_request('GET', 'credits/balance');

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_api_error("WP Error: $error_message");
            return array('error' => $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $this->log_api_response("Test connection - Response code: $response_code, Body: $response_body");

        if ($response_code === 200) {
            return array('success' => true);
        } else {
            $error_message = 'API connection test failed';
            $this->log_api_error("API Error: $error_message");
            return array('error' => $error_message);
        }
    }


    private function delete_old_logs($limit) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rui_logs ORDER BY date_time ASC LIMIT %d", $limit));
        $this->update_logs_count();
    }

    private function truncate_logs_table() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rui_logs");
        update_option('rui_logs_count', 0);
    }

    private function get_logs_from_db($limit) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rui_logs ORDER BY date_time DESC LIMIT %d", $limit));
    }

    public function handle_refresh_credits() {
        check_ajax_referer('rui_refresh_credits', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Insufficient permissions', 'rapidurlindexer-wp'));
        }

        $credits_info = $this->get_credits_balance();
        if (isset($credits_info['credits'])) {
            wp_send_json_success(array('credits' => intval($credits_info['credits'])));
        } else {
            wp_send_json_error(array('error' => esc_html__('Failed to fetch credits', 'rapidurlindexer-wp')));
        }
    }
    }
}

if (class_exists('RUI_WordPress_Plugin')) {
    global $rapidurlindexer_wordpress;
    $rapidurlindexer_wordpress = new RUI_WordPress_Plugin();
}

// Register uninstall hook
register_uninstall_hook(__FILE__, 'rapidurlindexer_uninstall');
register_deactivation_hook(__FILE__, array('RUI_WordPress_Plugin', 'deactivate'));

/**
 * Uninstall function to clean up the plugin data.
 */
function rapidurlindexer_uninstall() {
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        exit;
    }

    // Load plugin text domain
    load_plugin_textdomain('rapidurlindexer-wp', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    $settings = get_option('rui_settings');
    $remove_data = isset($settings['remove_data_on_uninstall']) ? $settings['remove_data_on_uninstall'] : 0;

    if ($remove_data) {
        // Remove options
        delete_option('rui_settings');
        delete_option('rui_submission_history');
        delete_option('rui_submission_queue');
        delete_option('rui_submission_queue_lock');
        
        // Remove custom post meta
        delete_post_meta_by_key('_rui_submit_on_publish');
        delete_post_meta_by_key('_rui_submit_on_update');
        
        // Remove custom term meta
        delete_metadata('term', 0, '_rui_submit_on_publish', '', true);
        delete_metadata('term', 0, '_rui_submit_on_update', '', true);
        
        // Remove logs table
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}rui_logs`");
        
        // Remove any transients
        delete_transient('rui_logs_count');
        
        // Clear any cached data
        wp_cache_delete('rui_logs_count');
        wp_cache_delete('rui_logs');
    }
}
