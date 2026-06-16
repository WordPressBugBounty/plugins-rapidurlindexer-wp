<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="rui-post-settings">
    <p>
        <label>
            <input type="checkbox" name="rui_submit_on_publish" value="1" <?php checked($submit_on_publish, 1); ?>>
            <?php esc_html_e('Submit to Rapid URL Indexer on Publish', 'rapidurlindexer-wp'); ?>
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="rui_submit_on_update" value="1" <?php checked($submit_on_update, 1); ?>>
            <?php esc_html_e('Submit to Rapid URL Indexer on Update', 'rapidurlindexer-wp'); ?>
        </label>
    </p>
    <p>
        <strong><?php esc_html_e('Taxonomy term setting: Submit on Publish', 'rapidurlindexer-wp'); ?></strong><br>
        <?php echo $category_submit_on_publish ? esc_html__('Enabled for at least one assigned taxonomy term.', 'rapidurlindexer-wp') : esc_html__('Not enabled for assigned taxonomy terms.', 'rapidurlindexer-wp'); ?>
    </p>
    <p>
        <strong><?php esc_html_e('Taxonomy term setting: Submit on Update', 'rapidurlindexer-wp'); ?></strong><br>
        <?php echo $category_submit_on_update ? esc_html__('Enabled for at least one assigned taxonomy term.', 'rapidurlindexer-wp') : esc_html__('Not enabled for assigned taxonomy terms.', 'rapidurlindexer-wp'); ?>
    </p>
    <?php wp_nonce_field('rui_post_settings', 'rui_post_settings_nonce'); ?>
</div>
