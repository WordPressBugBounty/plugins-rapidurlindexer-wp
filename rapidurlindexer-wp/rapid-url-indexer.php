<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    die;
}

/**
 * Plugin Name: Rapid URL Indexer for WP
 * Description: Submit URLs to Rapid URL Indexer for fast and reliable Google indexing. Uses the Rapid URL Indexer API service.
 * Version: 1.1.3
 * Author: Rapid URL Indexer
 * Author URI: https://rapidurlindexer.com/
 * License: GPLv3
 * Text Domain: rapidurlindexer-wp
 * Domain Path: /languages
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

if (!class_exists('RUI_WordPress_Plugin')) {
    class RUI_WordPress_Plugin {
        private $api_base_url;
        private const STANDARD_CREDITS_PER_URL = 1;
        private const APEX_CREDITS_PER_URL = 3;

        public function __construct() {
            $this->api_base_url = $this->get_api_base_url();
            add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
            add_action('admin_menu', array($this, 'add_plugin_page'));
            add_action('admin_init', array($this, 'page_init'), 1);
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('category_add_form_fields', array($this, 'add_category_meta_fields'));
            add_action('category_edit_form_fields', array($this, 'edit_category_meta_fields'));
            add_action('edited_category', array($this, 'save_category_meta'), 10, 2);
            add_action('create_category', array($this, 'save_category_meta'), 10, 2);
            add_action('wp_ajax_rapidurlindexer_bulk_submit', array($this, 'rapidurlindexer_handle_bulk_submit'));
            add_action('wp_ajax_rui_clear_logs', array($this, 'clear_logs'));
            add_action('wp_ajax_rui_refresh_credits', array($this, 'handle_refresh_credits'));
    
            // Add actions for post status transitions
            add_action('transition_post_status', array($this, 'on_post_status_change'), 10, 3);
            register_activation_hook(__FILE__, array($this, 'create_rapidurlindexer_logs_table'));
            $this->prune_old_logs();
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

    public function add_category_meta_fields($taxonomy) {
        ?>
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

        if (!current_user_can('manage_categories')) {
            wp_die(esc_html__('Insufficient permissions', 'rapidurlindexer-wp'));
            return;
        }

        $submit_on_publish = isset($_POST['rui_submit_on_publish']) ? 1 : 0;
        $submit_on_update = isset($_POST['rui_submit_on_update']) ? 1 : 0;

        update_term_meta($term_id, '_rui_submit_on_publish', $submit_on_publish);
        update_term_meta($term_id, '_rui_submit_on_update', $submit_on_update);
    }

    // Removed unused callback function
    
    public function on_post_status_change($new_status, $old_status, $post) {
        error_log("on_post_status_change triggered for post ID: {$post->ID}, old status: {$old_status}, new status: {$new_status}, post type: {$post->post_type}");
        
        if ($new_status === 'publish' && !post_password_required($post) && $post->post_status !== 'private') {
            $settings = get_option('rui_settings', array());
            $submit_on_publish = isset($settings["submit_on_publish_{$post->post_type}"]) ? $settings["submit_on_publish_{$post->post_type}"] : 0;
            $submit_on_update = isset($settings["submit_on_update_{$post->post_type}"]) ? $settings["submit_on_update_{$post->post_type}"] : 0;

            error_log("Global settings - Submit on publish: {$submit_on_publish}, Submit on update: {$submit_on_update}, Post type: {$post->post_type}");

            // Check post-level settings
            $post_submit_on_publish = get_post_meta($post->ID, '_rui_submit_on_publish', true);
            $post_submit_on_update = get_post_meta($post->ID, '_rui_submit_on_update', true);
            error_log("Post-level settings - Submit on publish: {$post_submit_on_publish}, Submit on update: {$post_submit_on_update}");

            $categories = get_the_category($post->ID);
            $category_submit_on_publish = false;
            $category_submit_on_update = false;

            foreach ($categories as $category) {
                if (get_term_meta($category->term_id, '_rui_submit_on_publish', true)) {
                    $category_submit_on_publish = true;
                }
                if (get_term_meta($category->term_id, '_rui_submit_on_update', true)) {
                    $category_submit_on_update = true;
                }
            }
            error_log("Category-level settings - Submit on publish: " . ($category_submit_on_publish ? 'true' : 'false') . ", Submit on update: " . ($category_submit_on_update ? 'true' : 'false'));

            $should_submit = false;
            $is_new_post = false;

            error_log("Checking if should submit...");
            $post_data = get_post($post->ID);
            $is_new_post = $post_data->post_date === $post_data->post_modified;
            $always_submit = isset($settings['always_submit_on_publish']) ? $settings['always_submit_on_publish'] : 0;

            if ($is_new_post) {
                error_log("New post being published. Post date: {$post_data->post_date}, Post modified: {$post_data->post_modified}");
                $should_submit = $submit_on_publish || $category_submit_on_publish || $post_submit_on_publish === '1' || $always_submit;
            } else {
                error_log("Existing post being updated. Post date: {$post_data->post_date}, Post modified: {$post_data->post_modified}");
                $should_submit = $submit_on_update || $category_submit_on_update || $post_submit_on_update === '1';
            }

            error_log("Should submit: " . ($should_submit ? 'true' : 'false') . 
                      ", Is new post: " . ($is_new_post ? 'true' : 'false') . 
                      ", Always submit: " . ($always_submit ? 'true' : 'false'));

            if ($should_submit || $always_submit) {
                $full_url = get_permalink($post->ID);
                error_log("Attempting to submit URL: {$full_url}");

                error_log("Checking credits balance...");
                $credits_info = $this->get_credits_balance();
                error_log("Credits info: " . print_r($credits_info, true));
                $apex_mode_enabled = $this->is_apex_mode_enabled();
                $required_credits = $this->get_required_credits_for_url_count(1, $apex_mode_enabled);
                
                if (isset($credits_info['error'])) {
                    error_log("Failed to check credits balance: " . $credits_info['error']);
                    return;
                }

                if (!isset($credits_info['credits']) || $credits_info['credits'] < $required_credits) {
                    $admin_email = get_option('admin_email');
                    $subject = __('Out of Rapid URL Indexer Credits', 'rapidurlindexer-wp');
                    /* translators: %s: URL to buy more credits */
                    $message = esc_html__('You do not have enough Rapid URL Indexer credits. Please visit %s to buy more.', 'rapidurlindexer-wp');
                    $message = sprintf($message, 'https://rapidurlindexer.com/my-account/rui-buy-credits/');
                    wp_mail($admin_email, $subject, $message);

                    error_log(__('Not enough credits available. Please buy more credits.', 'rapidurlindexer-wp'));
                    return;
                } else {
                    // Log the submission
                    $site_url = get_site_url();
                    $domain = preg_replace('#^https?://#', '', $site_url);
                    $project_name = $domain . '-' . $post->post_name;
                    $result = $this->submit_url($full_url, $project_name, $apex_mode_enabled);
                    error_log("URL submission result: " . print_r($result, true));

                    if (isset($result['project_id']) || (isset($result['code']) && ($result['code'] === 200 || $result['code'] === 201))) {
                        $this->log_submission($full_url, $is_new_post ? 'publish' : 'update');
                    } else {
                        error_log("Failed to log submission: " . print_r($result, true));
                        $this->log_submission($full_url, 'error');
                    }
                }
            }
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
        $category_submit_on_publish = false;
        $category_submit_on_update = false;

        $categories = get_the_category($post->ID);
        foreach ($categories as $category) {
            if (get_term_meta($category->term_id, '_rui_submit_on_publish', true)) {
                $category_submit_on_publish = true;
            }
            if (get_term_meta($category->term_id, '_rui_submit_on_update', true)) {
                $category_submit_on_update = true;
            }
        }

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

        // Log Settings
        add_settings_section('rui_log_settings', __('Log Settings', 'rapidurlindexer-wp'), null, 'rapidurlindexer-wp');
        add_settings_field('rui_max_logs', __('Maximum Logs', 'rapidurlindexer-wp'), array($this, 'max_logs_callback'), 'rapidurlindexer-wp', 'rui_log_settings');

        // Uninstall Settings
        add_settings_section('rui_uninstall_settings', __('Uninstall Settings', 'rapidurlindexer-wp'), null, 'rapidurlindexer-wp');
        add_settings_field('rui_remove_data_on_uninstall', __('Remove data on uninstall', 'rapidurlindexer-wp'), array($this, 'remove_data_on_uninstall_callback'), 'rapidurlindexer-wp', 'rui_uninstall_settings');
    }

    private function add_post_type_settings() {
        $post_types = get_post_types(array('public' => true), 'objects');
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
        $sanitized_input = array();
        
        if (isset($input['api_key'])) {
            $sanitized_input['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['email_status_updates'])) {
            $sanitized_input['email_status_updates'] = (bool) $input['email_status_updates'];
        }

        $sanitized_input['apex_mode_enabled'] = isset($input['apex_mode_enabled']) ? 1 : 0;
        
        $post_types = get_post_types(array('public' => true), 'names');
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

    public function submit_on_publish_callback() {
        $settings = get_option('rui_settings', array());
        $post_types = get_post_types(array('public' => true), 'objects');
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
        $post_types = get_post_types(array('public' => true), 'objects');
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
        $post_types = get_post_types(array('public' => true), 'objects');

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
        $settings = get_option('rui_settings');
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        echo "<input type='text' name='rui_settings[api_key]' value='" . esc_attr($api_key) . "' />";
        echo "<p class='description'>" . wp_kses(__('To find your API key, scroll down on your <a href="https://rapidurlindexer.com/my-account/rui-projects/" target="_blank">My Projects</a> page.', 'rapidurlindexer-wp'), array('a' => array('href' => array(), 'target' => array()))) . "</p>";
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
            wp_register_script('rui-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery'), '1.0.5', true);
            wp_localize_script('rui-admin-js', 'rui_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rui_bulk_submit'),
                'refresh_credits_nonce' => wp_create_nonce('rui_refresh_credits'),
                'clear_logs_nonce' => wp_create_nonce('rui_clear_logs'),
                'confirm_clear_logs' => __('Are you sure you want to clear all logs?', 'rapidurlindexer-wp'),
                'logs_cleared' => __('Logs cleared successfully', 'rapidurlindexer-wp'),
                'error_clearing_logs' => __('Error clearing logs', 'rapidurlindexer-wp'),
                'error_fetching_credits' => __('Error fetching credits', 'rapidurlindexer-wp')
            ));
            wp_enqueue_script('rui-admin-js');
            
            wp_register_style('rui-admin-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css', array(), '1.0.2');
            wp_enqueue_style('rui-admin-css');
        }
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
        $required_credits = $this->get_required_credits_for_url_count(count($urls), $apex_mode_enabled);

        $available_credits = $this->get_credits_balance();
        if (isset($available_credits['error'])) {
            error_log("Failed to check credits balance: " . $available_credits['error']);
            wp_send_json_error(esc_html__('Unable to verify credits. Please try again later.', 'rapidurlindexer-wp'));
            return;
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

        $response = $this->submit_urls($urls, $project_name, $apex_mode_enabled);

        if (isset($response['project_id'])) {
            // Fetch the new balance after successful submission
            $new_balance = $this->get_credits_balance();

            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %d: Project ID */
                    esc_html__('Project created successfully. Project ID: %d', 'rapidurlindexer-wp'),
                    $response['project_id']
                ),
                'credits' => isset($new_balance['credits']) ? $new_balance['credits'] : esc_html__('Unable to fetch balance', 'rapidurlindexer-wp')
            ));
        } elseif (isset($response['message']) && $response['message'] === 'Project created and submitted') {
            // Handle the case where the project is created but no project_id is returned
            $new_balance = $this->get_credits_balance();

            wp_send_json_success(array(
                'message' => esc_html__('Project created and submitted successfully.', 'rapidurlindexer-wp'),
                'credits' => isset($new_balance['credits']) ? $new_balance['credits'] : esc_html__('Unable to fetch balance', 'rapidurlindexer-wp')
            ));
        } else {
            $error_message = isset($response['message']) ? $response['message'] : esc_html__('Unknown error occurred', 'rapidurlindexer-wp');
            wp_send_json_error(sprintf(
                /* translators: %s: Error message */
                esc_html__('Error submitting URLs: %s', 'rapidurlindexer-wp'),
                $error_message
            ));
        }
    }

    private function submit_url($url, $project_name, $apex_mode_enabled) {
        error_log("Submitting URL: {$url}, Project Name: {$project_name}");
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
            $this->log_submission($url, 'error');
            error_log("API Error: " . $response->get_error_message());
            return array('code' => 1, 'message' => esc_html__('Error communicating with API', 'rapidurlindexer-wp'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        error_log("API Response Code: {$response_code}");
        error_log("API Response Body: " . print_r($response_body, true));

        if ($response_code === 201 || $response_code === 200) {
            $this->log_api_response($response_body);
            $this->log_submission($url, 'success');
            error_log("URL submitted successfully");
            return $response_body;
        } else {
            /* translators: %s: Error message */
            $error_message = isset($response_body['message']) ? $response_body['message'] : esc_html__('Unknown API error', 'rapidurlindexer-wp');
            $this->log_api_error($error_message);
            $this->log_submission($url, 'error');
            error_log("URL submission failed: {$error_message}");
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

        $url = $this->api_base_url . $endpoint;

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

        $response = wp_remote_post($this->api_base_url . 'projects', array(
            'headers' => array(
                'X-API-Key' => $this->get_api_key(),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'project_name' => $project_name,
                'urls' => $urls,
                'notify_on_status_change' => $email_status_updates == 1,
                'apex_mode_enabled' => (bool) $apex_mode_enabled
            )),
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
        $table_name = $wpdb->prefix . 'rui_logs';
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

if (is_admin()) {
    $rapidurlindexer_wordpress = new RUI_WordPress_Plugin();
}

// Register uninstall hook
register_uninstall_hook(__FILE__, 'rapidurlindexer_uninstall');

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
        
        // Remove custom post meta
        delete_post_meta_by_key('_rui_submit_on_publish');
        delete_post_meta_by_key('_rui_submit_on_update');
        
        // Remove custom term meta
        delete_metadata('term', 0, '_rui_submit_on_publish', '', true);
        delete_metadata('term', 0, '_rui_submit_on_update', '', true);
        
        // Remove logs table
        global $wpdb;
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS `%s`", $wpdb->prefix . 'rui_logs'));
        
        // Remove any transients
        delete_transient('rui_logs_count');
        
        // Clear any cached data
        wp_cache_delete('rui_logs_count');
        wp_cache_delete('rui_logs');
    }
}
