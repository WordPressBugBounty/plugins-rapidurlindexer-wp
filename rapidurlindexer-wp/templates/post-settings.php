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
            Submit to Rapid URL Indexer on Publish
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="rui_submit_on_update" value="1" <?php checked($submit_on_update, 1); ?>>
            Submit to Rapid URL Indexer on Update
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="rui_category_submit_on_publish" value="1" <?php checked($category_submit_on_publish, 1); ?>>
            Submit to Rapid URL Indexer on Publish (Category Level)
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="rui_category_submit_on_update" value="1" <?php checked($category_submit_on_update, 1); ?>>
            Submit to Rapid URL Indexer on Update (Category Level)
        </label>
    </p>
    <?php wp_nonce_field('rui_post_settings', 'rui_post_settings_nonce'); ?>
</div>
