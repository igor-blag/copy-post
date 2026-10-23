<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$copy_post_settings = get_site_option('copy_post_settings', []);
delete_site_option('copy_post_settings');

if (empty($copy_post_settings['delete_data_on_uninstall'])) {
    return;
}

$copy_post_meta_keys = [
    '_copy_post_origin',
    '_copy_post_source_blog_id',
    '_copy_post_source_post_id',
    '_copy_post_source_url',
    '_copy_post_source_title',
    '_copy_post_copied_at',
    '_copy_post_meta_keys',
    '_copy_post_copies',
    '_copy_post_media_source',
    '_copy_post_media_source_url',
];

if (is_multisite()) {
    $copy_post_sites = get_sites([
        'number' => 0,
        'archived' => 0,
        'spam' => 0,
        'deleted' => 0,
    ]);

    foreach ($copy_post_sites as $copy_post_site) {
        switch_to_blog((int) $copy_post_site->blog_id);

        foreach ($copy_post_meta_keys as $copy_post_key) {
            delete_post_meta_by_key($copy_post_key);
        }

        restore_current_blog();
    }
} else {
    foreach ($copy_post_meta_keys as $copy_post_key) {
        delete_post_meta_by_key($copy_post_key);
    }
}
