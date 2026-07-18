<?php

require __DIR__ . '/lib.php';

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Plugins/Commands.php';

// Sandbox WP_PLUGIN_DIR in a temp directory.
$root = sys_get_temp_dir() . '/agentconn_plugins_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0755, true);
}
define('WP_PLUGIN_DIR', $root);
define('AGENT_CONNECTOR_DIR', $root . '/wp-agent-connector');

function wp_mkdir_p($dir)
{
    return is_dir($dir) || mkdir($dir, 0755, true);
}

$commands = new AgentConnector\Modules\Plugins\Commands();

$one = "<?php\necho 1;\n";
$two = "<?php\necho 2;\n";

$local = tempnam(sys_get_temp_dir(), 'agentconn_pl_');
file_put_contents($local, $one);

// push --new creates the file (directories included) and lints it.
$commands->push(['demo/demo.php'], ['code' => $local, 'new' => true]);
assert_true(is_file($root . '/demo/demo.php'), 'file created');
assert_true(file_get_contents($root . '/demo/demo.php') === $one, 'content written');

// status --field=sha256 matches the bytes on disk.
WP_CLI::$output = [];
$commands->status(['demo/demo.php'], ['field' => 'sha256']);
$sha = end(WP_CLI::$output);
assert_true($sha === hash('sha256', $one), 'status sha256');

// --new on an existing file refuses.
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['demo/demo.php'], ['code' => $local, 'new' => true]);
    },
    'already exists'
);

// A stale --if-match refuses.
file_put_contents($local, $two);
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['demo/demo.php'], ['code' => $local, 'if-match' => str_repeat('0', 64)]);
    },
    'Remote file changed'
);

// The right --if-match updates and leaves a backup.
$commands->push(['demo/demo.php'], ['code' => $local, 'if-match' => $sha]);
assert_true(file_get_contents($root . '/demo/demo.php') === $two, 'content updated');
assert_true(is_file($root . '/demo/demo.php.agentconn-bak'), 'backup exists');

// restore undoes the last push.
$commands->restore(['demo/demo.php'], []);
assert_true(file_get_contents($root . '/demo/demo.php') === $one, 'restored');

// Invalid PHP is refused before touching the file.
file_put_contents($local, "<?php if ( {\n");
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['demo/demo.php'], ['code' => $local]);
    },
    'syntax error'
);
assert_true(file_get_contents($root . '/demo/demo.php') === $one, 'file untouched after lint failure');

// Path traversal and absolute paths are refused.
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['../evil.php'], ['code' => $local]);
    },
    'Invalid path segment'
);
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['/abs.php'], ['code' => $local]);
    },
    'relative to wp-content/plugins'
);

// The connector's own directory is off-limits.
expect_failure(
    static function () use ($commands, $local): void {
        $commands->push(['wp-agent-connector/agent-connector.php'], ['code' => $local]);
    },
    'connector itself'
);

echo "plugins-commands: OK\n";
