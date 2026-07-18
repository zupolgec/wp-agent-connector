<?php

namespace AgentConnector\Modules\Database;

use AgentConnector\Core\Result;

/**
 * Guarded SQL access for agent operations.
 *
 * Read commands and write commands are deliberately separate. Both accept SQL
 * over STDIN, support prepared placeholders via --params JSON, replace
 * {{prefix}} / {{base_prefix}}, and reject multiple statements.
 */
class Commands
{
    /**
     * Show database identity without credentials.
     */
    public function info($args, $assoc)
    {
        global $wpdb;
        Result::out([
            'siteurl'     => get_option('siteurl'),
            'database'    => DB_NAME,
            'prefix'      => $wpdb->prefix,
            'base_prefix' => $wpdb->base_prefix,
            'charset'     => $wpdb->charset,
            'collate'     => $wpdb->collate,
        ]);
    }

    /**
     * Run one read-only SQL statement.
     *
     * ## OPTIONS
     * --sql=<sql>
     * : SQL text, or "-" for STDIN. Allowed: SELECT, SHOW, DESCRIBE, DESC, EXPLAIN.
     * [--params=<json>]
     * : JSON array passed to $wpdb->prepare().
     * [--max-rows=<n>]
     * : Maximum rows emitted (default 500, maximum 5000).
     */
    public function query($args, $assoc)
    {
        global $wpdb;
        $sql     = $this->preparedSql($assoc);
        $keyword = $this->keyword($sql);
        if (!in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
            Result::fail("Read-only query refused: {$keyword}. Use db exec for data writes.");
        }
        if (preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $sql)) {
            Result::fail('SELECT INTO OUTFILE/DUMPFILE is not read-only and is refused.');
        }

        $max          = max(1, min(5000, (int) ($assoc['max-rows'] ?? 500)));
        $executionSql = $sql;
        if ($keyword === 'SELECT') {
            $inner        = rtrim(rtrim($sql), "; \t\n\r\0\x0B");
            $executionSql = "SELECT * FROM ({$inner}) AS agent_connector_rows LIMIT " . ($max + 1);
        }

        $rows = $wpdb->get_results($executionSql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            Result::fail('SQL error: ' . $wpdb->last_error);
        }

        $returned  = count($rows);
        $truncated = $returned > $max;
        if ($truncated) {
            $rows = array_slice($rows, 0, $max);
        }

        Result::out([
            'keyword'   => $keyword,
            'count'     => count($rows),
            'truncated' => $truncated,
            'rows'      => $rows,
        ]);
    }

    /**
     * Run one data-changing SQL statement.
     *
     * ## OPTIONS
     * --sql=<sql>
     * : SQL text, or "-" for STDIN. Allowed: INSERT, UPDATE, DELETE, REPLACE.
     * [--params=<json>]
     * : JSON array passed to $wpdb->prepare().
     * [--dry-run]
     * : Validate and report without executing.
     * [--yes]
     * : Required to commit a write.
     */
    public function exec($args, $assoc)
    {
        global $wpdb;
        $sql     = $this->preparedSql($assoc);
        $keyword = $this->keyword($sql);
        if (!in_array($keyword, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) {
            Result::fail("Write refused: {$keyword}. Schema and administrative statements are not allowed.");
        }

        $dryRun = isset($assoc['dry-run']);
        if ($dryRun) {
            Result::out([
                'keyword'    => $keyword,
                'sql_sha256' => hash('sha256', $sql),
                'committed'  => false,
                'executed'   => false,
                'dry_run'    => true,
            ]);
            return;
        }
        if (!isset($assoc['yes'])) {
            Result::fail('Pass --dry-run to test or --yes to commit the write.');
        }

        $wpdb->query('START TRANSACTION');
        $affected = $wpdb->query($sql);
        if ($affected === false || $wpdb->last_error !== '') {
            $error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            Result::fail('SQL error; rolled back: ' . $error);
        }

        $wpdb->query('COMMIT');
        wp_cache_flush();

        Result::out([
            'keyword'   => $keyword,
            'affected'  => (int) $affected,
            'insert_id' => (int) $wpdb->insert_id,
            'committed' => true,
            'executed'  => true,
            'dry_run'   => false,
        ]);
    }

    private function preparedSql(array $assoc): string
    {
        global $wpdb;
        if (!array_key_exists('sql', $assoc)) {
            Result::fail('--sql is required.');
        }

        $source = (string) $assoc['sql'];
        $sql    = $source === '-' ? file_get_contents('php://stdin') : $source;
        if ($sql === false || trim($sql) === '') {
            Result::fail('SQL input is empty.');
        }

        $sql = str_replace(
            ['{{prefix}}', '{{base_prefix}}'],
            [$wpdb->prefix, $wpdb->base_prefix],
            trim((string) $sql)
        );
        $this->assertSingleStatement($sql);

        if (isset($assoc['params'])) {
            $params = json_decode((string) $assoc['params'], true);
            if (!is_array($params) || array_values($params) !== $params) {
                Result::fail('--params must be a JSON array.');
            }
            $prepared = $wpdb->prepare($sql, $params);
            if (!is_string($prepared)) {
                Result::fail('Could not prepare SQL with the supplied parameters.');
            }
            $sql = $prepared;
        }
        return $sql;
    }

    private function keyword(string $sql): string
    {
        $clean = preg_replace(
            '/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*.*?\*\/)+/s',
            '',
            $sql
        );
        if (!preg_match('/\A([a-z]+)/i', (string) $clean, $match)) {
            Result::fail('Could not determine SQL statement type.');
        }
        return strtoupper($match[1]);
    }

    private function assertSingleStatement(string $sql): void
    {
        $withoutTrailing = rtrim(rtrim($sql), "; \t\n\r\0\x0B");
        if (strpos($withoutTrailing, ';') !== false) {
            Result::fail('Multiple SQL statements are not allowed.');
        }
    }
}
