<?php

require __DIR__ . '/lib.php';

define('DB_NAME', 'test_db');

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Database/Commands.php';

$wpdb = new FakeWpdb();
$commands = new AgentConnector\Modules\Database\Commands();

$commands->query([], ['sql' => 'SELECT ID FROM {{prefix}}posts']);
assert_true(strpos(end(WP_CLI::$output), '"count": 1') !== false, 'SELECT result');
assert_true(
    $wpdb->queries[0] === 'SELECT * FROM (SELECT ID FROM wp_posts) AS agent_connector_rows LIMIT 501',
    'prefix expansion and server-side row cap'
);

expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => 'UPDATE wp_posts SET post_title = "x"']);
    },
    'Read-only query refused'
);

$before = count($wpdb->queries);
$commands->exec([], ['sql' => 'UPDATE wp_posts SET post_title = "x"', 'dry-run' => true]);
assert_true(count($wpdb->queries) === $before, 'dry-run must not execute SQL');

expect_failure(
    static function () use ($commands): void {
        $commands->exec([], ['sql' => 'DELETE FROM wp_posts']);
    },
    'Pass --dry-run'
);

$commands->exec([], ['sql' => 'UPDATE wp_posts SET post_title = "x"', 'yes' => true]);
assert_true(array_slice($wpdb->queries, -3) === [
    'START TRANSACTION',
    'UPDATE wp_posts SET post_title = "x"',
    'COMMIT',
], 'committed write transaction');

expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => 'SELECT 1; DELETE FROM wp_posts']);
    },
    'Multiple SQL statements'
);

expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => 'SELECT post_content INTO OUTFILE "/tmp/export" FROM wp_posts']);
    },
    'INTO OUTFILE'
);

// A ";" inside a string literal is data, not a statement separator.
$commands->exec([], ['sql' => "UPDATE wp_posts SET post_title = 'a;b'", 'yes' => true]);
assert_true(end($wpdb->queries) === 'COMMIT', 'semicolon inside literal allowed');

// WITH ... SELECT is allowed, including RECURSIVE, multiple CTEs, and
// parens/semicolons hidden inside literals or comments.
$commands->query([], ['sql' => 'WITH t AS (SELECT 1 AS one) SELECT one FROM t']);
assert_true(strpos(end(WP_CLI::$output), '"keyword": "WITH"') !== false, 'WITH ... SELECT allowed');

$commands->query([], ['sql' => "WITH a AS (SELECT 1), b AS (SELECT ');') SELECT * FROM a, b"]);
assert_true(strpos(end(WP_CLI::$output), '"keyword": "WITH"') !== false, 'multi-CTE, parens in literal');

$commands->query([], ['sql' => "WITH RECURSIVE t AS (SELECT 1 /* ) ; */) SELECT * FROM t"]);
assert_true(strpos(end(WP_CLI::$output), '"keyword": "WITH"') !== false, 'RECURSIVE, parens in comment');

// WITH ... UPDATE/DELETE/INSERT are valid MySQL 8.0 statements: the read
// side must refuse them exactly like plain writes, or --dry-run/--yes on
// exec would be bypassed.
expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => 'WITH t AS (SELECT 1) DELETE FROM wp_posts']);
    },
    'Read-only query refused: WITH ... DELETE'
);
expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => "WITH t AS (SELECT 1) UPDATE wp_posts SET post_title = 'x'"]);
    },
    'Read-only query refused: WITH ... UPDATE'
);
expect_failure(
    static function () use ($commands): void {
        $commands->query([], ['sql' => 'WITH t AS (SELECT 1) INSERT INTO wp_posts (post_title) SELECT * FROM t']);
    },
    'Read-only query refused: WITH ... INSERT'
);

// The exec side refuses WITH outright (only plain writes are allowed there).
expect_failure(
    static function () use ($commands): void {
        $commands->exec([], ['sql' => 'WITH t AS (SELECT 1) UPDATE wp_posts SET post_title = "x"', 'yes' => true]);
    },
    'Write refused'
);

echo "database-commands: OK\n";
