<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Сайты сети и проверки доступа.
 */
class Sites
{
    private static array $sites_cache = [];
    private static array $names_cache = [];
    private static array $urls_cache = [];

    /**
     * @return array<int, array{blog_id:int,name:string,url:string}>
     */
    public static function all(int $user_id = 0): array
    {
        $user_id = $user_id ?: get_current_user_id();

        if (isset(self::$sites_cache[$user_id])) {
            return self::$sites_cache[$user_id];
        }

        $limit = (int) apply_filters('copy_post_sites_limit', 500);
        $args = [
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
            'orderby' => 'path',
            'order' => 'ASC',
        ];

        if ($limit > 0) {
            $args['number'] = $limit;
        }

        $sites = get_sites($args);
        $allowed = null;

        if (!is_super_admin($user_id)) {
            $allowed = [];

            foreach (get_blogs_of_user($user_id) as $blog) {
                $allowed[(int) $blog->userblog_id] = true;
            }
        }

        $out = [];

        foreach ($sites as $site) {
            $blog_id = (int) $site->blog_id;

            if ($blog_id === get_current_blog_id()) {
                continue;
            }

            if ($allowed !== null && !isset($allowed[$blog_id])) {
                continue;
            }

            if (!self::can_copy_to($blog_id, $user_id)) {
                continue;
            }

            $out[] = [
                'blog_id' => $blog_id,
                'name' => self::name($blog_id),
                'url' => self::url($blog_id),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        self::$sites_cache[$user_id] = $out;

        return $out;
    }

    public static function can_copy_to(int $blog_id, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();

        if ($blog_id <= 0 || $blog_id === get_current_blog_id()) {
            return false;
        }

        $site = get_site($blog_id);

        if (!$site || !empty($site->archived) || !empty($site->spam) || !empty($site->deleted)) {
            return false;
        }

        if (is_super_admin($user_id)) {
            return true;
        }

        if (!is_user_member_of_blog($user_id, $blog_id)) {
            return false;
        }

        switch_to_blog($blog_id);
        $can = user_can($user_id, Settings::required_cap());
        restore_current_blog();

        return (bool) $can;
    }

    public static function name(int $blog_id): string
    {
        if (isset(self::$names_cache[$blog_id])) {
            return self::$names_cache[$blog_id];
        }

        $name = (string) get_blog_option($blog_id, 'blogname', '');

        if ($name === '') {
            $site = get_site($blog_id);
            $name = $site ? trim($site->domain . $site->path, '/') : sprintf('Сайт #%d', $blog_id);
        }

        self::$names_cache[$blog_id] = $name;

        return $name;
    }

    public static function url(int $blog_id): string
    {
        if (isset(self::$urls_cache[$blog_id])) {
            return self::$urls_cache[$blog_id];
        }

        $url = (string) get_home_url($blog_id, '/');
        self::$urls_cache[$blog_id] = $url;

        return $url;
    }

    public static function admin_edit_url(int $blog_id, int $post_id): string
    {
        return (string) get_admin_url(
            $blog_id,
            'post.php?post=' . absint($post_id) . '&action=edit'
        );
    }
}
