<?php

define('ARRAY_A', 'ARRAY_A');
define('DB_NAME', 'test_db');

class WP_CLI
{
    public static $output = [];

    public static function log($message): void
    {
        self::$output[] = $message;
    }

    public static function error($message): void
    {
        throw new RuntimeException($message);
    }
}

class FakeWpdb
{
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $charset = 'utf8mb4';
    public $collate = 'utf8mb4_unicode_ci';
    public $last_error = '';
    public $insert_id = 0;
    public $queries = [];

    public function get_results($sql, $format): array
    {
        $this->queries[] = $sql;
        return [['ID' => '1', 'post_title' => 'Hello']];
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return stripos($sql, 'UPDATE ') === 0 ? 2 : 0;
    }

    public function prepare($sql, $params)
    {
        foreach ((array) $params as $param) {
            $replacement = is_numeric($param) ? (string) $param : "'" . addslashes((string) $param) . "'";
            $sql = preg_replace('/%[sdf]/', $replacement, $sql, 1);
        }
        return $sql;
    }
}

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

function get_option($name)
{
    return $name === 'siteurl' ? 'https://test.example.test' : null;
}

function wp_cache_flush(): void
{
}

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Database/Commands.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function expect_failure(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        assert_true(strpos($error->getMessage(), $contains) !== false, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure containing: ' . $contains);
}

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

echo "database-commands: OK\n";
