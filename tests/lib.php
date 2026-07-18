<?php

/**
 * Shared test doubles for the standalone command tests (no WordPress loaded).
 * Each test file runs in its own PHP process and defines the extra WP stubs
 * it needs on top of these.
 */

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

class WP_Error
{
    private $message;

    public function __construct(string $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
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
    public $row;
    public $inserted = [];

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

    public function get_results($sql, $format): array
    {
        $this->queries[] = $sql;
        return [['ID' => '1', 'post_title' => 'Hello']];
    }

    public function get_row($sql, $format): ?array
    {
        return $this->row;
    }

    public function get_col($sql): array
    {
        return [];
    }

    public function get_var($sql)
    {
        return $this->row['code'] ?? null;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return stripos($sql, 'UPDATE ') === 0 ? 2 : 0;
    }

    public function update($table, array $data, array $where)
    {
        if ($this->row === null) {
            return 0;
        }
        $this->row = array_merge($this->row, $data);
        return 1;
    }

    public function insert($table, array $data)
    {
        $this->inserted[] = $data;
        $this->insert_id  = 123;
        return 1;
    }

    public function prepare($sql, $params)
    {
        foreach ((array) $params as $param) {
            $replacement = is_numeric($param)
                ? (string) $param
                : "'" . addslashes((string) $param) . "'";
            $sql = preg_replace('/%[sdf]/', $replacement, $sql, 1);
        }
        return $sql;
    }
}

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

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

$GLOBALS['fake_options'] = ['siteurl' => 'https://test.example.test'];

function get_option($name, $default = false)
{
    return $GLOBALS['fake_options'][$name] ?? $default;
}

function update_option($name, $value, $autoload = null): bool
{
    $GLOBALS['fake_options'][$name] = $value;
    return true;
}

function wp_cache_flush(): void
{
}

function wp_generate_password($length = 12, $special_chars = true): string
{
    return str_repeat('a', (int) $length);
}

function get_file_data($file, $headers, $context = ''): array
{
    return array_map(static fn () => '', $headers);
}
