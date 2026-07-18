<?php

require __DIR__ . '/lib.php';

$GLOBALS['posts'] = [3 => (object) ['ID' => 3]];
$GLOBALS['meta']  = [];

function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}

function get_post_meta($id, $key, $single)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}

function update_post_meta($id, $key, $value)
{
    $GLOBALS['meta'][$id][$key] = $value;
    return true;
}

function wp_slash($value)
{
    return $value;
}

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Core/Cache.php';
require dirname(__DIR__) . '/src/Modules/Breakdance/Commands.php';

$commands = new AgentConnector\Modules\Breakdance\Commands();

// get refuses unknown posts instead of printing empty meta.
expect_failure(
    static function () use ($commands): void {
        $commands->get([999], []);
    },
    'Post not found'
);

// validate reports the nested tree structure problems.
$GLOBALS['meta'][3]['_breakdance_data'] = json_encode(['tree_json_string' => json_encode(['root' => []])]);
$commands->validate([3], []);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === false, 'missing fields invalid');
assert_true(in_array('tree missing _nextNodeId', $out['issues'], true), '_nextNodeId issue reported');

// set refuses broken data without --force and writes nothing.
$bad = tempnam(sys_get_temp_dir(), 'agentconn_bd_');
file_put_contents($bad, $GLOBALS['meta'][3]['_breakdance_data']);
$GLOBALS['meta'][3]['_breakdance_data'] = '';
expect_failure(
    static function () use ($commands, $bad): void {
        $commands->set([3], ['data' => $bad]);
    },
    'Breakdance data invalid'
);
assert_true($GLOBALS['meta'][3]['_breakdance_data'] === '', 'nothing written on invalid data');

// Valid data passes and triggers the regen + purge pipeline.
$tree = ['root' => new stdClass(), '_nextNodeId' => 5, 'status' => 'exported'];
file_put_contents($bad, json_encode(['tree_json_string' => json_encode($tree)]));
$commands->set([3], ['data' => $bad]);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === true, 'valid data accepted');
assert_true($out['cache'] === 'no-regen-function-found', 'regen auto-detection reported');
assert_true($out['purged'] === [], 'no purge provider in this environment');

unlink($bad);
echo "breakdance-commands: OK\n";
