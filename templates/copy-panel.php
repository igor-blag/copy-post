<?php
/**
 * Панель выбора сайтов и параметров копирования.
 *
 * @var array<int, array{blog_id:int,name:string,url:string}> $targets
 * @var array<string, mixed> $settings
 * @var int[] $post_ids
 * @var string $mode
 */

defined('ABSPATH') || exit;

$mode = isset($mode) && $mode === 'modal' ? 'modal' : 'page';
$targets = isset($targets) && is_array($targets) ? $targets : [];
$settings = isset($settings) && is_array($settings) ? $settings : [];
$post_ids = isset($post_ids) && is_array($post_ids) ? array_values(array_filter(array_map('absint', $post_ids))) : [];
$post_ids_value = implode(',', $post_ids);
$unique_id = wp_unique_id('copy-post-');

$checkbox = static function (string $name, string $label, bool $checked): void {
    ?>
    <label class="copy-post-option">
        <input type="checkbox"
               name="<?php echo esc_attr($name); ?>"
               value="1"
            <?php checked($checked); ?>>
        <span><?php echo esc_html($label); ?></span>
    </label>
    <?php
};
?>
<div class="copy-post-panel" data-post-ids="<?php echo esc_attr($post_ids_value); ?>">
    <input type="hidden" class="copy-post-post-ids" name="copy_post_ids" value="<?php echo esc_attr($post_ids_value); ?>">

    <div class="copy-post-panel__grid">
        <section class="copy-post-panel__section copy-post-panel__section--sites">
            <h3><?php echo esc_html__('Целевые сайты', 'copy-post'); ?></h3>

            <?php if ($targets === []) : ?>
                <p class="copy-post-empty"><?php echo esc_html__('Нет доступных целевых сайтов. Проверьте, что вы участник других сайтов сети и имеете право редактировать записи.', 'copy-post'); ?></p>
            <?php else : ?>
                <div class="copy-post-sites-tools">
                    <label class="copy-post-option copy-post-select-all-sites-label">
                        <input type="checkbox" class="copy-post-select-all-sites">
                        <span><?php echo esc_html__('Выбрать все', 'copy-post'); ?></span>
                    </label>

                    <?php if (count($targets) > 8) : ?>
                        <input type="search"
                               class="copy-post-site-search"
                               placeholder="<?php echo esc_attr__('Поиск сайта…', 'copy-post'); ?>"
                               aria-label="<?php echo esc_attr__('Поиск сайта', 'copy-post'); ?>">
                    <?php endif; ?>
                </div>

                <div class="copy-post-sites">
                    <?php foreach ($targets as $site) : ?>
                        <label class="copy-post-site" data-site-name="<?php echo esc_attr(function_exists('mb_strtolower') ? mb_strtolower($site['name']) : strtolower($site['name'])); ?>">
                            <input type="checkbox" name="targets[]" value="<?php echo esc_attr((string) $site['blog_id']); ?>">
                            <span class="copy-post-site__text">
                                <span class="copy-post-site__name"><?php echo esc_html($site['name']); ?></span>
                                <span class="copy-post-site__url"><?php echo esc_html($site['url']); ?></span>
                            </span>
                            <span class="copy-post-site__badge" hidden></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="copy-post-panel__section">
            <h3><?php echo esc_html__('Что копировать', 'copy-post'); ?></h3>
            <div class="copy-post-options">
                <?php
                $checkbox('copy_taxonomies', __('Таксономии (категории, метки, произвольные)', 'copy-post'), !empty($settings['copy_taxonomies']));
                $checkbox('copy_meta', __('Произвольные поля (мета)', 'copy-post'), !empty($settings['copy_meta']));
                $checkbox('copy_media', __('Медиа: миниатюра и файлы из контента/меты', 'copy-post'), !empty($settings['copy_media']));
                $checkbox('copy_dates', __('Дата и время публикации', 'copy-post'), !empty($settings['copy_dates']));
                $checkbox('copy_authors', __('Автор', 'copy-post'), !empty($settings['copy_authors']));
                $checkbox('copy_sticky', __('Закрепление записи (sticky)', 'copy-post'), !empty($settings['copy_sticky']));
                $checkbox('rewrite_urls', __('Переписывать ссылки исходного сайта', 'copy-post'), !empty($settings['rewrite_urls']));
                $checkbox('copy_comments', __('Комментарии (только при создании копии)', 'copy-post'), !empty($settings['copy_comments']));
                ?>
            </div>
        </section>

        <section class="copy-post-panel__section">
            <h3><?php echo esc_html__('Поведение', 'copy-post'); ?></h3>

            <p class="copy-post-field">
                <label for="<?php echo esc_attr($unique_id); ?>-duplicate"><?php echo esc_html__('Если копия уже есть', 'copy-post'); ?></label>
                <select id="<?php echo esc_attr($unique_id); ?>-duplicate" name="duplicate">
                    <option value="new" <?php selected((string) ($settings['duplicate_action'] ?? 'new'), 'new'); ?>>
                        <?php echo esc_html__('Создать новую', 'copy-post'); ?>
                    </option>
                    <option value="update" <?php selected((string) ($settings['duplicate_action'] ?? 'new'), 'update'); ?>>
                        <?php echo esc_html__('Обновить существующую', 'copy-post'); ?>
                    </option>
                    <option value="skip" <?php selected((string) ($settings['duplicate_action'] ?? 'new'), 'skip'); ?>>
                        <?php echo esc_html__('Пропустить', 'copy-post'); ?>
                    </option>
                </select>
            </p>

            <p class="copy-post-field">
                <label for="<?php echo esc_attr($unique_id); ?>-status"><?php echo esc_html__('Статус копии', 'copy-post'); ?></label>
                <select id="<?php echo esc_attr($unique_id); ?>-status" name="status">
                    <option value="draft" <?php selected((string) ($settings['default_status'] ?? 'draft'), 'draft'); ?>>
                        <?php echo esc_html__('Черновик', 'copy-post'); ?>
                    </option>
                    <option value="same" <?php selected((string) ($settings['default_status'] ?? 'draft'), 'same'); ?>>
                        <?php echo esc_html__('Как у оригинала', 'copy-post'); ?>
                    </option>
                    <option value="publish" <?php selected((string) ($settings['default_status'] ?? 'draft'), 'publish'); ?>>
                        <?php echo esc_html__('Опубликовать', 'copy-post'); ?>
                    </option>
                </select>
            </p>

            <p class="copy-post-selection">
                <?php echo esc_html__('Выбрано записей:', 'copy-post'); ?>
                <strong class="copy-post-selected-count"><?php echo esc_html((string) count($post_ids)); ?></strong>
            </p>
        </section>
    </div>

    <p class="copy-post-message" role="alert"></p>

    <div class="copy-post-panel__actions">
        <button type="button" class="button button-primary copy-post-start">
            <?php echo esc_html__('Копировать', 'copy-post'); ?>
        </button>

        <?php if ($mode === 'modal') : ?>
            <button type="button" class="button copy-post-close">
                <?php echo esc_html__('Закрыть', 'copy-post'); ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="copy-post-progress" hidden>
        <div class="copy-post-progress__bar"><span></span></div>
        <div class="copy-post-progress__footer">
            <span class="copy-post-progress__text"></span>
            <button type="button" class="button button-small copy-post-stop">
                <?php echo esc_html__('Остановить', 'copy-post'); ?>
            </button>
        </div>
    </div>

    <div class="copy-post-results" hidden>
        <p class="copy-post-summary"></p>
        <div class="copy-post-results__wrap">
            <table class="widefat striped copy-post-results__table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Запись', 'copy-post'); ?></th>
                        <th><?php echo esc_html__('Сайт', 'copy-post'); ?></th>
                        <th><?php echo esc_html__('Результат', 'copy-post'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p>
            <button type="button" class="button copy-post-retry" hidden>
                <?php echo esc_html__('Повторить ошибки', 'copy-post'); ?>
            </button>
        </p>
    </div>
</div>
