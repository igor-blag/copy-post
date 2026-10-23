<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Настройки сети.
 */
class Settings
{
    public const OPTION = 'copy_post_settings';

    public function init(): void
    {
        add_action('network_admin_menu', [$this, 'menu']);
        add_action('admin_post_copy_post_save_settings', [$this, 'save']);
    }

    public static function defaults(): array
    {
        return [
            'post_types' => ['post', 'page'],
            'copy_taxonomies' => 1,
            'copy_meta' => 1,
            'copy_media' => 1,
            'copy_comments' => 0,
            'copy_dates' => 1,
            'copy_authors' => 1,
            'copy_sticky' => 1,
            'rewrite_urls' => 1,
            'update_slug' => 0,
            'skip_protected_meta' => 0,
            'default_status' => 'draft',
            'duplicate_action' => 'new',
            'required_cap' => 'edit_posts',
            'excluded_meta_keys' => implode("\n", [
                '_edit_lock',
                '_edit_last',
                '_wp_old_slug',
                '_wp_old_date',
                '_wp_attached_file',
                '_wp_attachment_metadata',
                '_wp_attachment_backup_sizes',
                '_wp_page_template',
                '_encloseme',
                '_pingme',
                '_wp_desired_post_slug',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
                '_copy_post_origin',
                '_copy_post_source_blog_id',
                '_copy_post_source_post_id',
                '_copy_post_source_url',
                '_copy_post_source_title',
                '_copy_post_copied_at',
                '_copy_post_meta_keys',
                '_copy_post_copies',
            ]),
            'delete_data_on_uninstall' => 0,
        ];
    }

    public static function all(): array
    {
        $stored = get_site_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, self::defaults());
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $settings = self::all();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function post_types(): array
    {
        $available = array_keys(self::available_post_types());
        $stored = (array) self::get('post_types', []);
        $enabled = array_values(array_intersect($stored, $available));

        if ($enabled !== []) {
            return $enabled;
        }

        if ($stored === []) {
            return array_values(array_intersect(['post', 'page'], $available));
        }

        return [];
    }

    public static function available_post_types(): array
    {
        $objects = get_post_types(['public' => true], 'objects');
        $excluded = [
            'attachment',
            'revision',
            'nav_menu_item',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
        ];

        $available = [];

        foreach ($objects as $name => $object) {
            if (in_array($name, $excluded, true) || empty($object->show_ui)) {
                continue;
            }

            $available[$name] = $object;
        }

        return $available;
    }

    public static function excluded_keys(): array
    {
        $raw = (string) self::get('excluded_meta_keys', '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $keys = [];

        foreach ((array) $lines as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $keys[] = $line;
            }
        }

        return array_values(array_unique($keys));
    }

    public static function required_cap(): string
    {
        $allowed = ['edit_posts', 'edit_others_posts', 'publish_posts', 'manage_options'];
        $cap = (string) self::get('required_cap', 'edit_posts');

        return in_array($cap, $allowed, true) ? $cap : 'edit_posts';
    }

    public function save(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Недостаточно прав для изменения настроек.', 'copy-post'), 403);
        }

        check_admin_referer('copy_post_save_settings', 'copy_post_nonce');

        $input = isset($_POST['copy_post_settings']) && is_array($_POST['copy_post_settings'])
            ? wp_unslash($_POST['copy_post_settings'])
            : [];
        $settings = self::sanitize($input);

        update_site_option(self::OPTION, $settings);

        wp_safe_redirect(add_query_arg([
            'page' => 'copy-post-settings',
            'updated' => '1',
        ], network_admin_url('settings.php')));
        exit;
    }

    public function menu(): void
    {
        add_submenu_page(
            'settings.php',
            __('Копирование записей', 'copy-post'),
            __('Копирование записей', 'copy-post'),
            'manage_network_options',
            'copy-post-settings',
            [$this, 'render']
        );
    }

    /**
     * @param mixed $input
     */
    public static function sanitize($input): array
    {
        $defaults = self::defaults();
        $input = is_array($input) ? $input : [];
        $out = $defaults;

        $available = array_keys(self::available_post_types());
        $types = [];

        if (isset($input['post_types']) && is_array($input['post_types'])) {
            foreach ($input['post_types'] as $type) {
                if (is_string($type)) {
                    $types[] = sanitize_key($type);
                }
            }
        }

        $types = array_values(array_intersect($types, $available));

        if ($types === []) {
            $types = ['post'];
        }

        $out['post_types'] = $types;

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
            'skip_protected_meta',
            'delete_data_on_uninstall',
        ];

        foreach ($booleans as $key) {
            $out[$key] = empty($input[$key]) ? 0 : 1;
        }

        $status = isset($input['default_status']) && is_string($input['default_status'])
            ? sanitize_key($input['default_status'])
            : 'draft';
        $out['default_status'] = in_array($status, ['draft', 'same'], true) ? $status : 'draft';

        $duplicate = isset($input['duplicate_action']) && is_string($input['duplicate_action'])
            ? sanitize_key($input['duplicate_action'])
            : 'new';
        $out['duplicate_action'] = in_array($duplicate, ['new', 'update', 'skip'], true) ? $duplicate : 'new';

        $cap = isset($input['required_cap']) && is_string($input['required_cap'])
            ? sanitize_key($input['required_cap'])
            : 'edit_posts';
        $allowed_caps = ['edit_posts', 'edit_others_posts', 'publish_posts', 'manage_options'];
        $out['required_cap'] = in_array($cap, $allowed_caps, true) ? $cap : 'edit_posts';

        $keys = isset($input['excluded_meta_keys']) && is_string($input['excluded_meta_keys'])
            ? $input['excluded_meta_keys']
            : '';
        $out['excluded_meta_keys'] = self::sanitize_meta_keys($keys);

        return $out;
    }

    private static function sanitize_meta_keys(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $keys = [];

        foreach ((array) $lines as $line) {
            $line = trim(sanitize_text_field((string) $line));

            if ($line !== '') {
                $keys[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($keys)));
    }

    public function render(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        $settings = self::all();
        $types = self::available_post_types();
        $caps = [
            'edit_posts' => __('Редактор и выше (edit_posts)', 'copy-post'),
            'edit_others_posts' => __('Редактор чужих записей (edit_others_posts)', 'copy-post'),
            'publish_posts' => __('Публикация записей (publish_posts)', 'copy-post'),
            'manage_options' => __('Администратор сайта (manage_options)', 'copy-post'),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Копирование записей', 'copy-post'); ?></h1>
            <p><?php echo esc_html__('Настройки плагина Copy Post для всей сети. Отдельные сайты могут запускать копирование из раздела «Инструменты → Копирование записей».', 'copy-post'); ?></p>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === '1') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Настройки сохранены.', 'copy-post'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="copy_post_save_settings">
                <?php wp_nonce_field('copy_post_save_settings', 'copy_post_nonce'); ?>

                <h2><?php echo esc_html__('Типы записей', 'copy-post'); ?></h2>
                <p class="description"><?php echo esc_html__('Для выбранных типов в списках появляются действия копирования.', 'copy-post'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Доступные типы', 'copy-post'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ($types as $name => $object) : ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox"
                                               name="copy_post_settings[post_types][]"
                                               value="<?php echo esc_attr($name); ?>"
                                            <?php checked(in_array($name, (array) $settings['post_types'], true)); ?>>
                                        <?php echo esc_html($object->labels->singular_name); ?>
                                        <code><?php echo esc_html($name); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Что копировать по умолчанию', 'copy-post'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Данные', 'copy-post'); ?></th>
                        <td>
                            <fieldset>
                                <?php
                                $this->checkbox($settings, 'copy_taxonomies', __('Копировать таксономии с источника по умолчанию', 'copy-post'));
                                $this->checkbox($settings, 'copy_meta', __('Произвольные поля (мета)', 'copy-post'));
                                $this->checkbox($settings, 'copy_media', __('Медиа: миниатюра и файлы из контента/меты', 'copy-post'));
                                $this->checkbox($settings, 'copy_comments', __('Комментарии (только при создании копии)', 'copy-post'));
                                $this->checkbox($settings, 'copy_dates', __('Дата и время публикации', 'copy-post'));
                                $this->checkbox($settings, 'copy_authors', __('Автор (с проверкой участия в целевом сайте)', 'copy-post'));
                                $this->checkbox($settings, 'copy_sticky', __('Закрепление записи (sticky)', 'copy-post'));
                                $this->checkbox($settings, 'rewrite_urls', __('Переписывать ссылки исходного сайта на целевой', 'copy-post'));
                                ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Поведение', 'copy-post'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="copy-post-default-status"><?php echo esc_html__('Статус копии', 'copy-post'); ?></label></th>
                        <td>
                            <select id="copy-post-default-status" name="copy_post_settings[default_status]">
                                <option value="draft" <?php selected($settings['default_status'], 'draft'); ?>><?php echo esc_html__('Черновик', 'copy-post'); ?></option>
                                <option value="same" <?php selected($settings['default_status'], 'same'); ?>><?php echo esc_html__('Как у оригинала', 'copy-post'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="copy-post-duplicate"><?php echo esc_html__('Если копия уже есть', 'copy-post'); ?></label></th>
                        <td>
                            <select id="copy-post-duplicate" name="copy_post_settings[duplicate_action]">
                                <option value="new" <?php selected($settings['duplicate_action'], 'new'); ?>><?php echo esc_html__('Создавать новую', 'copy-post'); ?></option>
                                <option value="update" <?php selected($settings['duplicate_action'], 'update'); ?>><?php echo esc_html__('Обновлять существующую', 'copy-post'); ?></option>
                                <option value="skip" <?php selected($settings['duplicate_action'], 'skip'); ?>><?php echo esc_html__('Пропускать', 'copy-post'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Значение по умолчанию; в окне копирования его можно изменить.', 'copy-post'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Слаг при обновлении', 'copy-post'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="copy_post_settings[update_slug]" value="1" <?php checked($settings['update_slug']); ?>>
                                <?php echo esc_html__('Обновлять слаг копии при изменении слага источника', 'copy-post'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('По умолчанию слаг копии сохраняется, чтобы не ломать ссылки.', 'copy-post'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Защищённая мета', 'copy-post'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="copy_post_settings[skip_protected_meta]" value="1" <?php checked($settings['skip_protected_meta']); ?>>
                                <?php echo esc_html__('Не копировать мета-поля, начинающиеся с «_»', 'copy-post'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Поля медиа и миниатюра обрабатываются отдельно и не теряются.', 'copy-post'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="copy-post-required-cap"><?php echo esc_html__('Кому доступно', 'copy-post'); ?></label></th>
                        <td>
                            <select id="copy-post-required-cap" name="copy_post_settings[required_cap]">
                                <?php foreach ($caps as $cap => $label) : ?>
                                    <option value="<?php echo esc_attr($cap); ?>" <?php selected($settings['required_cap'], $cap); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Дополнительно пользователь должен иметь право редактировать конкретную запись и быть участником целевого сайта (кроме супер-админа).', 'copy-post'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Исключаемые мета-поля', 'copy-post'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="copy-post-excluded-meta"><?php echo esc_html__('Ключи', 'copy-post'); ?></label></th>
                        <td>
                            <textarea id="copy-post-excluded-meta"
                                      name="copy_post_settings[excluded_meta_keys]"
                                      rows="8"
                                      class="large-text code"><?php echo esc_textarea((string) $settings['excluded_meta_keys']); ?></textarea>
                            <p class="description"><?php echo esc_html__('По одному ключу в строке. Можно указать шаблон с «*» в конце, например: _elementor_*', 'copy-post'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Удаление', 'copy-post'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Данные плагина', 'copy-post'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="copy_post_settings[delete_data_on_uninstall]" value="1" <?php checked($settings['delete_data_on_uninstall']); ?>>
                                <?php echo esc_html__('Удалять служебную мету связей копий при удалении плагина', 'copy-post'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Сами записи, страницы и медиафайлы не удаляются никогда.', 'copy-post'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Сохранить настройки', 'copy-post')); ?>
            </form>
        </div>
        <?php
    }

    private function checkbox(array $settings, string $key, string $label): void
    {
        ?>
        <label style="display:block;margin-bottom:6px;">
            <input type="checkbox"
                   name="copy_post_settings[<?php echo esc_attr($key); ?>]"
                   value="1"
                <?php checked(!empty($settings[$key])); ?>>
            <?php echo esc_html($label); ?>
        </label>
        <?php
    }
}
