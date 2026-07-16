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
        $sql = "SELECT id, title, codeType, enabled, location, runType, folderId, lastModified FROM {$table}";
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
        $row = $wpdb->get_row($wpdb->prepare("SELECT codeType, code FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            Result::fail("Snippet not found: {$id}");
        }

        $src  = $assoc['code'] ?? '';
        $code = $src === '-' ? file_get_contents('php://stdin') : @file_get_contents($src);
        if ($code === false) {
            Result::fail("Cannot read --code: {$src}");
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
            Result::out(['id' => $id, 'type' => $row['codeType'], 'linted' => $linted,
                'prev_len' => strlen($row['code']), 'new_len' => strlen($code), 'dry_run' => true]);
            return;
        }

        $wpdb->update(
            $this->table(),
            ['code' => $code, 'lastModified' => (string) time(), 'error' => 0, 'errorMessage' => '', 'errorTrace' => '', 'errorLine' => 0],
            ['id' => $id]
        );
        Result::out(['id' => $id, 'type' => $row['codeType'], 'linted' => $linted,
            'prev_len' => strlen($row['code']), 'new_len' => strlen($code)]);
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
}
