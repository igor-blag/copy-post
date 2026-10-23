<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Обновления из GitHub Releases.
 */
class Updater
{
    private const REPOSITORY = 'igor-blag/copy-post';
    private const UPDATE_URI = 'https://github.com/igor-blag/copy-post';
    private const TRANSIENT = 'copy_post_github_release';

    public function init(): void
    {
        add_filter('update_plugins_github.com', [$this, 'filter_update'], 10, 4);
    }

    /**
     * @param mixed $update
     * @param array<string, mixed> $plugin_data
     * @param string $plugin_file
     * @param array<string, string> $locales
     * @return array<string, mixed>|false
     */
    public function filter_update($update, array $plugin_data, string $plugin_file, array $locales)
    {
        if ($plugin_file !== plugin_basename(COPY_POST_FILE)) {
            return $update;
        }

        $release = $this->latest_release();
        $installed = (string) ($plugin_data['Version'] ?? COPY_POST_VERSION);

        if (!$release || version_compare($release['version'], $installed, '<=')) {
            return false;
        }

        return [
            'id' => self::UPDATE_URI,
            'slug' => 'copy-post',
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => '6.1',
            'requires_php' => '7.4',
        ];
    }

    /**
     * @return array{version:string,url:string,package:string}|null
     */
    private function latest_release(): ?array
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached)) {
            return !empty($cached['available']) ? $cached : null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            [
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Copy-Post-WordPress-Updater',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            set_transient(self::TRANSIENT, ['available' => false], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
            set_transient(self::TRANSIENT, ['available' => false], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $tag = (string) ($release['tag_name'] ?? '');
        $version = preg_replace('/^v/i', '', $tag);

        if (!$version || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][a-zA-Z0-9.-]+)?$/D', $version)) {
            set_transient(self::TRANSIENT, ['available' => false], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $asset_name = 'copy-post-' . $version . '.zip';
        $package = '';

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== $asset_name) {
                continue;
            }

            $candidate = (string) ($asset['browser_download_url'] ?? '');
            $parts = wp_parse_url($candidate);

            if (is_array($parts) && 'https' === ($parts['scheme'] ?? '') && 'github.com' === ($parts['host'] ?? '')) {
                $package = esc_url_raw($candidate);
                break;
            }
        }

        $release_url = esc_url_raw((string) ($release['html_url'] ?? ''));

        if ($package === '' || $release_url === '') {
            set_transient(self::TRANSIENT, ['available' => false], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $data = [
            'available' => true,
            'version' => $version,
            'url' => $release_url,
            'package' => $package,
        ];

        set_transient(self::TRANSIENT, $data, 6 * HOUR_IN_SECONDS);

        return $data;
    }
}
