<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * AJAX-обработчики.
 */
class Ajax
{
    public function init(): void
    {
        add_action('wp_ajax_copy_post_run', [$this, 'run']);
        add_action('wp_ajax_copy_post_status', [$this, 'status']);
    }

    public function run(): void
    {
        check_ajax_referer('copy_post_run', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $target_ids = [];

        if (isset($_POST['target_ids']) && is_array($_POST['target_ids'])) {
            $target_ids = array_map('absint', wp_unslash($_POST['target_ids']));
        }

        $options = [];

        if (isset($_POST['options'])) {
            $decoded = json_decode((string) wp_unslash($_POST['options']), true);

            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        if ($post_id <= 0) {
            wp_send_json_error(['message' => __('Не указана запись.', 'copy-post')], 400);
        }

        if ($target_ids === []) {
            wp_send_json_error(['message' => __('Не выбран целевой сайт.', 'copy-post')], 400);
        }

        $result = Copier::copy($post_id, $target_ids, $options);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ], 400);
        }

        wp_send_json_success($result);
    }

    public function status(): void
    {
        check_ajax_referer('copy_post_run', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;

        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Нет доступа к записи.', 'copy-post')], 403);
        }

        $copies = get_post_meta($post_id, '_copy_post_copies', true);

        wp_send_json_success([
            'copies' => is_array($copies) ? $copies : [],
        ]);
    }
}
