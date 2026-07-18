<?php

define('ARRAY_A', 'ARRAY_A');

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
    public $last_error = '';
    public $row;

    public function __construct()
    {
        $this->row = [
            'id'            => 17,
            'title'         => 'Example',
            'codeType'      => 'php',
            'enabled'       => 1,
            'error'         => 0,
            'errorMessage'  => '',
            'lastModified'  => '1',
            'code'          => "<?php\necho 1;\n",
            'original_code' => "<?php\necho 1;\n",
        ];
    }

    public function prepare($sql, $value)
    {
        return preg_replace('/%d/', (string) (int) $value, $sql, 1);
    }

    public function get_row($sql, $format): array
    {
        return $this->row;
    }

    public function get_var($sql)
    {
        return $this->row['code'];
    }

    public function update($table, array $data, array $where)
    {
        $this->row = array_merge($this->row, $data);
        return 1;
    }
}

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

function update_option($name, $value, $autoload = null): bool
{
    return true;
}

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Wpcodebox/Commands.php';

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
$commands = new AgentConnector\Modules\Wpcodebox\Commands();
$oldHash = hash('sha256', $wpdb->row['code']);

$commands->status([17], ['field' => 'sha256']);
assert_true(end(WP_CLI::$output) === $oldHash, 'status sha256');

$file = tempnam(sys_get_temp_dir(), 'agentconn_snippet_');
file_put_contents($file, "<?php\necho 2;\n");

$commands->push([17], ['code' => $file, 'if-match' => $oldHash]);
assert_true($wpdb->row['code'] === "<?php\necho 2;\n", 'code updated');
assert_true($wpdb->row['original_code'] === $wpdb->row['code'], 'original_code updated');

expect_failure(
    static function () use ($commands, $file, $oldHash): void {
        $commands->push([17], ['code' => $file, 'if-match' => $oldHash]);
    },
    'Remote snippet changed'
);

unlink($file);
echo "wpcodebox-commands: OK\n";
