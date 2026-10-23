<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * WP-CLI команды.
 */
class Cli
{
    public function init(): void
    {
        \WP_CLI::add_command('copy-post', $this);
    }

    /**
     * Копирует запись на один или несколько сайтов сети.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : ID записи-источника.
     *
     * --to=<blog_ids>
     * : ID целевых сайтов через запятую.
     *
     * [--status=<status>]
     * : draft, same или publish. По умолчанию draft.
     *
     * [--duplicate=<action>]
     * : new, update или skip. По умолчанию new.
     *
     * [--update]
     * : Обновить существующую копию (то же, что --duplicate=update).
     *
     * [--skip-existing]
     * : Пропустить, если копия уже есть.
     *
     * [--skip-media]
     * : Не копировать медиа.
     *
     * [--skip-meta]
     * : Не копировать произвольные поля.
     *
     * [--skip-taxonomies]
     * : Не копировать таксономии.
     *
     * [--skip-rewrite]
     * : Не переписывать ссылки исходного сайта.
     *
     * [--skip-dates]
     * : Не копировать дату публикации.
     *
     * [--skip-authors]
     * : Не копировать автора.
     *
     * [--comments]
     * : Копировать комментарии.
     *
     * [--update-slug]
     * : Обновлять слаг при обновлении копии.
     *
     * [--user=<id>]
     * : Пользователь, от имени которого выполняется копирование. По умолчанию 1.
     *
     * [--porcelain]
     * : Вывести только ID созданных/обновлённых записей.
     *
     * ## EXAMPLES
     *
     *     wp copy-post copy 123 --to=2 --status=draft
     *     wp copy-post copy 123 --to=2,3 --duplicate=update
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function copy(array $args, array $assoc_args): void
    {
        $post_id = isset($args[0]) ? absint($args[0]) : 0;

        if ($post_id <= 0) {
            \WP_CLI::error('Укажите ID записи: wp copy-post copy <post_id> --to=<blog_id>.');
        }

        if (empty($assoc_args['to'])) {
            \WP_CLI::error('Укажите целевые сайты через --to=<blog_id>[,<blog_id>].');
        }

        $target_ids = array_values(array_filter(array_map('absint', explode(',', (string) $assoc_args['to']))));

        if ($target_ids === []) {
            \WP_CLI::error('Не удалось разобрать список целевых сайтов.');
        }

        $user_id = isset($assoc_args['user']) ? absint($assoc_args['user']) : 1;

        if (!get_userdata($user_id)) {
            \WP_CLI::error(sprintf('Пользователь #%d не найден.', $user_id));
        }

        wp_set_current_user($user_id);

        $duplicate = isset($assoc_args['duplicate']) ? sanitize_key((string) $assoc_args['duplicate']) : 'new';

        if (isset($assoc_args['update'])) {
            $duplicate = 'update';
        } elseif (isset($assoc_args['skip-existing'])) {
            $duplicate = 'skip';
        }

        $status = isset($assoc_args['status']) ? sanitize_key((string) $assoc_args['status']) : 'draft';

        $options = [
            'duplicate' => $duplicate,
            'status' => $status,
            'copy_media' => !isset($assoc_args['skip-media']),
            'copy_meta' => !isset($assoc_args['skip-meta']),
            'copy_taxonomies' => !isset($assoc_args['skip-taxonomies']),
            'rewrite_urls' => !isset($assoc_args['skip-rewrite']),
            'copy_dates' => !isset($assoc_args['skip-dates']),
            'copy_authors' => !isset($assoc_args['skip-authors']),
            'copy_comments' => isset($assoc_args['comments']),
            'copy_sticky' => !isset($assoc_args['skip-sticky']),
            'update_slug' => isset($assoc_args['update-slug']),
        ];

        $result = Copier::copy($post_id, $target_ids, $options);

        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        $rows = [];
        $ids = [];

        foreach ((array) $result['results'] as $item) {
            if (!empty($item['error'])) {
                $rows[] = [
                    'target' => $item['target_name'] ?: ('#' . $item['target_blog_id']),
                    'action' => 'error',
                    'post_id' => '',
                    'status' => '',
                    'message' => $item['error'],
                ];
                continue;
            }

            $rows[] = [
                'target' => $item['target_name'],
                'action' => $item['action'],
                'post_id' => $item['post_id'],
                'status' => $item['status'],
                'message' => $item['edit_link'],
            ];

            $ids[] = (int) $item['post_id'];

            foreach ((array) $item['warnings'] as $warning) {
                \WP_CLI::warning($warning);
            }
        }

        if (isset($assoc_args['porcelain'])) {
            foreach ($ids as $id) {
                \WP_CLI::line((string) $id);
            }
        } else {
            \WP_CLI\Utils\format_items('table', $rows, ['target', 'action', 'post_id', 'status', 'message']);
        }

        $errors = count(array_filter((array) $result['results'], static function (array $item): bool {
            return !empty($item['error']);
        }));

        if ($errors > 0) {
            \WP_CLI::error(sprintf('Завершено с ошибками: %d.', $errors));
        }

        \WP_CLI::success('Копирование завершено.');
    }

    /**
     * Список сайтов сети.
     *
     * ## EXAMPLES
     *
     *     wp copy-post sites
     *
     * @subcommand sites
     */
    public function sites(): void
    {
        $sites = get_sites([
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
            'number' => 0,
            'orderby' => 'path',
            'order' => 'ASC',
        ]);
        $rows = [];

        foreach ($sites as $site) {
            $blog_id = (int) $site->blog_id;
            $rows[] = [
                'blog_id' => $blog_id,
                'name' => Sites::name($blog_id),
                'url' => Sites::url($blog_id),
                'current' => $blog_id === get_current_blog_id() ? 'yes' : '',
            ];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['blog_id', 'name', 'url', 'current']);
    }

    /**
     * Список записей сайта.
     *
     * ## OPTIONS
     *
     * [--site=<blog_id>]
     * : ID сайта. По умолчанию текущий.
     *
     * [--post-type=<type>]
     * : Тип записи. По умолчанию post.
     *
     * ## EXAMPLES
     *
     *     wp copy-post posts --site=2 --post-type=post
     *
     * @subcommand posts
     */
    public function posts(array $args, array $assoc_args): void
    {
        $site_id = isset($assoc_args['site']) ? absint($assoc_args['site']) : get_current_blog_id();
        $post_type = isset($assoc_args['post-type']) ? sanitize_key((string) $assoc_args['post-type']) : 'post';

        switch_to_blog($site_id);

        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $rows = [];

        foreach ($posts as $post) {
            $rows[] = [
                'ID' => $post->ID,
                'title' => get_the_title($post),
                'status' => $post->post_status,
                'type' => $post->post_type,
            ];
        }

        restore_current_blog();

        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'title', 'status', 'type']);
    }
}
