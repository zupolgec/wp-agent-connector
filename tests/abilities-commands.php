<?php

require __DIR__ . '/lib.php';

class FakeAbility
{
    public $name;
    public $meta;
    public $executed = [];
    public $result;
    public $permission = true;
    public $inputSchema = ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']]];

    public function __construct(string $name, array $annotations, $result = ['ok' => 1])
    {
        $this->name   = $name;
        $this->meta   = ['annotations' => $annotations];
        $this->result = $result;
    }

    public function get_name() { return $this->name; }
    public function get_label() { return ucfirst($this->name); }
    public function get_description() { return 'Description of ' . $this->name; }
    public function get_category() { return explode('/', $this->name)[0]; }
    public function get_input_schema() { return $this->inputSchema; }
    public function get_output_schema() { return ['type' => 'object']; }
    public function get_meta() { return $this->meta; }

    public function validate_input($input = null)
    {
        if (isset($input['post_id']) && !is_int($input['post_id'])) {
            return new WP_Error('post_id must be an integer');
        }
        return true;
    }

    public function check_permissions($input = null)
    {
        return $this->permission;
    }

    public function execute($input = null)
    {
        $this->executed[] = $input;
        return $this->result;
    }
}

$GLOBALS['fake_abilities'] = [
    'core/get-site-info'   => new FakeAbility('core/get-site-info', ['readonly' => true]),
    'demo/get-tree'        => new FakeAbility('demo/get-tree', ['readonly' => true], ['root' => []]),
    'demo/edit-post'       => new FakeAbility('demo/edit-post', ['readonly' => null, 'destructive' => true]),
    'demo/fails'           => new FakeAbility('demo/fails', ['readonly' => true], new WP_Error('boom')),
];
$GLOBALS['current_user'] = 1;
$GLOBALS['purged'] = [];

function wp_get_abilities() { return $GLOBALS['fake_abilities']; }
function wp_get_ability($name) { return $GLOBALS['fake_abilities'][$name] ?? null; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function spinupwp_purge_post($id) { $GLOBALS['purged'][] = $id; }

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Core/Cache.php';
require dirname(__DIR__) . '/src/Modules/Abilities/Commands.php';

$commands = new AgentConnector\Modules\Abilities\Commands();
$last = static function () {
    return json_decode(end(WP_CLI::$output), true);
};

// list: every ability with its access mode; --namespace filters.
WP_CLI::$output = [];
$commands->list([], []);
$rows = $last();
assert_true(count($rows) === 4, 'list returns all abilities');
assert_true($rows[2]['name'] === 'demo/edit-post' && $rows[2]['mode'] === 'write', 'write ability flagged');
assert_true($rows[1]['mode'] === 'read', 'readonly ability flagged');

WP_CLI::$output = [];
$commands->list([], ['namespace' => 'core']);
assert_true(count($last()) === 1, 'namespace filter');

// schema: input schema and annotations of one ability.
WP_CLI::$output = [];
$commands->schema(['demo/edit-post'], []);
$schema = $last();
assert_true($schema['input_schema']['properties']['post_id']['type'] === 'integer', 'schema input');
assert_true($schema['mode'] === 'write', 'schema mode');

expect_failure(static function () use ($commands) {
    $commands->schema(['demo/missing'], []);
}, 'Unknown ability');

$input = tempnam(sys_get_temp_dir(), 'agentconn_ab_');

// run: a readonly ability executes without --yes and prints the result.
file_put_contents($input, '{"post_id": 5}');
WP_CLI::$output = [];
$commands->run(['demo/get-tree'], ['input' => $input]);
assert_true($last()['result'] === ['root' => []], 'readonly run result');
assert_true($GLOBALS['fake_abilities']['demo/get-tree']->executed === [['post_id' => 5]], 'readonly executed with input');

// run: no input means no argument (abilities without input schema)...
$GLOBALS['fake_abilities']['core/get-site-info']->inputSchema = [];
$commands->run(['core/get-site-info'], []);
assert_true($GLOBALS['fake_abilities']['core/get-site-info']->executed === [null], 'no input passes null');

// ...but an empty object when the schema expects one (e.g. breakdance/get-css-selectors).
$GLOBALS['fake_abilities']['demo/get-tree']->executed = [];
$commands->run(['demo/get-tree'], []);
assert_true($GLOBALS['fake_abilities']['demo/get-tree']->executed === [[]], 'no input passes {} for object schema');

// run: a write ability refuses without --yes and does not execute.
expect_failure(static function () use ($commands, $input) {
    $commands->run(['demo/edit-post'], ['input' => $input]);
}, '--yes');
assert_true($GLOBALS['fake_abilities']['demo/edit-post']->executed === [], 'write not executed without --yes');

// run --dry-run: validates input and permissions, never executes.
WP_CLI::$output = [];
$commands->run(['demo/edit-post'], ['input' => $input, 'dry-run' => true]);
assert_true($last()['dry_run'] === true && $last()['valid'] === true, 'dry run reports valid');
assert_true($GLOBALS['fake_abilities']['demo/edit-post']->executed === [], 'dry run does not execute');

file_put_contents($input, '{"post_id": "x"}');
expect_failure(static function () use ($commands, $input) {
    $commands->run(['demo/edit-post'], ['input' => $input, 'dry-run' => true]);
}, 'post_id must be an integer');

// run --yes: executes the write and purges the page cache of the post it touched.
file_put_contents($input, '{"post_id": 7}');
WP_CLI::$output = [];
$commands->run(['demo/edit-post'], ['input' => $input, 'yes' => true]);
assert_true($GLOBALS['fake_abilities']['demo/edit-post']->executed === [['post_id' => 7]], 'write executed with --yes');
assert_true($GLOBALS['purged'] === [7], 'cache purged for the post');
assert_true($last()['purged'] === ['spinupwp:post'], 'purge reported');

// Failures: invalid JSON, WP_Error result, no user, permission denied.
file_put_contents($input, '{not json');
expect_failure(static function () use ($commands, $input) {
    $commands->run(['demo/get-tree'], ['input' => $input]);
}, 'Invalid JSON');

expect_failure(static function () use ($commands) {
    $commands->run(['demo/fails'], []);
}, 'boom');

$GLOBALS['current_user'] = 0;
expect_failure(static function () use ($commands) {
    $commands->run(['demo/get-tree'], []);
}, '--user');
$GLOBALS['current_user'] = 1;

$GLOBALS['fake_abilities']['demo/get-tree']->permission = false;
expect_failure(static function () use ($commands) {
    $commands->run(['demo/get-tree'], []);
}, 'Permission denied');

unlink($input);
echo "abilities-commands: OK\n";
