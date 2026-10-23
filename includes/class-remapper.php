<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Перепривязка медиа-ID и URL в контенте и мете.
 */
class Remapper
{
    /**
     * @param array<int, array{id:int,url:string}> $media_map
     * @return array<string, string>
     */
    public static function build_url_map(array $payload, array $media_map, array $media_url_map = [], bool $rewrite_site = true): array
    {
        $map = [];
        $source_home = untrailingslashit((string) ($payload['source_home'] ?? ''));
        $source_site = untrailingslashit((string) ($payload['source_site'] ?? ''));
        $source_content = untrailingslashit((string) ($payload['source_content'] ?? ''));

        $target_home = untrailingslashit((string) home_url('/'));
        $target_site = untrailingslashit((string) site_url('/'));
        $target_content = untrailingslashit((string) apply_filters(
            'copy_post_target_content_url',
            network_site_url('/wp-content'),
            get_current_blog_id()
        ));

        if ($rewrite_site) {
            $map = self::add_variants($map, $source_home, $target_home);
            $map = self::add_variants($map, $source_site, $target_site);
            $map = self::add_variants($map, $source_content, $target_content);
        }

        foreach ($media_url_map as $source_url => $target_url) {
            $map = self::add_variants($map, (string) $source_url, (string) $target_url);
        }

        $source_uploads = untrailingslashit((string) ($payload['source_uploads'] ?? ''));

        if ($source_uploads !== '') {
            foreach (self::url_variants($source_uploads) as $variant) {
                $map[$variant] = $variant;
            }
        }

        uksort($map, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return apply_filters('copy_post_url_map', $map, $payload, $media_map, $media_url_map);
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     * @param array<string, string> $url_map
     */
    public static function content(string $content, array $media_map, array $url_map): string
    {
        if ($content === '') {
            return $content;
        }

        if (strpos($content, '<!-- wp:') !== false) {
            $blocks = parse_blocks($content);

            if (is_array($blocks)) {
                $blocks = self::walk_blocks($blocks, $media_map, $url_map);
                $content = serialize_blocks($blocks);
            }
        }

        $content = self::remap_html($content, $media_map);
        $content = self::replace_urls($content, $url_map);

        return $content;
    }

    /**
     * @param mixed $value
     * @param array<int, array{id:int,url:string}> $media_map
     * @param array<string, string> $url_map
     * @param array<string, mixed> $options
     * @return mixed
     */
    public static function meta_value(string $key, $value, array $media_map, array $url_map, array $options = [])
    {
        if (is_string($value)) {
            if (self::is_media_key($key) && preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $value)) {
                $value = self::map_id_list($value, $media_map);
            } else {
                $value = self::replace_urls($value, $url_map);
            }
        } elseif (is_int($value) || is_float($value)) {
            if (self::is_media_key($key)) {
                $value = self::map_id($value, $media_map);
            }
        } elseif (is_array($value)) {
            foreach ($value as $index => $item) {
                if (self::is_media_key($key) && (is_int($item) || is_float($item))) {
                    $value[$index] = self::map_id($item, $media_map);
                } elseif (is_string($item)) {
                    $value[$index] = self::replace_urls($item, $url_map);
                } elseif (is_array($item)) {
                    $value[$index] = self::meta_value($key, $item, $media_map, $url_map, $options);
                }
            }
        }

        return apply_filters('copy_post_meta_value', $value, $key, $media_map, $url_map, $options);
    }

    public static function is_media_key(string $key): bool
    {
        $exact = [
            '_thumbnail_id',
            '_photo_id',
            'edu_photo_id',
            '_product_image_gallery',
            '_gallery_ids',
            '_image_id',
            '_background_image_id',
        ];

        if (in_array($key, $exact, true)) {
            return true;
        }

        return (bool) preg_match('/(?:^|_)(?:image|photo|attachment|media|thumbnail|gallery)(?:_?ids?)?$/i', $key);
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     */
    public static function map_id($value, array $media_map): int
    {
        $id = (int) $value;

        return isset($media_map[$id]) ? (int) $media_map[$id]['id'] : $id;
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     * @return mixed
     */
    private static function map_ids($value, array $media_map)
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = is_numeric($item) ? self::map_id($item, $media_map) : $item;
            }

            return $out;
        }

        if (is_string($value)) {
            return self::map_id_list($value, $media_map);
        }

        return $value;
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     */
    private static function map_id_list(string $value, array $media_map): string
    {
        return (string) preg_replace_callback('/\d+/', static function (array $matches) use ($media_map): string {
            $id = (int) $matches[0];

            return isset($media_map[$id]) ? (string) $media_map[$id]['id'] : $matches[0];
        }, $value);
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     * @param array<string, string> $url_map
     */
    private static function walk_blocks(array $blocks, array $media_map, array $url_map): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $blocks[$index]['attrs'] = self::walk_attrs($block['attrs'], $media_map, $url_map);
            }

            $has_inner_blocks = !empty($block['innerBlocks']) && is_array($block['innerBlocks']);

            if ($has_inner_blocks) {
                $blocks[$index]['innerBlocks'] = self::walk_blocks($block['innerBlocks'], $media_map, $url_map);
            }
        }

        return $blocks;
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     * @param array<string, string> $url_map
     */
    private static function walk_attrs(array $attrs, array $media_map, array $url_map): array
    {
        $single_keys = ['id', 'mediaId', 'imageId', 'attachmentId', 'backgroundId', 'posterId', 'videoId', 'audioId'];
        $list_keys = ['ids', 'mediaIds', 'imageIds', 'galleryIds'];

        foreach ($attrs as $key => $value) {
            if (in_array($key, $single_keys, true)) {
                if (is_numeric($value)) {
                    $attrs[$key] = self::map_id($value, $media_map);
                }
                continue;
            }

            if (in_array($key, $list_keys, true)) {
                $attrs[$key] = self::map_ids($value, $media_map);
                continue;
            }

            if (is_string($value)) {
                $attrs[$key] = self::replace_urls($value, $url_map);
            } elseif (is_array($value)) {
                $attrs[$key] = self::walk_attrs($value, $media_map, $url_map);
            }
        }

        return $attrs;
    }

    /**
     * @param array<int, array{id:int,url:string}> $media_map
     */
    private static function remap_html(string $html, array $media_map): string
    {
        if ($html === '' || ($media_map === [] && strpos($html, 'wp-image-') === false && strpos($html, 'attachment_') === false)) {
            return $html;
        }

        $html = (string) preg_replace_callback('/wp-image-(\d+)/i', static function (array $matches) use ($media_map): string {
            $id = (int) $matches[1];

            return isset($media_map[$id]) ? 'wp-image-' . (int) $media_map[$id]['id'] : $matches[0];
        }, $html);

        $html = (string) preg_replace_callback('/attachment_(\d+)/i', static function (array $matches) use ($media_map): string {
            $id = (int) $matches[1];

            return isset($media_map[$id]) ? 'attachment_' . (int) $media_map[$id]['id'] : $matches[0];
        }, $html);

        $html = (string) preg_replace_callback('/\[(gallery|playlist)([^\]]*)\]/i', static function (array $matches) use ($media_map): string {
            $shortcode = $matches[1];
            $attributes = $matches[2];

            $attributes = (string) preg_replace_callback('/ids=(["\'])([^"\']*)\1/i', static function (array $ids_match) use ($media_map): string {
                $quote = $ids_match[1];
                $ids = self::map_id_list($ids_match[2], $media_map);

                return 'ids=' . $quote . $ids . $quote;
            }, $attributes);

            return '[' . $shortcode . $attributes . ']';
        }, $html);

        return $html;
    }

    /**
     * @param array<string, string> $url_map
     */
    public static function replace_urls(string $text, array $url_map): string
    {
        if ($text === '' || $url_map === []) {
            return $text;
        }

        return strtr($text, $url_map);
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private static function add_variants(array $map, string $source, string $target): array
    {
        $source = trim($source);
        $target = trim($target);

        if ($source === '' || $target === '' || $source === $target) {
            return $map;
        }

        $source_variants = self::url_variants($source);

        foreach ($source_variants as $variant) {
            $map[$variant] = $target;

            if (substr($variant, -1) === '/' && substr($target, -1) !== '/') {
                $map[$variant] = $target . '/';
            } elseif (substr($variant, -1) !== '/' && substr($target, -1) === '/') {
                $map[$variant] = rtrim($target, '/');
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    private static function url_variants(string $url): array
    {
        $variants = [$url];
        $trimmed = rtrim($url, '/');
        $slashed = trailingslashit($url);
        $variants[] = $trimmed;
        $variants[] = $slashed;

        if (strpos($url, 'http://') === 0) {
            $variants[] = 'https://' . substr($url, 7);
            $variants[] = 'https:\/\/' . substr($url, 7);
        } elseif (strpos($url, 'https://') === 0) {
            $variants[] = 'http://' . substr($url, 8);
            $variants[] = 'http:\/\/' . substr($url, 8);
        }

        $variants[] = str_replace('/', '\/', $url);

        $out = [];

        foreach ($variants as $variant) {
            $variant = trim((string) $variant);

            if ($variant !== '') {
                $out[] = $variant;
            }
        }

        return array_values(array_unique($out));
    }
}
