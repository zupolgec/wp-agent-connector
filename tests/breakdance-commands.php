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

// ---- a realistic tree for outline / deep validate / patch ----------------

$heading = ['id' => 4, 'data' => ['type' => 'EssentialElements\\Heading', 'properties' => [
    'content' => ['content' => ['text' => 'Hello world']],
    'design'  => new stdClass(),
]], 'children' => []];
$column   = ['id' => 3, 'data' => ['type' => 'EssentialElements\\Column'], 'children' => [$heading]];
$columns  = ['id' => 2, 'data' => ['type' => 'EssentialElements\\Columns'], 'children' => [$column]];
$section  = ['id' => 1, 'data' => ['type' => 'EssentialElements\\Section'], 'children' => [$columns]];
$goodTree = ['root' => ['id' => 0, 'data' => ['type' => 'root'], 'children' => [$section]],
    '_nextNodeId' => 5, 'status' => 'exported'];
$goodRaw  = json_encode(['tree_json_string' => json_encode($goodTree)]);
$GLOBALS['meta'][3]['_breakdance_data'] = $goodRaw;

// outline walks the tree, strips the namespace, finds the primary text.
$commands->outline([3], []);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['nodes'] === 4, 'outline counts nodes');
assert_true($out['hash'] === hash('sha256', $goodRaw), 'outline reports content hash');
assert_true($out['tree'][0]['type'] === 'Section', 'namespace stripped');
$leaf = $out['tree'][0]['children'][0]['children'][0]['children'][0];
assert_true($leaf['type'] === 'Heading' && $leaf['text'] === 'Hello world', 'primary text surfaced');

// validate is clean on the good tree and reports node count + hash.
$commands->validate([3], []);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === true && $out['nodes'] === 4, 'good tree validates');
assert_true($out['warnings'] === [], 'no warnings on pure EssentialElements tree');

// Deep validation: duplicate ids, nesting violation, third-party namespace,
// stale _nextNodeId.
$rogue   = ['id' => 2, 'data' => ['type' => 'Foo\\Bar'], 'children' => []];
$badTree = ['root' => ['id' => 0, 'children' => [
    ['id' => 2, 'data' => ['type' => 'EssentialElements\\Columns'], 'children' => [$rogue]],
]], '_nextNodeId' => 2, 'status' => 'exported'];
$GLOBALS['meta'][3]['_breakdance_data'] = json_encode(['tree_json_string' => json_encode($badTree)]);
$commands->validate([3], []);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === false, 'structural problems invalidate');
assert_true(in_array('duplicate node id 2', $out['issues'], true), 'duplicate id reported');
assert_true((bool) preg_grep('/Columns may only contain Column/', $out['issues']), 'nesting rule enforced');
assert_true((bool) preg_grep('/_nextNodeId \(2\) must be greater/', $out['issues']), 'stale _nextNodeId reported');
assert_true((bool) preg_grep('/Foo\\\\Bar/', $out['warnings']), 'third-party namespace is a warning, not an issue');

// Overwrite gate: set on a post with existing data needs --replace or a hash.
$GLOBALS['meta'][3]['_breakdance_data'] = $goodRaw;
file_put_contents($bad, $goodRaw);
expect_failure(
    static function () use ($commands, $bad): void {
        $commands->set([3], ['data' => $bad]);
    },
    'already has Breakdance data'
);
expect_failure(
    static function () use ($commands, $bad): void {
        $commands->set([3], ['data' => $bad, 'expect-hash' => str_repeat('0', 64)]);
    },
    'Hash mismatch'
);
$commands->set([3], ['data' => $bad, 'expect-hash' => hash('sha256', $goodRaw)]);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === true && $out['hash'] === hash('sha256', $goodRaw), 'matching hash allows the write');
$commands->set([3], ['data' => $bad, 'replace' => true]);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === true, '--replace allows the write');

// patch deep-merges into a single node and preserves empty JSON objects.
$patchFile = tempnam(sys_get_temp_dir(), 'agentconn_bd_patch_');
file_put_contents($patchFile, '{"data":{"properties":{"content":{"content":{"text":"New title"}}}}}');
$commands->patch([3, 4], ['data' => $patchFile]);
$out = json_decode(end(WP_CLI::$output), true);
assert_true($out['valid'] === true && $out['node'] === 4, 'patch applied and revalidated');
$stored    = $GLOBALS['meta'][3]['_breakdance_data'];
$innerJson = json_decode($stored)->tree_json_string;
assert_true(strpos($innerJson, '"design":{}') !== false, 'empty objects survive the round trip');
$inner = json_decode($innerJson);
$text  = $inner->root->children[0]->children[0]->children[0]->children[0]->data->properties->content->content->text;
assert_true($text === 'New title', 'node text updated in place');
assert_true($out['hash'] === hash('sha256', $stored), 'patch reports the new hash');

// patch refuses unknown nodes and stale hashes.
expect_failure(
    static function () use ($commands, $patchFile): void {
        $commands->patch([3, 999], ['data' => $patchFile]);
    },
    'Node not found'
);
expect_failure(
    static function () use ($commands, $patchFile): void {
        $commands->patch([3, 4], ['data' => $patchFile, 'expect-hash' => str_repeat('0', 64)]);
    },
    'Hash mismatch'
);

unlink($bad);
unlink($patchFile);
echo "breakdance-commands: OK\n";
