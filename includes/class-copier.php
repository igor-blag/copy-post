<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Основная логика копирования.
 */
class Copier
{
    /**
     * @param int[] $target_ids
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    public static function copy(int $source_id, array $target_ids, array $options = [])
    {
        $source = get_post($source_id);

        if (!$source) {
            return new \WP_Error('copy_post_source_missing', __('Запись-источник не найдена.', 'copy-post'));
        }

        if (in_array($source->post_type, ['attachment', 'revision', 'nav_menu_item'], true)) {
            return new \WP_Error('copy_post_source_type', __('Этот тип записи нельзя копировать.', 'copy-post'));
        }

        if (!in_array($source->post_type, Settings::post_types(), true)) {
            return new \WP_Error('copy_post_type_disabled', __('Тип записи не включён в настройках Copy Post.', 'copy-post'));
        }

        if (in_array($source->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
            return new \WP_Error('copy_post_source_status', __('Запись в корзине или ещё не создана — копировать нельзя.', 'copy-post'));
        }

        if (!current_user_can('edit_post', $source_id)) {
            return new \WP_Error('copy_post_forbidden', __('Недостаточно прав для копирования этой записи.', 'copy-post'));
        }

        $target_ids = array_values(array_unique(array_filter(array_map('absint', (array) $target_ids))));

        if ($target_ids === []) {
            return new \WP_Error('copy_post_no_targets', __('Не выбран ни один целевой сайт.', 'copy-post'));
        }

        $options = self::sanitize_options($options);
        $payload = self::build_payload($source, $options);
        $results = [];

        foreach ($target_ids as $target_id) {
            if ($target_id === get_current_blog_id()) {
                $results[] = self::error_result(
                    $target_id,
                    'copy_post_same_site',
                    __('Нельзя копировать запись на тот же сайт.', 'copy-post')
                );
                continue;
            }

            if (!Sites::can_copy_to($target_id)) {
                $results[] = self::error_result(
                    $target_id,
                    'copy_post_target_forbidden',
                    __('Нет доступа к целевому сайту.', 'copy-post')
                );
                continue;
            }

            $result = self::copy_to_site($payload, $target_id, $options);

            if (is_wp_error($result)) {
                $results[] = self::error_result(
                    $target_id,
                    $result->get_error_code(),
                    $result->get_error_message()
                );
                continue;
            }

            self::save_source_mapping($source_id, $target_id, $result);
            $results[] = $result;
        }

        return [
            'source_id' => $source_id,
            'source_title' => get_the_title($source),
            'results' => $results,
        ];
    }

    /**
     * @param mixed $options
     * @return array<string, mixed>
     */
    public static function sanitize_options($options): array
    {
        $options = is_array($options) ? $options : [];
        $booleans = [
            'copy_taxonomies',
            'copy_meta',
            'copy_media',
            'copy_comments',
            'copy_dates',
            'copy_authors',
            'copy_sticky',
            'rewrite_urls',
            'update_slug',
        ];

        $out = [];

        foreach ($booleans as $key) {
            $out[$key] = array_key_exists($key, $options)
                ? (!empty($options[$key]) ? 1 : 0)
                : (int) Settings::get($key, 1);
        }

        $status = isset($options['status']) ? sanitize_key((string) $options['status']) : '';
        $out['status'] = in_array($status, ['draft', 'same', 'publish'], true)
            ? $status
            : (string) Settings::get('default_status', 'draft');

        $duplicate = isset($options['duplicate']) ? sanitize_key((string) $options['duplicate']) : '';
        $out['duplicate'] = in_array($duplicate, ['new', 'update', 'skip'], true)
            ? $duplicate
            : (string) Settings::get('duplicate_action', 'new');

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function build_payload(\WP_Post $post, array $options): array
    {
        $meta = [];

        foreach (get_post_meta($post->ID) as $meta_key => $meta_values) {
            $meta[$meta_key] = array_map('maybe_unserialize', (array) $meta_values);
        }

        $terms = [];

        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy => $object) {
            if ($taxonomy === 'post_format') {
                continue;
            }

            if (empty($object->public) && empty($object->show_ui)) {
                continue;
            }

            $assigned = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'all']);

            if (is_wp_error($assigned)) {
                continue;
            }

            $collected = [];

            foreach ($assigned as $term) {
                if ($term instanceof \WP_Term) {
                    self::collect_term($term, $taxonomy, $collected, true);
                }
            }

            $terms[$taxonomy] = array_values($collected);
        }

        $comments = [];

        if (!empty($options['copy_comments'])) {
            $comments = self::collect_comments($post->ID);
        }

        $media = [];

        if (!empty($options['copy_media'])) {
            $media = Media::collect($post, $meta);
        }

        $permalink = get_permalink($post);

        if (!$permalink) {
            $permalink = home_url('/?p=' . (int) $post->ID);
        }

        $source_home = untrailingslashit(home_url('/'));
        $content_url = untrailingslashit((string) content_url());
        $source_content_global = ($content_url === '' || strpos($content_url, $source_home) !== 0);

        return [
            'source_id' => (int) $post->ID,
            'source_blog_id' => (int) get_current_blog_id(),
            'post_type' => $post->post_type,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_name' => $post->post_name,
            'post_status' => $post->post_status,
            'post_password' => $post->post_password,
            'post_author' => (int) $post->post_author,
            'post_date' => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
            'post_parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'page_template' => (string) get_page_template_slug($post),
            'format' => (string) (get_post_format($post) ?: ''),
            'is_sticky' => $post->post_type === 'post' ? is_sticky($post->ID) : false,
            'source_permalink' => (string) $permalink,
            'source_title' => (string) get_the_title($post),
            'source_home' => home_url('/'),
            'source_site' => site_url('/'),
            'source_content' => $source_content_global ? '' : $content_url,
            'source_content_global' => $source_content_global,
            'source_uploads' => (string) (wp_get_upload_dir()['baseurl'] ?? ''),
            'meta' => $meta,
            'terms' => $terms,
            'thumbnail_id' => (int) get_post_thumbnail_id($post),
            'media' => $media,
            'comments' => $comments,
            'warnings' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    private static function copy_to_site(array $payload, int $target_id, array $options)
    {
        switch_to_blog($target_id);

        $warnings = (array) ($payload['warnings'] ?? []);

        try {
            if (!post_type_exists($payload['post_type'])) {
                return new \WP_Error(
                    'copy_post_target_type',
                    sprintf(
                        /* translators: 1: post type, 2: site name. */
                        __('Тип записи «%1$s» не зарегистрирован на сайте «%2$s».', 'copy-post'),
                        $payload['post_type'],
                        Sites::name($target_id)
                    )
                );
            }

            $origin = $payload['source_blog_id'] . ':' . $payload['source_id'];
            $existing_id = self::find_by_origin($origin);
            $action = 'created';

            if ($existing_id > 0) {
                if ($options['duplicate'] === 'skip') {
                    $existing = get_post($existing_id);

                    return [
                        'target_blog_id' => $target_id,
                        'target_name' => Sites::name($target_id),
                        'action' => 'skipped',
                        'post_id' => $existing_id,
                        'title' => $existing ? get_the_title($existing) : '',
                        'edit_link' => (string) get_edit_post_link($existing_id, 'raw'),
                        'view_link' => (string) self::view_link($existing_id),
                        'status' => $existing ? get_post_status($existing) : '',
                        'warnings' => $warnings,
                    ];
                }

                if ($options['duplicate'] === 'update') {
                    if (!current_user_can('edit_post', $existing_id)) {
                        return new \WP_Error(
                            'copy_post_update_forbidden',
                            __('Нет прав на обновление существующей копии.', 'copy-post')
                        );
                    }

                    $action = 'updated';
                }
            }

            $media = ['map' => [], 'url_map' => [], 'warnings' => []];

            if (!empty($options['copy_media'])) {
                $media = Media::copy(
                    (array) $payload['media'],
                    (int) $payload['source_blog_id'],
                    (string) $payload['source_content']
                );
                $warnings = array_merge($warnings, (array) $media['warnings']);
            }

            $url_map = Remapper::build_url_map(
                $payload,
                (array) $media['map'],
                (array) $media['url_map'],
                !empty($options['rewrite_urls'])
            );

            $content = Remapper::content((string) $payload['post_content'], (array) $media['map'], $url_map);
            $content = apply_filters('copy_post_content', $content, $payload, $target_id, $options);

            $can_publish = current_user_can('publish_posts');
            $status = self::resolve_status((string) $payload['post_status'], (string) $options['status'], $can_publish);

            if (
                $options['status'] === 'same'
                && in_array($payload['post_status'], ['publish', 'future', 'private'], true)
                && $status === 'draft'
            ) {
                $warnings[] = __('Недостаточно прав для публикации — копия сохранена как черновик.', 'copy-post');
            }

            $author = (int) $payload['post_author'];

            if (!empty($options['copy_authors'])) {
                if ($author <= 0 || !get_userdata($author)) {
                    $author = get_current_user_id();
                }

                if ($author > 0 && !is_super_admin($author) && !is_user_member_of_blog($author, $target_id)) {
                    $warnings[] = __('Автор не является участником целевого сайта — использован текущий пользователь.', 'copy-post');
                    $author = get_current_user_id();
                }

                if ($author <= 0) {
                    $author = self::fallback_author_id();
                }
            } else {
                $author = get_current_user_id() ?: self::fallback_author_id();
            }

            $current_user_id = get_current_user_id();

            if (
                $current_user_id > 0
                && $author !== $current_user_id
                && !current_user_can('edit_others_posts')
            ) {
                $warnings[] = __('Нет прав на редактирование чужих записей — автором копии назначен текущий пользователь.', 'copy-post');
                $author = $current_user_id;
            }

            $parent_id = 0;
            $post_type_object = get_post_type_object((string) $payload['post_type']);
            $hierarchical = $post_type_object ? !empty($post_type_object->hierarchical) : false;

            if ($hierarchical && (int) $payload['post_parent'] > 0) {
                $parent_origin = $payload['source_blog_id'] . ':' . (int) $payload['post_parent'];
                $parent_id = self::find_by_origin($parent_origin);

                if ($parent_id <= 0) {
                    $warnings[] = __('Родительская запись не скопирована — копия создана на верхнем уровне.', 'copy-post');
                }
            }

            $page_template = '';

            if ((string) $payload['page_template'] !== '') {
                $templates = wp_get_theme()->get_page_templates(null, (string) $payload['post_type']);

                if (isset($templates[$payload['page_template']])) {
                    $page_template = (string) $payload['page_template'];
                } else {
                    $warnings[] = sprintf(
                        /* translators: %s: template name. */
                        __('Шаблон страницы «%s» отсутствует в теме целевого сайта.', 'copy-post'),
                        (string) $payload['page_template']
                    );
                }
            }

            $postarr = [
                'post_title' => (string) $payload['post_title'],
                'post_content' => $content,
                'post_excerpt' => (string) $payload['post_excerpt'],
                'post_status' => $status,
                'post_type' => (string) $payload['post_type'],
                'post_parent' => $parent_id,
                'menu_order' => (int) $payload['menu_order'],
                'comment_status' => (string) $payload['comment_status'],
                'ping_status' => (string) $payload['ping_status'],
                'post_password' => (string) $payload['post_password'],
            ];

            if ($action === 'updated') {
                $postarr['ID'] = $existing_id;
            }

            if ($action === 'created' || !empty($options['update_slug'])) {
                $postarr['post_name'] = (string) $payload['post_name'];
            }

            if (!empty($options['copy_dates'])) {
                $postarr['post_date'] = (string) $payload['post_date'];
                $postarr['post_date_gmt'] = (string) $payload['post_date_gmt'];
            }

            if ($action === 'created' || !empty($options['copy_authors'])) {
                $postarr['post_author'] = $author;
            }

            if ($page_template !== '') {
                $postarr['page_template'] = $page_template;
            }

            $postarr = apply_filters('copy_post_payload', $postarr, $payload, $target_id, $options);

            do_action('copy_post_before_insert', $postarr, $payload, $target_id, $options);

            $new_id = $action === 'updated'
                ? wp_update_post(wp_slash($postarr), true)
                : wp_insert_post(wp_slash($postarr), true);

            if (is_wp_error($new_id) || !$new_id) {
                $message = is_wp_error($new_id)
                    ? $new_id->get_error_message()
                    : __('неизвестная ошибка вставки', 'copy-post');

                return new \WP_Error(
                    'copy_post_insert_failed',
                    sprintf(
                        /* translators: %s: error message. */
                        __('Не удалось создать копию: %s', 'copy-post'),
                        $message
                    )
                );
            }

            $new_id = (int) $new_id;

            self::copy_terms($payload, $new_id, $warnings);
            self::copy_meta($payload, $new_id, (array) $media['map'], $url_map, $options, $action === 'updated', $warnings);

            $thumbnail_id = (int) $payload['thumbnail_id'];

            if ($thumbnail_id > 0 && isset($media['map'][$thumbnail_id])) {
                set_post_thumbnail($new_id, (int) $media['map'][$thumbnail_id]['id']);
            } else {
                delete_post_thumbnail($new_id);
            }

            if ($page_template !== '') {
                update_post_meta($new_id, '_wp_page_template', wp_slash($page_template));
            } else {
                delete_post_meta($new_id, '_wp_page_template');
            }

            $format = (string) $payload['format'];
            $supported_formats = get_theme_support('post-formats');
            $supported_formats = is_array($supported_formats) && isset($supported_formats[0])
                ? (array) $supported_formats[0]
                : [];

            if ($format !== '' && in_array($format, $supported_formats, true)) {
                set_post_format($new_id, $format);
            } else {
                if ($format !== '') {
                    $warnings[] = sprintf(
                        /* translators: %s: post format. */
                        __('Формат записи «%s» не поддерживается темой целевого сайта.', 'copy-post'),
                        $format
                    );
                }

                set_post_format($new_id, false);
            }

            if ($payload['post_type'] === 'post' && !empty($options['copy_sticky'])) {
                if (!empty($payload['is_sticky'])) {
                    stick_post($new_id);
                } else {
                    unstick_post($new_id);
                }
            }

            if ($action === 'created' && !empty($options['copy_comments']) && !empty($payload['comments'])) {
                self::copy_comments((array) $payload['comments'], $new_id);
            }

            self::attach_media_parents($payload, $media['map'], $new_id);

            update_post_meta($new_id, '_copy_post_origin', wp_slash($origin));
            update_post_meta($new_id, '_copy_post_source_blog_id', (int) $payload['source_blog_id']);
            update_post_meta($new_id, '_copy_post_source_post_id', (int) $payload['source_id']);
            update_post_meta($new_id, '_copy_post_source_url', wp_slash((string) $payload['source_permalink']));
            update_post_meta($new_id, '_copy_post_source_title', wp_slash((string) $payload['source_title']));
            update_post_meta($new_id, '_copy_post_copied_at', current_time('mysql', true));

            clean_post_cache($new_id);

            do_action('copy_post_after', $new_id, (int) $payload['source_id'], $target_id, $action, $options, $payload);

            return [
                'target_blog_id' => $target_id,
                'target_name' => Sites::name($target_id),
                'action' => $action,
                'post_id' => $new_id,
                'title' => get_the_title($new_id),
                'edit_link' => (string) get_edit_post_link($new_id, 'raw'),
                'view_link' => (string) self::view_link($new_id),
                'status' => get_post_status($new_id),
                'warnings' => array_values(array_unique($warnings)),
            ];
        } catch (\Throwable $throwable) {
            return new \WP_Error('copy_post_exception', $throwable->getMessage());
        } finally {
            restore_current_blog();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{id:int,url:string}> $media_map
     * @param array<string, string> $url_map
     * @param array<string, mixed> $options
     */
    private static function copy_meta(
        array $payload,
        int $new_id,
        array $media_map,
        array $url_map,
        array $options,
        bool $is_update,
        array &$warnings
    ): void {
        if (empty($options['copy_meta'])) {
            return;
        }

        $copied_keys = [];
        $source_meta = (array) $payload['meta'];

        foreach ($source_meta as $key => $values) {
            $key = (string) $key;

            if (in_array($key, ['_thumbnail_id', '_wp_page_template', '_copy_post_meta_keys'], true)) {
                continue;
            }

            if (strpos($key, '_copy_post_') === 0 || self::is_excluded_key($key)) {
                continue;
            }

            if (!empty($options['skip_protected_meta']) && substr($key, 0, 1) === '_' && !Remapper::is_media_key($key)) {
                continue;
            }

            $copied_keys[] = $key;
            delete_post_meta($new_id, $key);

            foreach ((array) $values as $value) {
                $value = Remapper::meta_value($key, $value, $media_map, $url_map, $options);
                add_post_meta($new_id, $key, wp_slash($value));
            }
        }

        if ($is_update) {
            $previous = get_post_meta($new_id, '_copy_post_meta_keys', true);

            if (is_array($previous)) {
                foreach ($previous as $old_key) {
                    $old_key = (string) $old_key;

                    if (
                        $old_key !== ''
                        && !in_array($old_key, $copied_keys, true)
                        && strpos($old_key, '_copy_post_') !== 0
                        && !self::is_excluded_key($old_key)
                    ) {
                        delete_post_meta($new_id, $old_key);
                    }
                }
            }
        }

        update_post_meta($new_id, '_copy_post_meta_keys', wp_slash($copied_keys));
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $warnings
     */
    private static function copy_terms(array $payload, int $new_id, array &$warnings): void
    {
        foreach ((array) $payload['terms'] as $taxonomy => $terms) {
            $taxonomy = (string) $taxonomy;

            if (!taxonomy_exists($taxonomy)) {
                $warnings[] = sprintf(
                    /* translators: %s: taxonomy name. */
                    __('Таксономия «%s» не зарегистрирована на целевом сайте.', 'copy-post'),
                    $taxonomy
                );
                continue;
            }

            $term_map = [];
            $pending = (array) $terms;

            while ($pending !== []) {
                $progress = false;

                foreach ($pending as $index => $term) {
                    $parent_source = (int) $term['parent_source_id'];

                    if ($parent_source === 0 || isset($term_map[$parent_source])) {
                        $target_term_id = self::ensure_term($term, $taxonomy, $term_map);

                        if (is_wp_error($target_term_id)) {
                            $warnings[] = sprintf(
                                /* translators: 1: term name, 2: error message. */
                                __('Не удалось создать термин «%1$s»: %2$s', 'copy-post'),
                                $term['name'],
                                $target_term_id->get_error_message()
                            );
                        } else {
                            $term_map[(int) $term['source_id']] = (int) $target_term_id;
                        }

                        unset($pending[$index]);
                        $progress = true;
                    }
                }

                if (!$progress) {
                    foreach ($pending as $term) {
                        $target_term_id = self::ensure_term($term, $taxonomy, $term_map);

                        if (!is_wp_error($target_term_id)) {
                            $term_map[(int) $term['source_id']] = (int) $target_term_id;
                        }
                    }

                    break;
                }
            }

            $assigned_ids = [];

            foreach ((array) $terms as $term) {
                if (empty($term['assigned'])) {
                    continue;
                }

                $source_term_id = (int) $term['source_id'];

                if (isset($term_map[$source_term_id])) {
                    $assigned_ids[] = (int) $term_map[$source_term_id];
                }
            }

            if ($taxonomy === 'category' && $assigned_ids === []) {
                $default_category = (int) get_option('default_category');

                if ($default_category > 0) {
                    $assigned_ids[] = $default_category;
                }
            }

            $assigned_ids = array_values(array_unique(array_filter($assigned_ids)));
            $set_result = wp_set_object_terms($new_id, $assigned_ids, $taxonomy, false);

            if (is_wp_error($set_result)) {
                $warnings[] = sprintf(
                    /* translators: 1: taxonomy name, 2: error message. */
                    __('Не удалось назначить таксономию «%1$s»: %2$s', 'copy-post'),
                    $taxonomy,
                    $set_result->get_error_message()
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $term
     * @param array<int, int> $term_map
     * @return int|\WP_Error
     */
    private static function ensure_term(array $term, string $taxonomy, array $term_map)
    {
        $parent_source = (int) $term['parent_source_id'];
        $parent_id = $parent_source > 0 && isset($term_map[$parent_source])
            ? (int) $term_map[$parent_source]
            : 0;

        $existing = get_term_by('slug', (string) $term['slug'], $taxonomy);

        if ($existing instanceof \WP_Term) {
            return (int) $existing->term_id;
        }

        $existing_id = term_exists((string) $term['name'], $taxonomy);

        if (is_array($existing_id) && isset($existing_id['term_id'])) {
            return (int) $existing_id['term_id'];
        }

        if (is_int($existing_id) || is_string($existing_id)) {
            return (int) $existing_id;
        }

        $created = wp_insert_term((string) $term['name'], $taxonomy, [
            'slug' => (string) $term['slug'],
            'description' => (string) $term['description'],
            'parent' => $parent_id,
        ]);

        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $data = $created->get_error_data();
                $existing_term_id = is_array($data) && isset($data['term_id'])
                    ? (int) $data['term_id']
                    : (int) $data;

                if ($existing_term_id > 0) {
                    return $existing_term_id;
                }
            }

            return $created;
        }

        return (int) $created['term_id'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $warnings
     */
    private static function collect_term(\WP_Term $term, string $taxonomy, array &$terms, bool $assigned): void
    {
        if (isset($terms[$term->term_id])) {
            if ($assigned) {
                $terms[$term->term_id]['assigned'] = true;
            }

            return;
        }

        $terms[$term->term_id] = [
            'source_id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'parent_source_id' => (int) $term->parent,
            'description' => $term->description,
            'assigned' => $assigned,
        ];

        if ($term->parent > 0) {
            $parent = get_term((int) $term->parent, $taxonomy);

            if ($parent instanceof \WP_Term) {
                self::collect_term($parent, $taxonomy, $terms, false);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collect_comments(int $post_id): array
    {
        $comments = get_comments([
            'post_id' => $post_id,
            'status' => 'all',
            'orderby' => 'comment_ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        $out = [];

        foreach ($comments as $comment) {
            $out[] = [
                'comment_ID' => (int) $comment->comment_ID,
                'comment_author' => $comment->comment_author,
                'comment_author_email' => $comment->comment_author_email,
                'comment_author_url' => $comment->comment_author_url,
                'comment_author_IP' => $comment->comment_author_IP,
                'comment_date' => $comment->comment_date,
                'comment_date_gmt' => $comment->comment_date_gmt,
                'comment_content' => $comment->comment_content,
                'comment_karma' => (int) $comment->comment_karma,
                'comment_approved' => $comment->comment_approved,
                'comment_agent' => $comment->comment_agent,
                'comment_type' => $comment->comment_type,
                'comment_parent' => (int) $comment->comment_parent,
                'user_id' => (int) $comment->user_id,
                'comment_meta' => get_comment_meta((int) $comment->comment_ID),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     */
    private static function copy_comments(array $comments, int $new_post_id): void
    {
        $map = [];

        foreach ($comments as $comment) {
            $old_id = (int) $comment['comment_ID'];
            $parent = (int) $comment['comment_parent'];
            $new_parent = $parent > 0 && isset($map[$parent]) ? (int) $map[$parent] : 0;

            $data = [
                'comment_post_ID' => $new_post_id,
                'comment_author' => (string) $comment['comment_author'],
                'comment_author_email' => (string) $comment['comment_author_email'],
                'comment_author_url' => (string) $comment['comment_author_url'],
                'comment_author_IP' => (string) $comment['comment_author_IP'],
                'comment_date' => (string) $comment['comment_date'],
                'comment_date_gmt' => (string) $comment['comment_date_gmt'],
                'comment_content' => (string) $comment['comment_content'],
                'comment_karma' => (int) $comment['comment_karma'],
                'comment_approved' => $comment['comment_approved'],
                'comment_agent' => (string) $comment['comment_agent'],
                'comment_type' => (string) $comment['comment_type'],
                'comment_parent' => $new_parent,
                'user_id' => (int) $comment['user_id'],
            ];

            $new_id = wp_insert_comment(wp_slash($data));

            if (!$new_id) {
                continue;
            }

            $map[$old_id] = (int) $new_id;

            foreach ((array) $comment['comment_meta'] as $meta_key => $values) {
                foreach ((array) $values as $value) {
                    add_comment_meta((int) $new_id, (string) $meta_key, wp_slash($value));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array{id:int,url:string}> $media_map
     */
    private static function attach_media_parents(array $payload, array $media_map, int $new_post_id): void
    {
        foreach ((array) $payload['media'] as $source) {
            $source_id = (int) $source['id'];

            if ((int) ($source['parent'] ?? 0) !== (int) $payload['source_id'] || !isset($media_map[$source_id])) {
                continue;
            }

            $target_attachment_id = (int) $media_map[$source_id]['id'];

            if ((int) get_post_field('post_parent', $target_attachment_id) === 0) {
                wp_update_post([
                    'ID' => $target_attachment_id,
                    'post_parent' => $new_post_id,
                ]);
            }
        }
    }

    private static function resolve_status(string $source_status, string $requested, bool $can_publish): string
    {
        if ($requested === 'draft') {
            return 'draft';
        }

        $status = $requested === 'same' ? $source_status : 'publish';

        if (in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
            return 'draft';
        }

        if (in_array($status, ['publish', 'future', 'private'], true) && !$can_publish) {
            return 'draft';
        }

        if (!in_array($status, ['publish', 'future', 'private', 'draft', 'pending'], true)) {
            return 'draft';
        }

        return $status;
    }

    private static function fallback_author_id(): int
    {
        $admins = get_users([
            'role' => 'administrator',
            'number' => 1,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return $admins ? (int) $admins[0] : 0;
    }

    private static function find_by_origin(string $origin): int
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'orderby' => 'ID',
            'order' => 'DESC',
            'meta_key' => '_copy_post_origin',
            'meta_value' => $origin,
        ]);

        return empty($ids) ? 0 : (int) $ids[0];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function save_source_mapping(int $source_id, int $target_id, array $result): void
    {
        $copies = get_post_meta($source_id, '_copy_post_copies', true);

        if (!is_array($copies)) {
            $copies = [];
        }

        $copies[$target_id] = [
            'post_id' => (int) $result['post_id'],
            'status' => (string) $result['status'],
            'url' => (string) $result['view_link'],
            'date' => current_time('mysql', true),
        ];

        update_post_meta($source_id, '_copy_post_copies', wp_slash($copies));
    }

    private static function is_excluded_key(string $key): bool
    {
        foreach (Settings::excluded_keys() as $pattern) {
            if ($pattern === $key) {
                return true;
            }

            if (substr($pattern, -1) === '*' && strpos($key, rtrim($pattern, '*')) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function view_link(int $post_id): string
    {
        $url = get_permalink($post_id);

        if (!$url) {
            $url = get_preview_post_link($post_id);
        }

        return (string) $url;
    }

    /**
     * @return array<string, mixed>
     */
    private static function error_result(int $target_id, string $code, string $message): array
    {
        return [
            'target_blog_id' => $target_id,
            'target_name' => Sites::name($target_id),
            'action' => '',
            'post_id' => 0,
            'title' => '',
            'edit_link' => '',
            'view_link' => '',
            'status' => '',
            'warnings' => [],
            'error' => $message,
            'error_code' => $code,
        ];
    }
}
