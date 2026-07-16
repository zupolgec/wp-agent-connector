<?php

namespace AgentConnector\Modules\Self;

use AgentConnector\Core\Result;

/**
 * Integrity-verified self-update from GitHub release assets.
 *
 * There is deliberately NO "install the latest" shortcut: every update names an
 * explicit tag AND the expected SHA-256 of the release zip. The command
 * downloads that asset, verifies the hash, and only then extracts. On any
 * mismatch it refuses and changes nothing. This makes every production code
 * swap explicit and tamper-evident.
 *
 *   wp agent self version
 *   wp agent self update --tag=v0.2.0 --sha256=<hash>
 *   wp agent self update --tag=v0.2.0 --sha256=<hash> --dry-run
 *
 * The verified artifact is the release asset "wp-agent-connector.zip" (a zip we
 * build and attach to the release), NOT GitHub's auto-generated source archive
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
     * Update the plugin to an explicit, checksum-verified release.
     *
     * ## OPTIONS
     * --tag=<tag>
     * : Release tag to install, e.g. v0.2.0. Required (no implicit "latest").
     * --sha256=<hash>
     * : Expected SHA-256 (64 hex chars) of the release asset. Required.
     * [--dry-run]
     * : Show what would happen without downloading or writing.
     *
     * ## EXAMPLES
     *   wp agent self update --tag=v0.2.0 --sha256=ab12...ef
     */
    public function update($args, $assoc)
    {
        $tag  = isset($assoc['tag']) ? trim((string) $assoc['tag']) : '';
        $hash = isset($assoc['sha256']) ? strtolower(trim((string) $assoc['sha256'])) : '';

        if ($tag === '') {
            Result::fail('--tag is required (no implicit "latest").');
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            Result::fail('--sha256 must be a 64-character hex SHA-256 of the release asset.');
        }

        $url = 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode($tag) . '/' . self::ASSET;

        if (isset($assoc['dry-run'])) {
            Result::out([
                'installed'      => $this->installedVersion(),
                'target_tag'     => $tag,
                'asset_url'      => $url,
                'expected_sha256' => $hash,
                'dry_run'        => true,
            ]);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        $zip = download_url($url, 60);
        if (is_wp_error($zip)) {
            Result::fail('Download failed: ' . $zip->get_error_message());
        }

        $actual = hash_file('sha256', $zip);
        if (!hash_equals($hash, (string) $actual)) {
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

        $copied = copy_dir($root, AGENT_CONNECTOR_DIR);
        $this->rrmdir($workdir);
        if (is_wp_error($copied)) {
            Result::fail('Copy failed: ' . $copied->get_error_message());
        }

        Result::out([
            'ok'   => true,
            'from' => $installed,
            'to'   => $this->installedVersion(),
            'tag'  => $tag,
            'sha256_verified' => true,
        ]);
    }

    // ---- helpers ----------------------------------------------------------

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
