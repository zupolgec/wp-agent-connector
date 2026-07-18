<?php

namespace AgentConnector\Modules\Wpcodebox;

use AgentConnector\Core\Result;

/**
 * Read and edit WPCodeBox snippets from the CLI.
 *
 * WPCodeBox executes snippets straight from its DB tables (no compiled file
 * cache), so a `code` update takes effect on the next request. `set` lints PHP
 * before writing (via token_get_all, without executing) and refuses on a syntax
 * error, to avoid taking the site down with a bad snippet.
 *
 *   wp agent snippet list [--type=php] [--enabled] [--folder=<id>]
 *   wp agent snippet get <id> [--field=code]
 *   cat snippet.php | wp agent snippet set <id> --code=- [--dry-run]
 *   wp agent snippet toggle <id> --on|--off
 */
class Commands
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpcb_snippets';
    }

    /**
     * List database tables that look like they belong to WPCodeBox.
     */
    public function tables($args, $assoc)
    {
        global $wpdb;
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', '%wpcodebox%'));
        $alt    = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', '%wpcb%'));
        Result::out(['tables' => array_values(array_unique(array_merge($tables, $alt)))]);
    }

    /**
     * List snippets (without code bodies).
     *
     * ## OPTIONS
     * [--type=<type>]
     * : Filter by codeType (php, css, js, html).
     * [--enabled]
     * : Only enabled snippets.
     * [--folder=<id>]
     * : Filter by folder id.
     */
    public function list($args, $assoc)
    {
        global $wpdb;
        $table  = $this->table();
        $where  = [];
        $params = [];
        if (!empty($assoc['type'])) {
            $where[]  = 'codeType = %s';
            $params[] = $assoc['type'];
        }
        if (isset($assoc['enabled'])) {
            $where[] = 'enabled = 1';
        }
        if (isset($assoc['folder'])) {
            $where[]  = 'folderId = %d';
            $params[] = (int) $assoc['folder'];
        }
        $sql = "SELECT id, title, codeType, enabled, location, runType, folderId,
                       lastModified, error, SHA2(code, 256) AS sha256
                FROM {$table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id';
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        Result::out(['count' => count($rows), 'snippets' => $rows]);
    }

    /**
     * Show a snippet. Default prints all fields as JSON; --field prints one raw.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     * [--field=<field>]
     * : Print a single field raw (e.g. --field=code for piping).
     */
    public function get($args, $assoc)
    {
        global $wpdb;
        $id  = (int) ($args[0] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            Result::fail("Snippet not found: {$id}");
        }
        if (!empty($assoc['field'])) {
            $field = $assoc['field'];
            if (!array_key_exists($field, $row)) {
                Result::fail("No such field: {$field}");
            }
            \WP_CLI::log((string) $row[$field]);
            return;
        }
        Result::out($row);
    }

    /**
     * Report the remote hash, or compare it with a local code stream.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     * [--code=<file>]
     * : Local code path, or "-" for STDIN.
     * [--field=<field>]
     * : Print one status field raw (e.g. sha256).
     */
    public function status($args, $assoc)
    {
        global $wpdb;
        $id  = (int) ($args[0] ?? 0);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, codeType, enabled, error, errorMessage, lastModified, code
                 FROM {$this->table()} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        if (!$row) {
            Result::fail("Snippet not found: {$id}");
        }

        $status = [
            'id'            => (int) $row['id'],
            'title'         => $row['title'],
            'type'          => $row['codeType'],
            'enabled'       => (int) $row['enabled'],
            'error'         => (int) $row['error'],
            'error_message' => $row['errorMessage'],
            'last_modified' => $row['lastModified'],
            'bytes'         => strlen($row['code']),
            'sha256'        => hash('sha256', $row['code']),
        ];

        if (isset($assoc['code'])) {
            $local = $this->readCode((string) $assoc['code']);
            $status['local_bytes']  = strlen($local);
            $status['local_sha256'] = hash('sha256', $local);
            $status['state']        = hash_equals($status['sha256'], $status['local_sha256'])
                ? 'in_sync'
                : 'different';
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
     * Stream a snippet's code to STDOUT for an atomic local pull.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     */
    public function pull($args, $assoc)
    {
        global $wpdb;
        $id   = (int) ($args[0] ?? 0);
        $code = $wpdb->get_var($wpdb->prepare("SELECT code FROM {$this->table()} WHERE id = %d", $id));
        if ($code === null) {
            Result::fail("Snippet not found: {$id}");
        }
        // Do not use WP_CLI::line/log here: both append a newline and would
        // make an exact pull differ from the remote bytes.
        fwrite(STDOUT, (string) $code);
    }

    /**
     * Push code from a file or STDIN. --if-match prevents overwriting a remote edit.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     * --code=<file>
     * : Path to code, or "-" for STDIN.
     * [--if-match=<sha256>]
     * : Refuse unless the current remote SHA-256 matches.
     * [--dry-run]
     * : Lint and compare without writing.
     */
    public function push($args, $assoc)
    {
        $this->set($args, $assoc);
    }

    /**
     * Replace a snippet's code. PHP is linted before writing.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     * --code=<file>
     * : Path to the new code, or "-" for STDIN. For PHP include the <?php tag.
     * [--dry-run]
     * : Lint and report without writing.
     */
    public function set($args, $assoc)
    {
        global $wpdb;
        $id  = (int) ($args[0] ?? 0);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT codeType, code, original_code FROM {$this->table()} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        if (!$row) {
            Result::fail("Snippet not found: {$id}");
        }

        $src = (string) ($assoc['code'] ?? '');
        if ($src === '') {
            Result::fail('--code is required.');
        }
        $code = $this->readCode($src);

        $previousHash = hash('sha256', $row['code']);
        $expectedHash = strtolower(trim((string) ($assoc['if-match'] ?? '')));
        if ($expectedHash !== '' && !hash_equals($previousHash, $expectedHash)) {
            Result::fail(
                "Remote snippet changed: expected {$expectedHash}, current {$previousHash}. "
                . 'Pull or compare before pushing.'
            );
        }

        $linted = false;
        if ($row['codeType'] === 'php') {
            try {
                token_get_all($code, TOKEN_PARSE);
                $linted = true;
            } catch (\ParseError $e) {
                Result::fail('PHP syntax error, refused (nothing changed): ' . $e->getMessage());
            }
        }

        if (isset($assoc['dry-run'])) {
            Result::out([
                'id'          => $id,
                'type'        => $row['codeType'],
                'linted'      => $linted,
                'prev_len'    => strlen($row['code']),
                'new_len'     => strlen($code),
                'prev_sha256' => $previousHash,
                'new_sha256'  => hash('sha256', $code),
                'changed'     => !hash_equals($previousHash, hash('sha256', $code)),
                'dry_run'     => true,
            ]);
            return;
        }

        // One-level backup of the previous code so `restore` can undo a bad edit.
        update_option(
            'agentconn_snippet_bak_' . $id,
            ['code' => $row['code'], 'original_code' => $row['original_code'], 'ts' => time()],
            false
        );

        $updated = $wpdb->update(
            $this->table(),
            [
                'code'          => $code,
                'original_code' => $code,
                'lastModified'  => (string) time(),
                'error'         => 0,
                'errorMessage'  => '',
                'errorTrace'    => '',
                'errorLine'     => 0,
            ],
            ['id' => $id]
        );
        if ($updated === false) {
            Result::fail('WPCodeBox update failed: ' . $wpdb->last_error);
        }
        Result::out([
            'id'          => $id,
            'type'        => $row['codeType'],
            'linted'      => $linted,
            'prev_len'    => strlen($row['code']),
            'new_len'     => strlen($code),
            'prev_sha256' => $previousHash,
            'new_sha256'  => hash('sha256', $code),
            'changed'     => !hash_equals($previousHash, hash('sha256', $code)),
            'backup'      => true,
        ]);
    }

    /**
     * Restore the code saved before the last `set` (one-level undo).
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     *
     * ## EXAMPLES
     *   wp agent snippet restore 19
     */
    public function restore($args, $assoc)
    {
        global $wpdb;
        $id  = (int) ($args[0] ?? 0);
        $bak = get_option('agentconn_snippet_bak_' . $id);
        if (!is_array($bak) || !isset($bak['code'])) {
            Result::fail("No backup found for snippet {$id}.");
        }
        $wpdb->update(
            $this->table(),
            [
                'code'          => $bak['code'],
                'original_code' => $bak['original_code'] ?? $bak['code'],
                'lastModified'  => (string) time(),
            ],
            ['id' => $id]
        );
        Result::out(['id' => $id, 'restored' => true, 'backup_ts' => $bak['ts'] ?? null, 'len' => strlen($bak['code'])]);
    }

    /**
     * Create a new snippet (disabled by default for safety).
     *
     * ## OPTIONS
     * --title=<title>
     * : Snippet title.
     * --code=<file>
     * : Path to the code, or "-" for STDIN. For PHP include the <?php tag.
     * [--type=<type>]
     * : codeType: php (default), css, js, html.
     * [--folder=<id>]
     * : Folder id (default 0).
     * [--enable]
     * : Create it enabled (default: disabled, so you can review first).
     *
     * ## EXAMPLES
     *   cat new.php | wp agent snippet create --title="WAY - X" --code=-
     */
    public function create($args, $assoc)
    {
        global $wpdb;
        $title = $assoc['title'] ?? '';
        if ($title === '') {
            Result::fail('--title is required.');
        }
        $src  = $assoc['code'] ?? '';
        $code = $src === '-' ? file_get_contents('php://stdin') : @file_get_contents($src);
        if ($code === false) {
            Result::fail("Cannot read --code: {$src}");
        }
        $type = $assoc['type'] ?? 'php';
        if ($type === 'php') {
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                Result::fail('PHP syntax error, refused: ' . $e->getMessage());
            }
        }

        $row = [
            'title'             => $title,
            'description'       => '',
            'enabled'           => isset($assoc['enable']) ? 1 : 0,
            'priority'          => 1,
            'runType'           => 'always',
            'code'              => $code,
            'original_code'     => $code,
            'codeType'          => $type,
            'conditions'        => '[]',
            'location'          => '',
            'tagOptions'        => '',
            'hook'              => '[{"hook":{"label":"Plugins Loaded (Default)","value":"custom_plugins_loaded"},"priority":1}]',
            'renderType'        => null,
            'minify'            => 0,
            'snippet_order'     => -1,
            'addToQuickActions' => 0,
            'savedToCloud'      => 0,
            'remoteId'          => 0,
            'externalUrl'       => 0,
            'secret'            => substr(md5(uniqid('', true)), 0, 20),
            'folderId'          => isset($assoc['folder']) ? (int) $assoc['folder'] : 0,
            'error'             => 0,
            'errorMessage'      => '',
            'errorTrace'        => '',
            'errorLine'         => 0,
            'devMode'           => 0,
            'lastModified'      => (string) time(),
        ];
        $wpdb->insert($this->table(), $row);
        Result::out(['id' => (int) $wpdb->insert_id, 'title' => $title, 'type' => $type, 'enabled' => $row['enabled']]);
    }

    /**
     * Enable or disable a snippet.
     *
     * ## OPTIONS
     * <id>
     * : Snippet id.
     * [--on]
     * : Enable.
     * [--off]
     * : Disable.
     */
    public function toggle($args, $assoc)
    {
        global $wpdb;
        $id = (int) ($args[0] ?? 0);
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE id = %d", $id))) {
            Result::fail("Snippet not found: {$id}");
        }
        $enabled = isset($assoc['on']) ? 1 : (isset($assoc['off']) ? 0 : null);
        if ($enabled === null) {
            Result::fail('Pass --on or --off.');
        }
        $wpdb->update($this->table(), ['enabled' => $enabled, 'lastModified' => (string) time()], ['id' => $id]);
        Result::out(['id' => $id, 'enabled' => $enabled]);
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
