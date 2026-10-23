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
        add_action('wp_ajax_copy_post_terms', [$this, 'terms']);
        add_action('wp_ajax_copy_post_create_term', [$this, 'create_term']);
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

    public function terms(): void
    {
        check_ajax_referer('copy_post_run', 'nonce');

        $target_id = isset($_POST['target_id']) ? absint(wp_unslash($_POST['target_id'])) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';

        if ($target_id <= 0 || $post_type === '' || !Sites::can_copy_to($target_id)) {
            wp_send_json_error(['message' => __('Нет доступа к целевому сайту.', 'copy-post')], 403);
        }

        switch_to_blog($target_id);

        $taxonomies_data = [];
        $error = '';

        try {
            if (!post_type_exists($post_type)) {
                $error = __('Тип записи не зарегистрирован на целевом сайте.', 'copy-post');
            } else {
                $taxonomies = get_object_taxonomies($post_type, 'objects');
                $limit = (int) apply_filters('copy_post_terms_limit', 1000, $target_id);

                foreach ($taxonomies as $taxonomy => $object) {
                    if ($taxonomy === 'post_format') {
                        continue;
                    }

                    if (empty($object->public) && empty($object->show_ui)) {
                        continue;
                    }

                    $terms = get_terms([
                        'taxonomy' => $taxonomy,
                        'hide_empty' => false,
                        'number' => $limit > 0 ? $limit : 0,
                    ]);

                    if (is_wp_error($terms)) {
                        continue;
                    }

                    $can_create = false;

                    if (!empty($object->cap->manage_terms)) {
                        $can_create = current_user_can($object->cap->manage_terms);
                    }

                    $taxonomies_data[] = [
                        'name' => $taxonomy,
                        'label' => (string) $object->labels->name,
                        'singular' => (string) $object->labels->singular_name,
                        'hierarchical' => !empty($object->hierarchical),
                        'can_create' => $can_create,
                        'terms' => self::terms_payload($terms, !empty($object->hierarchical)),
                    ];
                }
            }
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
        } finally {
            restore_current_blog();
        }

        if ($error !== '') {
            wp_send_json_error(['message' => $error], 400);
        }

        wp_send_json_success([
            'taxonomies' => $taxonomies_data,
        ]);
    }

    public function create_term(): void
    {
        check_ajax_referer('copy_post_run', 'nonce');

        $target_id = isset($_POST['target_id']) ? absint(wp_unslash($_POST['target_id'])) : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $parent = isset($_POST['parent']) ? absint(wp_unslash($_POST['parent'])) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';

        if ($target_id <= 0 || $taxonomy === '' || $name === '' || !Sites::can_copy_to($target_id)) {
            wp_send_json_error(['message' => __('Нет доступа к целевому сайту.', 'copy-post')], 403);
        }

        switch_to_blog($target_id);

        $term_data = null;
        $error = '';

        try {
            $taxonomy_object = get_taxonomy($taxonomy);

            if (!$taxonomy_object) {
                $error = __('Таксономия не зарегистрирована на целевом сайте.', 'copy-post');
            } elseif ($post_type !== '' && !is_object_in_taxonomy($post_type, $taxonomy)) {
                $error = __('Эта таксономия не относится к выбранному типу записи.', 'copy-post');
            } else {
                $capability = !empty($taxonomy_object->cap->manage_terms)
                    ? (string) $taxonomy_object->cap->manage_terms
                    : 'manage_categories';

                if (!current_user_can($capability)) {
                    $error = __('Недостаточно прав для создания терминов на целевом сайте.', 'copy-post');
                } else {
                    if (empty($taxonomy_object->hierarchical)) {
                        $parent = 0;
                    }

                    if ($parent > 0) {
                        $parent_term = get_term($parent, $taxonomy);

                        if (!$parent_term instanceof \WP_Term) {
                            $parent = 0;
                        }
                    }

                    $existing = null;

                    if (!empty($taxonomy_object->hierarchical)) {
                        $matches = get_terms([
                            'taxonomy' => $taxonomy,
                            'name' => $name,
                            'parent' => $parent,
                            'hide_empty' => false,
                            'number' => 1,
                        ]);

                        if (!is_wp_error($matches) && $matches !== []) {
                            $existing = (int) $matches[0]->term_id;
                        }
                    } else {
                        $existing = term_exists($name, $taxonomy);
                    }

                    if (is_array($existing) && isset($existing['term_id'])) {
                        $term_id = (int) $existing['term_id'];
                    } elseif (is_int($existing) || is_string($existing)) {
                        $term_id = (int) $existing;
                    } else {
                        $created = wp_insert_term($name, $taxonomy, [
                            'parent' => $parent,
                        ]);

                        if (is_wp_error($created)) {
                            $error = $created->get_error_message();
                            $term_id = 0;
                        } else {
                            $term_id = (int) $created['term_id'];
                        }
                    }

                    if ($error === '' && !empty($term_id)) {
                        $term = get_term($term_id, $taxonomy);

                        if ($term instanceof \WP_Term) {
                            $term_data = [
                                'id' => (int) $term->term_id,
                                'name' => $term->name,
                                'parent' => (int) $term->parent,
                                'depth' => count(get_ancestors((int) $term->term_id, $taxonomy, 'taxonomy')),
                            ];
                        } else {
                            $error = __('Не удалось прочитать созданный термин.', 'copy-post');
                        }
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
        } finally {
            restore_current_blog();
        }

        if ($error !== '') {
            wp_send_json_error(['message' => $error], 400);
        }

        wp_send_json_success([
            'term' => $term_data,
        ]);
    }

    /**
     * @param array<int, \WP_Term> $terms
     * @return array<int, array{id:int,name:string,parent:int,depth:int}>
     */
    private static function terms_payload(array $terms, bool $hierarchical): array
    {
        if (!$hierarchical) {
            usort($terms, static function (\WP_Term $a, \WP_Term $b): int {
                return strcasecmp($a->name, $b->name);
            });

            return array_map(static function (\WP_Term $term): array {
                return [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'parent' => (int) $term->parent,
                    'depth' => 0,
                ];
            }, $terms);
        }

        $by_parent = [];

        foreach ($terms as $term) {
            $by_parent[(int) $term->parent][] = $term;
        }

        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $by_parent): void {
            if (empty($by_parent[$parent])) {
                return;
            }

            foreach ($by_parent[$parent] as $term) {
                $out[] = [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'parent' => (int) $term->parent,
                    'depth' => $depth,
                ];

                $walk((int) $term->term_id, $depth + 1);
            }
        };

        $walk(0, 0);
        $seen = array_column($out, 'id');

        foreach ($terms as $term) {
            if (!in_array((int) $term->term_id, $seen, true)) {
                $out[] = [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'parent' => (int) $term->parent,
                    'depth' => 0,
                ];
            }
        }

        return $out;
    }
}
