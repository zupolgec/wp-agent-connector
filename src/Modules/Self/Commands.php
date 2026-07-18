<?php

namespace AgentConnector\Modules\Self;

use AgentConnector\Core\Result;

/**
 * Self-update from GitHub release assets.
 *
 * `update` installs the latest release, or a version you name. It is gated by
 * a confirmation prompt (or --yes when non-interactive) and always reports the
 * downloaded asset's SHA-256. Pass --sha256 to pin an expected hash from an
 * out-of-band source: only then does a mismatch abort the update. (A hash read
 * off the same GitHub release adds no tamper-evidence, so it is not required.)
 *
 *   wp agent self version
 *   wp agent self update --yes
 *   wp agent self update 0.6.2 --yes
 *   wp agent self update 0.6.2 --sha256=<hash> --dry-run
 *
 * The artifact is the release asset "wp-agent-connector.zip" (a zip we build
 * and attach to the release), NOT GitHub's auto-generated source archive
 * (whose bytes are not guaranteed stable).
 */
class Commands
{
    private const REPO  = 'zupolgec/wp-agent-connector';
    private const ASSET = 'wp-agent-connector.zip';

    /**
     * Show the installed version.
     *
     * ## EXAMPLES
     *   wp agent self version
     */
    public function version($args, $assoc)
    {
        Result::out([
            'installed' => $this->installedVersion(),
            'repo'      => self::REPO,
        ]);
    }

    /**
     * Update the plugin to the latest release, or to a specific version.
     *
     * With no version it installs the newest published release. The update is
     * confirmed interactively unless --yes is passed (required for scripted or
     * agent-driven runs). The downloaded asset's SHA-256 is always reported;
     * pass --sha256 to pin an expected hash from an out-of-band source and
     * abort on any mismatch.
     *
     * ## OPTIONS
     * [<version>]
     * : Version to install, e.g. 0.6.2 (a leading "v" is accepted). Omit to
     *   install the latest release.
     * [--sha256=<hash>]
     * : Expected SHA-256 (64 hex chars) of the release asset. Optional; when
     *   given the update aborts unless the download matches.
     * [--yes]
     * : Skip the confirmation prompt (required when not interactive).
     * [--dry-run]
     * : Resolve the target and show it without downloading or writing.
     *
     * ## EXAMPLES
     *   wp agent self update
     *   wp agent self update 0.6.2 --yes
     *   wp agent self update --sha256=ab12...ef --yes
     */
    public function update($args, $assoc)
    {
        $version = isset($args[0]) ? ltrim(trim((string) $args[0]), 'vV') : '';
        $hash    = isset($assoc['sha256']) ? strtolower(trim((string) $assoc['sha256'])) : '';

        if ($hash !== '' && !preg_match('/^[0-9a-f]{64}$/', $hash)) {
            Result::fail('--sha256 must be a 64-character hex SHA-256 of the release asset.');
        }

        $tag       = $version !== '' ? 'v' . $version : $this->latestTag();
        $url       = 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode($tag) . '/' . self::ASSET;
        $installed = $this->installedVersion();

        if (isset($assoc['dry-run'])) {
            Result::out([
                'installed'       => $installed,
                'target_tag'      => $tag,
                'source'          => $version !== '' ? 'pinned' : 'latest',
                'asset_url'       => $url,
                'expected_sha256' => $hash !== '' ? $hash : null,
                'dry_run'         => true,
            ]);
            return;
        }

        \WP_CLI::confirm("Update Agent Connector from {$installed} to {$tag}?", $assoc);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        $zip = download_url($url, 60);
        if (is_wp_error($zip)) {
            Result::fail('Download failed: ' . $zip->get_error_message());
        }

        $actual = (string) hash_file('sha256', $zip);
        if ($hash !== '' && !hash_equals($hash, $actual)) {
            @unlink($zip);
            Result::fail("Checksum mismatch: expected {$hash}, got {$actual}. Aborted, nothing changed.");
        }

        $workdir = trailingslashit(get_temp_dir()) . uniqid('agentconn_up_', true);
        $unzip   = unzip_file($zip, $workdir);
        @unlink($zip);
        if (is_wp_error($unzip)) {
            $this->rrmdir($workdir);
            Result::fail('Unzip failed: ' . $unzip->get_error_message());
        }

        $root = $this->findPluginRoot($workdir);
        if ($root === null) {
            $this->rrmdir($workdir);
            Result::fail('Could not locate agent-connector.php in the archive.');
        }

        $error = $this->installFrom($root);
        $this->rrmdir($workdir);
        if ($error !== null) {
            Result::fail($error);
        }

        Result::out([
            'ok'              => true,
            'from'            => $installed,
            'to'              => $this->installedVersion(),
            'tag'             => $tag,
            'sha256'          => $actual,
            'sha256_verified' => $hash !== '',
        ]);
    }

    // ---- helpers ----------------------------------------------------------

    /** Newest published release tag, via the GitHub API. Fails closed. */
    private function latestTag(): string
    {
        $res = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 30,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'wp-agent-connector',
                ],
            ]
        );
        if (is_wp_error($res)) {
            Result::fail('Could not query the latest release: ' . $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            Result::fail("GitHub API returned HTTP {$code} while resolving the latest release.");
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $tag  = is_array($data) && isset($data['tag_name']) ? (string) $data['tag_name'] : '';
        if ($tag === '') {
            Result::fail('Could not determine the latest release tag.');
        }
        return $tag;
    }

    private function installedVersion(): string
    {
        $data = get_file_data(AGENT_CONNECTOR_FILE, ['Version' => 'Version']);
        return $data['Version'] !== '' ? $data['Version'] : (defined('AGENT_CONNECTOR_VERSION') ? AGENT_CONNECTOR_VERSION : '0');
    }

    /** Find the directory containing agent-connector.php (flat or nested archive). */
    private function findPluginRoot(string $dir): ?string
    {
        if (is_file($dir . '/agent-connector.php')) {
            return $dir;
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $sub) {
            if (is_file($sub . '/agent-connector.php')) {
                return $sub;
            }
        }
        return null;
    }

    /**
     * Replace the plugin directory with the verified new version.
     *
     * A clean swap (current aside → new into place → drop the old copy), not a
     * copy_dir overlay: files removed in the new release must disappear, and
     * on failure the previous version is restored. Returns an error message
     * or null on success.
     */
    private function installFrom(string $root): ?string
    {
        $target  = AGENT_CONNECTOR_DIR;
        $parent  = dirname($target);
        $staging = $parent . '/.agentconn-new-' . uniqid('', true);
        $backup  = $parent . '/.agentconn-old-' . uniqid('', true);

        $copied = copy_dir($root, $staging);
        if (is_wp_error($copied)) {
            $this->rrmdir($staging);
            return 'Copy failed: ' . $copied->get_error_message();
        }
        if (!@rename($target, $backup)) {
            $this->rrmdir($staging);
            return 'Could not move the current version aside; nothing changed.';
        }
        if (!@rename($staging, $target)) {
            @rename($backup, $target);
            return 'Could not move the new version into place; previous version restored.';
        }
        $this->rrmdir($backup);
        return null;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }
}
