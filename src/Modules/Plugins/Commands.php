<?php

namespace AgentConnector\Modules\Plugins;

use AgentConnector\Core\Result;

/**
 * Deploy and inspect plugin files from the CLI, without ad-hoc scp/FTP.
 *
 * Paths are always relative to WP_PLUGIN_DIR and confined there (no absolute
 * paths, no `..`). `push` lints PHP before writing (via token_get_all, without
 * executing), writes atomically (temp file + rename), and keeps a one-level
 * `.agentconn-bak` sibling so `restore` can undo a bad deploy. The connector's
 * own directory is off-limits: updating it is `wp agent self`'s job.
 *
 *   wp agent plugin status my-plugin/my-plugin.php [--code=-]
 *   wp agent plugin pull my-plugin/my-plugin.php > local.php
 *   cat local.php | wp agent plugin push my-plugin/my-plugin.php --code=- [--if-match=<sha256>]
 *   wp agent plugin restore my-plugin/my-plugin.php
 *
 * Activation stays on the native command (`wp plugin activate <slug>`).
 */
class Commands
{
    private const BACKUP_SUFFIX = '.agentconn-bak';

    /**
     * Report a plugin file's state, or compare it with a local code stream.
     *
     * ## OPTIONS
     * <path>
     * : File path relative to wp-content/plugins (e.g. my-plugin/my-plugin.php).
     * [--code=<file>]
     * : Local code path, or "-" for STDIN, to compare with the remote file.
     * [--field=<field>]
     * : Print a single status field raw (e.g. sha256).
     */
    public function status($args, $assoc)
    {
        $rel    = (string) ($args[0] ?? '');
        $target = $this->resolve($rel);
        $exists = is_file($target);
        $code   = $exists ? (string) file_get_contents($target) : '';

        $status = [
            'path'   => $rel,
            'exists' => $exists,
            'bytes'  => $exists ? strlen($code) : null,
            'sha256' => $exists ? hash('sha256', $code) : null,
            'backup' => is_file($target . self::BACKUP_SUFFIX),
        ];

        // Activation state of the plugin this file belongs to (top directory).
        $top    = explode('/', $rel)[0];
        $active = false;
        foreach ((array) get_option('active_plugins', []) as $p) {
            if ($p === $rel || strpos((string) $p, $top . '/') === 0) {
                $active = true;
                break;
            }
        }
        $status['plugin_active'] = $active;

        if (isset($assoc['code'])) {
            $local                  = $this->readCode((string) $assoc['code']);
            $status['local_bytes']  = strlen($local);
            $status['local_sha256'] = hash('sha256', $local);
            if (!$exists) {
                $status['state'] = 'absent';
            } else {
                $status['state'] = hash_equals((string) $status['sha256'], $status['local_sha256'])
                    ? 'in_sync'
                    : 'different';
            }
        }

        if (!empty($assoc['field'])) {
            $field = (string) $assoc['field'];
            if (!array_key_exists($field, $status)) {
                Result::fail("No such status field: {$field}");
            }
            \WP_CLI::log((string) $status[$field]);
            return;
        }

        Result::out($status);
    }

    /**
     * Stream a plugin file's bytes to STDOUT for an atomic local pull.
     *
     * ## OPTIONS
     * <path>
     * : File path relative to wp-content/plugins.
     */
    public function pull($args, $assoc)
    {
        $target = $this->resolve((string) ($args[0] ?? ''));
        if (!is_file($target)) {
            Result::fail('File not found: ' . (string) ($args[0] ?? ''));
        }
        // No WP_CLI::log here: it appends a newline and would break byte-exact pulls.
        fwrite(STDOUT, (string) file_get_contents($target));
    }

    /**
     * Write a plugin file from a local file or STDIN. PHP is linted first.
     *
     * ## OPTIONS
     * <path>
     * : File path relative to wp-content/plugins. Missing directories are created.
     * --code=<file>
     * : Path to the new code, or "-" for STDIN.
     * [--if-match=<sha256>]
     * : Refuse unless the current remote SHA-256 matches (file must exist).
     * [--new]
     * : Assert the file does not exist yet (refuse if it does).
     * [--dry-run]
     * : Lint and report without writing.
     *
     * ## EXAMPLES
     *   cat way-elements.php | wp agent plugin push way-elements/way-elements.php --code=- --new
     */
    public function push($args, $assoc)
    {
        $rel    = (string) ($args[0] ?? '');
        $target = $this->resolve($rel);

        $src = (string) ($assoc['code'] ?? '');
        if ($src === '') {
            Result::fail('--code is required.');
        }
        $code = $this->readCode($src);

        $exists       = is_file($target);
        $prev         = $exists ? (string) file_get_contents($target) : null;
        $previousHash = $exists ? hash('sha256', (string) $prev) : null;

        if (isset($assoc['new']) && $exists) {
            Result::fail("File already exists: {$rel} (drop --new to overwrite).");
        }

        $expectedHash = strtolower(trim((string) ($assoc['if-match'] ?? '')));
        if ($expectedHash !== '') {
            if (!$exists) {
                Result::fail("--if-match given but the file does not exist: {$rel}");
            }
            if (!hash_equals((string) $previousHash, $expectedHash)) {
                Result::fail(
                    "Remote file changed: expected {$expectedHash}, current {$previousHash}. "
                    . 'Pull or compare before pushing.'
                );
            }
        }

        $linted = false;
        if (substr($rel, -4) === '.php') {
            try {
                token_get_all($code, TOKEN_PARSE);
                $linted = true;
            } catch (\ParseError $e) {
                Result::fail('PHP syntax error, refused (nothing written): ' . $e->getMessage());
            }
        }

        $report = [
            'path'        => $rel,
            'linted'      => $linted,
            'prev_len'    => $exists ? strlen((string) $prev) : null,
            'new_len'     => strlen($code),
            'prev_sha256' => $previousHash,
            'new_sha256'  => hash('sha256', $code),
            'created'     => !$exists,
            'changed'     => !$exists || !hash_equals((string) $previousHash, hash('sha256', $code)),
        ];

        if (isset($assoc['dry-run'])) {
            Result::out($report + ['dry_run' => true]);
            return;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            Result::fail('Cannot create directory: ' . dirname($rel));
        }

        if ($exists && !copy($target, $target . self::BACKUP_SUFFIX)) {
            Result::fail('Backup copy failed, nothing written.');
        }

        $tmp = $target . '.agentconn-tmp';
        if (file_put_contents($tmp, $code) !== strlen($code)) {
            @unlink($tmp);
            Result::fail('Write failed, nothing changed.');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            Result::fail('Atomic rename failed, previous file untouched.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }

        Result::out($report + ['backup' => $exists]);
    }

    /**
     * Restore the file saved before the last `push` (one-level undo).
     *
     * ## OPTIONS
     * <path>
     * : File path relative to wp-content/plugins.
     */
    public function restore($args, $assoc)
    {
        $rel    = (string) ($args[0] ?? '');
        $target = $this->resolve($rel);
        $backup = $target . self::BACKUP_SUFFIX;
        if (!is_file($backup)) {
            Result::fail("No backup found for: {$rel}");
        }
        if (!copy($backup, $target)) {
            Result::fail('Restore copy failed.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
        Result::out([
            'path'     => $rel,
            'restored' => true,
            'bytes'    => filesize($target),
            'sha256'   => hash('sha256', (string) file_get_contents($target)),
        ]);
    }

    /**
     * Validate the path and confine it to WP_PLUGIN_DIR.
     */
    private function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] === '/' || strpos($path, '\\') !== false) {
            Result::fail('Path must be relative to wp-content/plugins (no absolute paths or backslashes).');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                Result::fail("Invalid path segment in: {$path}");
            }
        }
        if (explode('/', $path)[0] === basename(AGENT_CONNECTOR_DIR)) {
            Result::fail('Refusing to touch the connector itself: use `wp agent self update`.');
        }
        $base = realpath(WP_PLUGIN_DIR);
        if ($base === false) {
            Result::fail('Cannot resolve WP_PLUGIN_DIR.');
        }
        return $base . '/' . $path;
    }

    private function readCode(string $source): string
    {
        $code = $source === '-' ? file_get_contents('php://stdin') : @file_get_contents($source);
        if ($code === false) {
            Result::fail("Cannot read --code: {$source}");
        }
        return (string) $code;
    }
}
