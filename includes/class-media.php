<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Сбор и копирование вложений.
 */
class Media
{
    /**
     * Собирает данные о вложениях, на которые ссылается запись.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function collect(\WP_Post $post, array $meta): array
    {
        $ids = [];
        $content = (string) $post->post_content;

        self::collect_from_content($content, $ids);

        $thumbnail_id = (int) get_post_thumbnail_id($post);
        if ($thumbnail_id > 0) {
            $ids[] = $thumbnail_id;
        }

        self::collect_from_meta($meta, $ids);

        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $items = [];

        foreach ($ids as $id) {
            $attachment = get_post($id);

            if (!$attachment || $attachment->post_type !== 'attachment') {
                continue;
            }

            $path = get_attached_file($id);
            $url = wp_get_attachment_url($id);

            if (!$path && !$url) {
                continue;
            }

            $attachment_meta = [];

            foreach (get_post_meta($id) as $meta_key => $meta_values) {
                $attachment_meta[$meta_key] = array_map('maybe_unserialize', (array) $meta_values);
            }

            $items[$id] = [
                'id' => $id,
                'path' => $path ?: '',
                'url' => $url ?: '',
                'mime' => (string) get_post_mime_type($id),
                'title' => (string) get_the_title($id),
                'caption' => (string) get_post_field('post_excerpt', $id),
                'description' => (string) get_post_field('post_content', $id),
                'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'parent' => (int) get_post_field('post_parent', $id),
                'meta' => $attachment_meta,
            ];
        }

        return $items;
    }

    /**
     * Копирует файлы в текущий (целевой) сайт.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array{map:array<int,array{id:int,url:string}>,url_map:array<string,string>,warnings:string[]}
     */
    public static function copy(array $sources, int $source_blog_id, string $source_content_url = ''): array
    {
        $map = [];
        $url_map = [];
        $warnings = [];
        $source_content_url = untrailingslashit($source_content_url);
        $target_content_url = untrailingslashit((string) network_site_url('/wp-content'));
        $target_content_url = untrailingslashit((string) apply_filters(
            'copy_post_target_content_url',
            $target_content_url,
            get_current_blog_id()
        ));
        $normalize_url = static function (string $url) use ($source_content_url, $target_content_url): string {
            if (
                $source_content_url !== ''
                && $target_content_url !== ''
                && ($url === $source_content_url || strpos($url, $source_content_url . '/') === 0)
            ) {
                return $target_content_url . substr($url, strlen($source_content_url));
            }

            return $url;
        };

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!function_exists('wp_unique_filename')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        foreach ($sources as $source) {
            $source_id = (int) $source['id'];
            $existing_id = self::find_existing($source_blog_id, $source_id);

            if ($existing_id > 0) {
                $existing_url = wp_get_attachment_url($existing_id);

                if ($existing_url) {
                    $existing_url = $normalize_url($existing_url);
                    $map[$source_id] = ['id' => $existing_id, 'url' => $existing_url];

                    if (!empty($source['url'])) {
                        $url_map[(string) $source['url']] = $existing_url;
                    }

                    continue;
                }
            }

            $contents = false;
            $filename = '';

            if (!empty($source['path']) && is_readable((string) $source['path'])) {
                $contents = @file_get_contents((string) $source['path']);
                $filename = basename((string) $source['path']);
            }

            if ($contents === false && !empty($source['url'])) {
                $response = wp_remote_get((string) $source['url'], [
                    'timeout' => 30,
                    'redirection' => 3,
                ]);

                if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
                    $contents = wp_remote_retrieve_body($response);
                    $path = (string) wp_parse_url((string) $source['url'], PHP_URL_PATH);
                    $filename = basename($path);
                }
            }

            if ($contents === false || $contents === '') {
                $warnings[] = sprintf(
                    /* translators: %s: attachment title. */
                    __('Не удалось прочитать файл «%s».', 'copy-post'),
                    $source['title'] ?: ('#' . $source_id)
                );
                continue;
            }

            if ($filename === '') {
                $filename = 'attachment-' . $source_id . '.bin';
            }

            $upload = wp_upload_bits($filename, null, $contents);

            if (!empty($upload['error'])) {
                $upload = self::fallback_upload($filename, $contents);

                if (is_wp_error($upload)) {
                    $warnings[] = sprintf(
                        /* translators: 1: attachment title, 2: error message. */
                        __('Не удалось скопировать файл «%1$s»: %2$s', 'copy-post'),
                        $source['title'] ?: ('#' . $source_id),
                        $upload->get_error_message()
                    );
                    continue;
                }
            }

            $mime = (string) $source['mime'];

            if ($mime === '') {
                $filetype = wp_check_filetype((string) $upload['file']);
                $mime = $filetype['type'] ?: 'application/octet-stream';
            }

            $upload_url = $normalize_url((string) $upload['url']);

            $attachment = [
                'post_mime_type' => $mime,
                'post_title' => $source['title'] ?: $filename,
                'post_content' => $source['description'],
                'post_excerpt' => $source['caption'],
                'post_status' => 'inherit',
                'guid' => $upload_url,
            ];

            $new_id = wp_insert_attachment(wp_slash($attachment), (string) $upload['file'], 0, true);

            if (is_wp_error($new_id) || !$new_id) {
                $message = is_wp_error($new_id) ? $new_id->get_error_message() : __('неизвестная ошибка', 'copy-post');
                $warnings[] = sprintf(
                    /* translators: 1: attachment title, 2: error message. */
                    __('Не удалось создать вложение «%1$s»: %2$s', 'copy-post'),
                    $source['title'] ?: ('#' . $source_id),
                    $message
                );
                continue;
            }

            $metadata = wp_generate_attachment_metadata((int) $new_id, (string) $upload['file']);

            if (!is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata((int) $new_id, $metadata);
            }

            $skip_meta = [
                '_wp_attached_file',
                '_wp_attachment_metadata',
                '_wp_attachment_backup_sizes',
                '_copy_post_media_source',
                '_copy_post_media_source_url',
            ];

            foreach ((array) $source['meta'] as $meta_key => $values) {
                if (in_array((string) $meta_key, $skip_meta, true)) {
                    continue;
                }

                foreach ((array) $values as $value) {
                    add_post_meta((int) $new_id, (string) $meta_key, wp_slash($value));
                }
            }

            if (!empty($source['alt'])) {
                update_post_meta((int) $new_id, '_wp_attachment_image_alt', wp_slash($source['alt']));
            }

            update_post_meta((int) $new_id, '_copy_post_media_source', $source_blog_id . ':' . $source_id);
            update_post_meta((int) $new_id, '_copy_post_media_source_url', (string) $source['url']);

            $new_url = wp_get_attachment_url((int) $new_id) ?: (string) $upload['url'];
            $new_url = $normalize_url($new_url);

            $map[$source_id] = ['id' => (int) $new_id, 'url' => $new_url];

            if (!empty($source['url'])) {
                $url_map[(string) $source['url']] = $new_url;
            }
        }

        return [
            'map' => $map,
            'url_map' => $url_map,
            'warnings' => $warnings,
        ];
    }

    private static function find_existing(int $source_blog_id, int $source_id): int
    {
        $key = $source_blog_id . ':' . $source_id;
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_key' => '_copy_post_media_source',
            'meta_value' => $key,
        ]);

        if (empty($ids)) {
            return 0;
        }

        $id = (int) $ids[0];

        return get_attached_file($id) ? $id : 0;
    }

    /**
     * @return array{file:string,url:string}| \WP_Error
     */
    private static function fallback_upload(string $filename, string $contents)
    {
        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            return new \WP_Error('copy_post_upload_dir', (string) $uploads['error']);
        }

        $filename = wp_unique_filename($uploads['path'], sanitize_file_name($filename));
        $path = trailingslashit($uploads['path']) . $filename;

        if (false === @file_put_contents($path, $contents)) {
            return new \WP_Error('copy_post_upload_failed', __('не удалось записать файл в uploads', 'copy-post'));
        }

        return [
            'file' => $path,
            'url' => str_replace($uploads['basedir'], $uploads['baseurl'], $path),
        ];
    }

    private static function collect_from_content(string $content, array &$ids): void
    {
        if ($content === '') {
            return;
        }

        if (strpos($content, '<!-- wp:') !== false) {
            $blocks = parse_blocks($content);
            self::collect_blocks($blocks, $ids);
        }

        self::collect_from_html($content, $ids);
        self::collect_urls($content, $ids);
    }

    private static function collect_blocks(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                self::collect_attrs($block['attrs'], $ids);
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collect_blocks($block['innerBlocks'], $ids);
            }

            if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
                self::collect_from_html($block['innerHTML'], $ids);
                self::collect_urls($block['innerHTML'], $ids);
            }

            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $chunk) {
                    if (is_string($chunk)) {
                        self::collect_from_html($chunk, $ids);
                        self::collect_urls($chunk, $ids);
                    }
                }
            }
        }
    }

    private static function collect_attrs(array $attrs, array &$ids): void
    {
        $single_keys = ['id', 'mediaId', 'imageId', 'attachmentId', 'backgroundId', 'posterId', 'videoId', 'audioId'];
        $list_keys = ['ids', 'mediaIds', 'imageIds', 'galleryIds'];

        foreach ($attrs as $key => $value) {
            if (in_array($key, $single_keys, true)) {
                self::add_id($value, $ids);
                continue;
            }

            if (in_array($key, $list_keys, true)) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        self::add_id($item, $ids);
                    }
                } elseif (is_string($value)) {
                    foreach (preg_split('/[\s,]+/', $value) as $item) {
                        self::add_id($item, $ids);
                    }
                }
                continue;
            }

            if (is_string($value)) {
                self::collect_urls($value, $ids);
            } elseif (is_array($value)) {
                self::collect_attrs($value, $ids);
            }
        }
    }

    private static function collect_from_html(string $html, array &$ids): void
    {
        if (preg_match_all('/wp-image-(\d+)/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                self::add_id($id, $ids);
            }
        }

        if (preg_match_all('/attachment_(\d+)/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                self::add_id($id, $ids);
            }
        }

        if (preg_match_all('/data-(?:id|attachment-id|media-id)=["\'](\d+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                self::add_id($id, $ids);
            }
        }

        if (preg_match_all('/\[(?:gallery|playlist)[^\]]*ids=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $list) {
                foreach (explode(',', $list) as $id) {
                    self::add_id($id, $ids);
                }
            }
        }
    }

    private static function collect_from_meta(array $meta, array &$ids): void
    {
        foreach ($meta as $key => $values) {
            foreach ((array) $values as $value) {
                self::collect_meta_value((string) $key, $value, $ids, 0);
            }
        }
    }

    /**
     * @param mixed $value
     */
    private static function collect_meta_value(string $key, $value, array &$ids, int $depth): void
    {
        if ($depth > 6) {
            return;
        }

        if (is_string($value)) {
            if (Remapper::is_media_key($key)) {
                foreach (preg_split('/[\s,]+/', $value) as $part) {
                    self::add_id($part, $ids);
                }
            }

            self::collect_urls($value, $ids);

            return;
        }

        if (is_int($value) || is_float($value)) {
            if (Remapper::is_media_key($key)) {
                self::add_id($value, $ids);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::collect_meta_value($key, $item, $ids, $depth + 1);
            }
        }
    }

    private static function collect_urls(string $text, array &$ids): void
    {
        if ($text === '' || strpos($text, 'http') === false) {
            return;
        }

        $scan = str_replace('\\/', '/', $text);

        if (!preg_match_all('#https?://[^\s"\'<>()]+#i', $scan, $matches)) {
            return;
        }

        foreach ($matches[0] as $url) {
            $id = self::url_to_attachment_id($url);

            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    private static function url_to_attachment_id(string $url): int
    {
        $uploads = wp_get_upload_dir();
        $base_url = (string) ($uploads['baseurl'] ?? '');

        if ($base_url === '' || (strpos($url, $base_url) === false && strpos($url, '/wp-content/uploads/') === false)) {
            return 0;
        }

        $url = strtok($url, '?#');
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');

        $candidates = [$url];
        $without_size = preg_replace('/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $url);
        $without_scaled = preg_replace('/-scaled(?=\.[a-z0-9]{2,5}$)/i', '', $url);
        $without_rotated = preg_replace('/-rotated(?=\.[a-z0-9]{2,5}$)/i', '', $url);
        $without_edit = preg_replace('/-e\d+(?=\.[a-z0-9]{2,5}$)/i', '', $url);

        foreach ([$without_size, $without_scaled, $without_rotated, $without_edit] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $candidate !== $url) {
                $candidates[] = $candidate;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $id = (int) attachment_url_to_postid($candidate);

            if ($id > 0 && get_post_type($id) === 'attachment') {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @param mixed $value
     */
    private static function add_id($value, array &$ids): void
    {
        if (is_numeric($value)) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
}
