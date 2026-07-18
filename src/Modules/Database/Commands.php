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
     * : SQL text, or "-" for STDIN. Allowed: SELECT, SHOW, DESCRIBE, DESC,
     *   EXPLAIN, and WITH only when the statement after the CTE definitions
     *   is a SELECT (MySQL 8.0 also allows WITH ... UPDATE/DELETE; refused).
     * [--params=<json>]
     * : JSON array passed to $wpdb->prepare().
     * [--max-rows=<n>]
     * : Maximum rows emitted (default 500, maximum 5000). Enforced
     *   server-side only for plain SELECT statements.
     */
    public function query($args, $assoc)
    {
        global $wpdb;
        $sql     = $this->preparedSql($assoc);
        $keyword = $this->keyword($sql);
        if ($keyword === 'WITH') {
            // MySQL 8.0 allows WITH ... UPDATE/DELETE as well as WITH ...
            // SELECT, so the read-only guarantee depends on the statement
            // after the CTE definitions, not on the first keyword.
            $main = $this->mainStatementKeyword($sql);
            if ($main !== 'SELECT') {
                Result::fail("Read-only query refused: WITH ... {$main}. Use db exec for data writes.");
            }
        } elseif (!in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
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
        // Blank out literals and comments first, so a ";" inside a string or
        // a comment is not mistaken for a statement separator.
        $withoutTrailing = rtrim(rtrim($this->stripIgnored($sql)), "; \t\n\r\0\x0B");
        if (strpos($withoutTrailing, ';') !== false) {
            Result::fail('Multiple SQL statements are not allowed.');
        }
    }

    /**
     * SQL with quoted literals and comments blanked out (MySQL string
     * escapes: '' and \'). Structure survives; string contents cannot
     * confuse the separator/CTE scans. Multi-statements are also refused by
     * mysqli itself, which never runs multi_query — defense in depth.
     */
    private function stripIgnored(string $sql): string
    {
        $stripped = preg_replace(
            '/\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|\\\\.|[^"\\\\])*"/s',
            "''",
            $sql
        );
        $stripped = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\//s', ' ', (string) $stripped);
        return $stripped === null ? $sql : $stripped;
    }

    /**
     * Keyword of the statement that follows a WITH clause.
     *
     * Scans a copy of the SQL with literals and comments blanked out, so
     * parens or keywords inside strings cannot confuse the walk. Skips each
     * CTE definition — name [(cols)] AS [NOT] [MATERIALIZED] (subquery) —
     * with real parenthesis matching, then returns the next keyword. Any
     * parse failure is a refusal: this guard fails closed.
     */
    private function mainStatementKeyword(string $sql): string
    {
        $s = $this->stripIgnored($sql);

        if (!preg_match('/^\s*WITH\b/i', $s, $m)) {
            Result::fail('Could not parse the WITH clause.');
        }
        $i = strlen($m[0]);

        if (preg_match('/\G\s*RECURSIVE\b/i', $s, $m, 0, $i)) {
            $i += strlen($m[0]);
        }

        while (true) {
            if (!preg_match('/\G\s*(?:`[^`]+`|[a-zA-Z_][a-zA-Z0-9_$]*)\s*/', $s, $m, 0, $i)) {
                Result::fail('Could not parse the WITH clause (CTE name).');
            }
            $i += strlen($m[0]);
            $i = $this->skipParens($s, $i, false); // optional column list
            if (!preg_match('/\G\s*AS\b/i', $s, $m, 0, $i)) {
                Result::fail('Could not parse the WITH clause (AS).');
            }
            $i += strlen($m[0]);
            if (preg_match('/\G\s*(?:NOT\s+)?MATERIALIZED\b/i', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
            }
            $i = $this->skipParens($s, $i, true); // subquery, required
            if (preg_match('/\G\s*,/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                continue;
            }
            break;
        }

        if (!preg_match('/\G\s*([a-zA-Z]+)/', $s, $m, 0, $i)) {
            Result::fail('Could not determine the statement after the WITH clause.');
        }
        return strtoupper($m[1]);
    }

    private function skipParens(string $s, int $i, bool $required): int
    {
        $len = strlen($s);
        while ($i < $len && ctype_space($s[$i])) {
            $i++;
        }
        if ($i >= $len || $s[$i] !== '(') {
            if ($required) {
                Result::fail('Could not parse the WITH clause (subquery).');
            }
            return $i;
        }
        $depth = 0;
        for (; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        Result::fail('Unbalanced parentheses in the WITH clause.');
    }
}
