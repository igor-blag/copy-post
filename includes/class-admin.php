<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Административный интерфейс.
 */
class Admin
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_footer-edit.php', [$this, 'render_modal']);
        add_filter('post_row_actions', [$this, 'row_action'], 10, 2);
        add_filter('page_row_actions', [$this, 'row_action'], 10, 2);
        add_filter(
            'network_admin_plugin_action_links_' . plugin_basename(COPY_POST_FILE),
            [$this, 'action_links']
        );

        add_action('init', [$this, 'register_type_hooks'], 20);
    }

    public function register_type_hooks(): void
    {
        foreach (Settings::post_types() as $type) {
            add_filter("bulk_actions-edit-{$type}", [$this, 'bulk_action']);
            add_filter("handle_bulk_actions-edit-{$type}", [$this, 'handle_bulk'], 10, 3);
            add_filter("manage_{$type}_posts_columns", [$this, 'columns']);
            add_action("manage_{$type}_posts_custom_column", [$this, 'column_content'], 10, 2);
            add_action("add_meta_boxes_{$type}", [$this, 'meta_boxes']);
        }
    }

    public function menu(): void
    {
        add_management_page(
            __('Копирование записей', 'copy-post'),
            __('Копирование записей', 'copy-post'),
            Settings::required_cap(),
            'copy-post',
            [$this, 'tools_page']
        );
    }

    public function action_links(array $links): array
    {
        $links[] = '<a href="' . esc_url(network_admin_url('settings.php?page=copy-post-settings')) . '">'
            . esc_html__('Настройки', 'copy-post') . '</a>';

        return $links;
    }

    public function enqueue(): void
    {
        $screen = get_current_screen();

        if (!$screen) {
            return;
        }

        $is_list = $screen->base === 'edit'
            && in_array((string) $screen->post_type, Settings::post_types(), true);
        $is_tools = $screen->id === 'tools_page_copy-post';

        if (!$is_list && !$is_tools) {
            return;
        }

        wp_enqueue_style(
            'copy-post-admin',
            COPY_POST_URL . 'assets/css/admin.css',
            [],
            COPY_POST_VERSION
        );

        wp_enqueue_script(
            'copy-post-admin',
            COPY_POST_URL . 'assets/js/admin.js',
            [],
            COPY_POST_VERSION,
            true
        );

        $settings = Settings::all();
        $defaults = [
            'copy_taxonomies' => (int) $settings['copy_taxonomies'],
            'copy_meta' => (int) $settings['copy_meta'],
            'copy_media' => (int) $settings['copy_media'],
            'copy_comments' => (int) $settings['copy_comments'],
            'copy_dates' => (int) $settings['copy_dates'],
            'copy_authors' => (int) $settings['copy_authors'],
            'copy_sticky' => (int) $settings['copy_sticky'],
            'rewrite_urls' => (int) $settings['rewrite_urls'],
            'update_slug' => (int) $settings['update_slug'],
            'default_status' => (string) $settings['default_status'],
            'duplicate_action' => (string) $settings['duplicate_action'],
        ];

        wp_localize_script('copy-post-admin', 'copyPostData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('copy_post_run'),
            'sites' => Sites::all(),
            'settings' => $defaults,
            'strings' => [
                'error' => __('Ошибка', 'copy-post'),
                'selectTarget' => __('Выберите хотя бы один целевой сайт.', 'copy-post'),
                'selectPost' => __('Выберите хотя бы одну запись.', 'copy-post'),
                'created' => __('Создана', 'copy-post'),
                'updated' => __('Обновлена', 'copy-post'),
                'skipped' => __('Пропущена', 'copy-post'),
                'copying' => __('Копирование…', 'copy-post'),
                'done' => __('Готово', 'copy-post'),
                'open' => __('Открыть', 'copy-post'),
                'view' => __('На сайте', 'copy-post'),
                'retry' => __('Повторить ошибки', 'copy-post'),
                'stop' => __('Остановить', 'copy-post'),
                'existing' => __('копия уже есть', 'copy-post'),
                'warnings' => __('Предупреждения', 'copy-post'),
                'noTargets' => __('Нет доступных целевых сайтов.', 'copy-post'),
                'stopped' => __('Остановлено', 'copy-post'),
                /* translators: 1: created count, 2: updated count, 3: skipped count, 4: error count. */
                'summary' => __('Создано: %1$d, обновлено: %2$d, пропущено: %3$d, ошибок: %4$d', 'copy-post'),
                'taxonomyLoading' => __('Загрузка таксономий…', 'copy-post'),
                'taxonomyError' => __('Не удалось загрузить таксономии.', 'copy-post'),
                'taxonomyEmpty' => __('У этого типа записи на целевом сайте нет таксономий.', 'copy-post'),
                'taxonomyNoTerms' => __('Терминов пока нет.', 'copy-post'),
                'taxonomySearch' => __('Поиск терминов…', 'copy-post'),
                'taxonomyCreate' => __('Создать', 'copy-post'),
                'taxonomyNewTerm' => __('Название нового термина', 'copy-post'),
                'taxonomyParent' => __('Родительский термин', 'copy-post'),
                'taxonomyTopLevel' => __('— верхний уровень —', 'copy-post'),
                'taxonomyClear' => __('Очищать невыбранные таксономии на этом сайте', 'copy-post'),
                'taxonomyClearHelp' => __('Если снять, таксономии без выбора останутся как на целевом сайте.', 'copy-post'),
                'taxonomyCopy' => __('Копировать с источника', 'copy-post'),
                'taxonomyManual' => __('Выбрать на сайте', 'copy-post'),
                'taxonomyNone' => __('Не копировать', 'copy-post'),
                'taxonomyCreated' => __('Термин добавлен в список.', 'copy-post'),
                'taxonomyNotLoaded' => __('Дождитесь загрузки таксономий для выбранных сайтов.', 'copy-post'),
            ],
        ]);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function row_action(array $actions, \WP_Post $post): array
    {
        if (!current_user_can(Settings::required_cap())) {
            return $actions;
        }

        if (!in_array($post->post_type, Settings::post_types(), true)) {
            return $actions;
        }

        if (in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
            return $actions;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $url = add_query_arg([
            'page' => 'copy-post',
            'post_id' => (int) $post->ID,
        ], admin_url('tools.php'));

        $actions['copy_post'] = sprintf(
            '<a href="%1$s" class="copy-post-open" data-post-id="%2$d" data-post-title="%3$s">%4$s</a>',
            esc_url($url),
            (int) $post->ID,
            esc_attr(get_the_title($post)),
            esc_html__('Копировать на сайт…', 'copy-post')
        );

        return $actions;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function bulk_action(array $actions): array
    {
        if (!current_user_can(Settings::required_cap())) {
            return $actions;
        }

        $actions['copy_post'] = __('Копировать на сайт…', 'copy-post');

        return $actions;
    }

    public function handle_bulk(string $redirect, string $action, array $post_ids): string
    {
        if ($action !== 'copy_post') {
            return $redirect;
        }

        if (!current_user_can(Settings::required_cap())) {
            return $redirect;
        }

        $allowed = [];

        foreach (array_unique(array_map('absint', $post_ids)) as $post_id) {
            $post = get_post($post_id);

            if (!$post) {
                continue;
            }

            if (!in_array($post->post_type, Settings::post_types(), true)) {
                continue;
            }

            if (in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }

            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $allowed[] = $post_id;
        }

        if ($allowed === []) {
            return add_query_arg([
                'page' => 'copy-post',
                'copy_post_notice' => 'empty',
            ], admin_url('tools.php'));
        }

        usort($allowed, static function (int $a, int $b): int {
            $post_a = get_post($a);
            $post_b = get_post($b);

            if (!$post_a || !$post_b) {
                return $a <=> $b;
            }

            if ($post_a->post_type !== $post_b->post_type) {
                return strcmp($post_a->post_type, $post_b->post_type);
            }

            $depth_a = count(get_post_ancestors($post_a));
            $depth_b = count(get_post_ancestors($post_b));

            if ($depth_a !== $depth_b) {
                return $depth_a <=> $depth_b;
            }

            return $a <=> $b;
        });

        $token = sanitize_key(wp_generate_password(20, false));
        $key = 'copy_post_batch_' . get_current_user_id() . '_' . $token;

        set_transient($key, $allowed, HOUR_IN_SECONDS);

        return add_query_arg([
            'page' => 'copy-post',
            'batch' => $token,
        ], admin_url('tools.php'));
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out['copy_post_links'] = __('Связи', 'copy-post');
            }
        }

        if (!isset($out['copy_post_links'])) {
            $out['copy_post_links'] = __('Связи', 'copy-post');
        }

        return $out;
    }

    public function column_content(string $column, int $post_id): void
    {
        if ($column === 'copy_post_links') {
            echo $this->links_html($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function meta_boxes(\WP_Post $post): void
    {
        $origin_blog = (int) get_post_meta($post->ID, '_copy_post_source_blog_id', true);
        $copies = get_post_meta($post->ID, '_copy_post_copies', true);

        if ($origin_blog > 0) {
            add_meta_box(
                'copy-post-source',
                __('Источник', 'copy-post'),
                [$this, 'render_source_box'],
                $post->post_type,
                'side',
                'default'
            );
        }

        if (is_array($copies) && $copies !== []) {
            add_meta_box(
                'copy-post-copies',
                __('Копии на других сайтах', 'copy-post'),
                [$this, 'render_copies_box'],
                $post->post_type,
                'side',
                'default'
            );
        }
    }

    public function render_source_box(\WP_Post $post): void
    {
        $blog_id = (int) get_post_meta($post->ID, '_copy_post_source_blog_id', true);
        $source_id = (int) get_post_meta($post->ID, '_copy_post_source_post_id', true);
        $source_url = (string) get_post_meta($post->ID, '_copy_post_source_url', true);
        $source_title = (string) get_post_meta($post->ID, '_copy_post_source_title', true);
        $copied_at = (string) get_post_meta($post->ID, '_copy_post_copied_at', true);

        if ($blog_id <= 0 || $source_id <= 0) {
            echo '<p>' . esc_html__('Данные источника отсутствуют.', 'copy-post') . '</p>';
            return;
        }

        $site_name = Sites::name($blog_id);
        $admin_url = Sites::admin_edit_url($blog_id, $source_id);
        ?>
        <p><strong><?php echo esc_html__('Сайт:', 'copy-post'); ?></strong> <?php echo esc_html($site_name); ?></p>
        <p>
            <strong><?php echo esc_html__('Запись:', 'copy-post'); ?></strong>
            <a href="<?php echo esc_url($source_url ?: $admin_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($source_title ?: ('#' . $source_id)); ?>
            </a>
        </p>
        <?php if ($copied_at !== '') : ?>
            <p><strong><?php echo esc_html__('Скопирована:', 'copy-post'); ?></strong> <?php echo esc_html($copied_at); ?></p>
        <?php endif; ?>
        <p>
            <a class="button button-small" href="<?php echo esc_url($admin_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html__('Открыть на исходном сайте', 'copy-post'); ?>
            </a>
        </p>
        <?php
    }

    public function render_copies_box(\WP_Post $post): void
    {
        $copies = get_post_meta($post->ID, '_copy_post_copies', true);

        if (!is_array($copies) || $copies === []) {
            echo '<p>' . esc_html__('Копий пока нет.', 'copy-post') . '</p>';
        } else {
            echo '<ul class="copy-post-copies-list">';

            foreach ($copies as $blog_id => $copy) {
                $blog_id = (int) $blog_id;
                $copy_id = isset($copy['post_id']) ? (int) $copy['post_id'] : 0;

                if ($blog_id <= 0 || $copy_id <= 0) {
                    continue;
                }

                $site_name = Sites::name($blog_id);
                $edit_url = Sites::admin_edit_url($blog_id, $copy_id);
                $status = (string) ($copy['status'] ?? '');
                $status_object = get_post_status_object($status);
                $status_label = $status_object ? $status_object->label : $status;
                $date = (string) ($copy['date'] ?? '');

                echo '<li>';
                echo '<a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener">' . esc_html($site_name) . '</a>';

                if ($status_label !== '') {
                    echo ' — ' . esc_html($status_label);
                }

                if ($date !== '') {
                    echo '<br><small>' . esc_html($date) . '</small>';
                }

                echo '</li>';
            }

            echo '</ul>';
        }

        $url = add_query_arg([
            'page' => 'copy-post',
            'post_id' => (int) $post->ID,
        ], admin_url('tools.php'));
        ?>
        <p>
            <a class="button button-small" href="<?php echo esc_url($url); ?>">
                <?php echo esc_html__('Копировать на сайт…', 'copy-post'); ?>
            </a>
        </p>
        <?php
    }

    public function render_modal(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->base !== 'edit' || !in_array((string) $screen->post_type, Settings::post_types(), true)) {
            return;
        }

        if (!current_user_can(Settings::required_cap())) {
            return;
        }
        ?>
        <dialog id="copy-post-modal" class="copy-post-modal">
            <div class="copy-post-modal__inner">
                <header class="copy-post-modal__header">
                    <div>
                        <h2 id="copy-post-modal-title"><?php echo esc_html__('Копировать на сайт', 'copy-post'); ?></h2>
                        <p class="copy-post-modal__subtitle"></p>
                    </div>
                    <button type="button" class="copy-post-modal__close copy-post-close" aria-label="<?php echo esc_attr__('Закрыть', 'copy-post'); ?>">&times;</button>
                </header>
                <?php $this->render_panel([], 'modal', (string) $screen->post_type); ?>
            </div>
        </dialog>
        <?php
    }

    public function tools_page(): void
    {
        if (!current_user_can(Settings::required_cap())) {
            wp_die(esc_html__('Недостаточно прав для копирования записей.', 'copy-post'));
        }

        $notice = isset($_GET['copy_post_notice'])
            ? sanitize_key(wp_unslash($_GET['copy_post_notice']))
            : '';
        $batch = isset($_GET['batch']) ? sanitize_key(wp_unslash($_GET['batch'])) : '';
        $single_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $post_ids = [];
        $show_table = true;

        if ($batch !== '') {
            $key = 'copy_post_batch_' . get_current_user_id() . '_' . $batch;
            $stored = get_transient($key);

            if (is_array($stored)) {
                $post_ids = array_values(array_filter(array_map('absint', $stored)));
            } else {
                $notice = 'expired';
            }

            $show_table = false;
        } elseif ($single_id > 0) {
            $post = get_post($single_id);

            if (
                $post
                && in_array($post->post_type, Settings::post_types(), true)
                && !in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)
                && current_user_can('edit_post', $single_id)
            ) {
                $post_ids = [$single_id];
                $show_table = false;
            } else {
                $notice = 'invalid';
            }
        }

        if (!$show_table) {
            $post_ids = $this->filter_posts($post_ids);

            if ($post_ids === [] && $notice === '') {
                $notice = 'expired';
            }
        }

        $panel_post_type = '';

        if ($post_ids !== []) {
            $panel_post_type = (string) get_post_type($post_ids[0]);
        } else {
            $types = Settings::post_types();
            $requested_type = isset($_GET['cp_type']) ? sanitize_key(wp_unslash($_GET['cp_type'])) : '';
            $panel_post_type = in_array($requested_type, $types, true)
                ? $requested_type
                : ($types[0] ?? 'post');
        }
        ?>
        <div class="wrap copy-post-tools">
            <h1><?php echo esc_html__('Копирование записей', 'copy-post'); ?></h1>
            <p>
                <?php echo esc_html__('Скопируйте записи и страницы текущего сайта на другие сайты сети. Перед запуском можно выбрать сайты и состав данных.', 'copy-post'); ?>
            </p>

            <?php $this->print_notice($notice); ?>

            <?php if (!$show_table && $post_ids !== []) : ?>
                <h2><?php echo esc_html__('Выбранные записи', 'copy-post'); ?></h2>
                <ul class="copy-post-selected-list">
                    <?php foreach ($post_ids as $post_id) : ?>
                        <li>
                            <a href="<?php echo esc_url((string) get_edit_post_link($post_id)); ?>">
                                <?php echo esc_html(get_the_title($post_id) ?: ('#' . $post_id)); ?>
                            </a>
                            <span class="copy-post-selected-list__type">
                                <?php
                                $type_object = get_post_type_object((string) get_post_type($post_id));
                                $type_label = $type_object ? $type_object->labels->singular_name : (string) get_post_type($post_id);
                                echo esc_html($type_label . ' (' . get_post_type($post_id) . ')');
                                ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <a href="<?php echo esc_url(admin_url('tools.php?page=copy-post')); ?>">
                        <?php echo esc_html__('Выбрать другие записи', 'copy-post'); ?>
                    </a>
                </p>
                <?php $this->render_panel($post_ids, 'page', $panel_post_type); ?>
            <?php else : ?>
                <?php $this->render_filters(); ?>
                <?php $this->render_table(); ?>
                <?php $this->render_panel([], 'page', $panel_post_type); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function print_notice(string $notice): void
    {
        if ($notice === '') {
            return;
        }

        $messages = [
            'empty' => [__('Не выбрано ни одной записи для копирования.', 'copy-post'), 'warning'],
            'expired' => [__('Выбор записей устарел. Выберите записи заново.', 'copy-post'), 'warning'],
            'invalid' => [__('Запись недоступна для копирования.', 'copy-post'), 'error'],
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        [$message, $type] = $messages[$notice];
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    private function render_filters(): void
    {
        $types = Settings::post_types();
        $current_type = isset($_GET['cp_type'])
            ? sanitize_key(wp_unslash($_GET['cp_type']))
            : ($types[0] ?? 'post');

        if (!in_array($current_type, $types, true)) {
            $current_type = $types[0] ?? 'post';
        }

        $statuses = [
            'any' => __('Все статусы', 'copy-post'),
            'publish' => __('Опубликованные', 'copy-post'),
            'draft' => __('Черновики', 'copy-post'),
            'pending' => __('На рассмотрении', 'copy-post'),
            'private' => __('Личные', 'copy-post'),
            'future' => __('Запланированные', 'copy-post'),
        ];
        $current_status = isset($_GET['cp_status'])
            ? sanitize_key(wp_unslash($_GET['cp_status']))
            : 'any';

        if (!isset($statuses[$current_status])) {
            $current_status = 'any';
        }

        $search = isset($_GET['cp_s']) ? sanitize_text_field(wp_unslash($_GET['cp_s'])) : '';
        ?>
        <form method="get" class="copy-post-filters">
            <input type="hidden" name="page" value="copy-post">
            <label>
                <span class="screen-reader-text"><?php echo esc_html__('Тип записи', 'copy-post'); ?></span>
                <select name="cp_type">
                    <?php foreach ($types as $type) : ?>
                        <?php $object = get_post_type_object($type); ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($current_type, $type); ?>>
                            <?php
                            $type_label = $object ? $object->labels->singular_name : $type;
                            echo esc_html($type_label . ' (' . $type . ')');
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="screen-reader-text"><?php echo esc_html__('Статус', 'copy-post'); ?></span>
                <select name="cp_status">
                    <?php foreach ($statuses as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="screen-reader-text"><?php echo esc_html__('Поиск', 'copy-post'); ?></span>
                <input type="search" name="cp_s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Поиск записей…', 'copy-post'); ?>">
            </label>

            <?php submit_button(__('Фильтровать', 'copy-post'), 'secondary', '', false); ?>
        </form>
        <?php
    }

    private function render_table(): void
    {
        $types = Settings::post_types();
        $current_type = isset($_GET['cp_type'])
            ? sanitize_key(wp_unslash($_GET['cp_type']))
            : ($types[0] ?? 'post');

        if (!in_array($current_type, $types, true)) {
            $current_type = $types[0] ?? 'post';
        }

        $current_status = isset($_GET['cp_status'])
            ? sanitize_key(wp_unslash($_GET['cp_status']))
            : 'any';

        if (!in_array($current_status, ['any', 'publish', 'draft', 'pending', 'private', 'future'], true)) {
            $current_status = 'any';
        }

        $search = isset($_GET['cp_s']) ? sanitize_text_field(wp_unslash($_GET['cp_s'])) : '';
        $paged = isset($_GET['cp_paged']) ? max(1, absint($_GET['cp_paged'])) : 1;
        $args = [
            'post_type' => $current_type,
            'post_status' => $current_status,
            's' => $search,
            'paged' => $paged,
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
        ];

        if (!current_user_can('edit_others_posts')) {
            $args['author'] = get_current_user_id();
        }

        $query = new \WP_Query($args);
        $posts = array_values(array_filter((array) $query->posts, static function ($post): bool {
            return $post instanceof \WP_Post && current_user_can('edit_post', $post->ID);
        }));
        ?>
        <div class="copy-post-table-wrap">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" class="copy-post-select-all-posts" aria-label="<?php echo esc_attr__('Выбрать все записи', 'copy-post'); ?>">
                        </td>
                        <th scope="col"><?php echo esc_html__('Заголовок', 'copy-post'); ?></th>
                        <th scope="col"><?php echo esc_html__('Тип', 'copy-post'); ?></th>
                        <th scope="col"><?php echo esc_html__('Статус', 'copy-post'); ?></th>
                        <th scope="col"><?php echo esc_html__('Дата', 'copy-post'); ?></th>
                        <th scope="col"><?php echo esc_html__('Связи', 'copy-post'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($posts === []) : ?>
                        <tr>
                            <td colspan="6"><?php echo esc_html__('Записей не найдено.', 'copy-post'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($posts as $post) : ?>
                            <?php
                            $type_object = get_post_type_object($post->post_type);
                            $status_object = get_post_status_object($post->post_status);
                            $depth = count(get_post_ancestors($post));
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox"
                                           class="copy-post-post-checkbox"
                                           name="copy_post_ids[]"
                                           value="<?php echo esc_attr((string) $post->ID); ?>"
                                           data-depth="<?php echo esc_attr((string) $depth); ?>"
                                           aria-label="<?php echo esc_attr(sprintf(
                                               /* translators: %s: post title. */
                                               __('Выбрать «%s»', 'copy-post'),
                                               get_the_title($post)
                                           )); ?>">
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url((string) get_edit_post_link($post->ID)); ?>">
                                            <?php echo esc_html(get_the_title($post) ?: ('#' . $post->ID)); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html(($type_object ? $type_object->labels->singular_name : $post->post_type) . ' (' . $post->post_type . ')'); ?></td>
                                <td><?php echo esc_html($status_object ? $status_object->label : $post->post_status); ?></td>
                                <td><?php echo esc_html(mysql2date((string) get_option('date_format'), $post->post_date)); ?></td>
                                <td><?php echo $this->links_html((int) $post->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php

        if ($query->max_num_pages > 1) {
            $base = add_query_arg(array_filter([
                'page' => 'copy-post',
                'cp_type' => $current_type,
                'cp_status' => $current_status,
                'cp_s' => $search,
            ], static function ($value): bool {
                return $value !== '' && $value !== null;
            }), admin_url('tools.php'));
            $base = add_query_arg('cp_paged', '%#%', $base);

            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base' => $base,
                'format' => '',
                'current' => $paged,
                'total' => (int) $query->max_num_pages,
                'prev_text' => '‹',
                'next_text' => '›',
                'type' => 'plain',
            ]));
            echo '</div></div>';
        }
    }

    /**
     * @param int[] $post_ids
     */
    private function render_panel(array $post_ids, string $mode, string $post_type = ''): void
    {
        $targets = Sites::all();
        $settings = Settings::all();
        $post_ids = array_values(array_filter(array_map('absint', $post_ids)));

        if ($post_type === '') {
            $post_type = Settings::post_types()[0] ?? 'post';
        }

        include COPY_POST_DIR . 'templates/copy-panel.php';
    }

    /**
     * @param int[] $post_ids
     * @return int[]
     */
    private function filter_posts(array $post_ids): array
    {
        $allowed = [];

        foreach (array_filter(array_map('absint', $post_ids)) as $post_id) {
            $post = get_post($post_id);

            if (!$post) {
                continue;
            }

            if (!in_array($post->post_type, Settings::post_types(), true)) {
                continue;
            }

            if (in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }

            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $allowed[] = $post_id;
        }

        return array_values(array_unique($allowed));
    }

    private function links_html(int $post_id): string
    {
        $parts = [];
        $origin_blog = (int) get_post_meta($post_id, '_copy_post_source_blog_id', true);
        $origin_post = (int) get_post_meta($post_id, '_copy_post_source_post_id', true);

        if ($origin_blog > 0 && $origin_post > 0) {
            $parts[] = sprintf(
                '<span class="copy-post-column__source">← <a href="%1$s">%2$s</a></span>',
                esc_url(Sites::admin_edit_url($origin_blog, $origin_post)),
                esc_html(Sites::name($origin_blog))
            );
        }

        $copies = get_post_meta($post_id, '_copy_post_copies', true);

        if (is_array($copies) && $copies !== []) {
            $links = [];
            $count = 0;

            foreach ($copies as $blog_id => $copy) {
                $blog_id = (int) $blog_id;
                $copy_id = isset($copy['post_id']) ? (int) $copy['post_id'] : 0;

                if ($blog_id <= 0 || $copy_id <= 0) {
                    continue;
                }

                $count++;

                if ($count > 3) {
                    continue;
                }

                $links[] = sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(Sites::admin_edit_url($blog_id, $copy_id)),
                    esc_html(Sites::name($blog_id))
                );
            }

            if ($links !== []) {
                $suffix = $count > 3 ? ' +' . ($count - 3) : '';
                $parts[] = '<span class="copy-post-column__copies">→ ' . implode(', ', $links) . $suffix . '</span>';
            }
        }

        return $parts === [] ? '—' : implode('<br>', $parts);
    }
}
