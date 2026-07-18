<?php

require __DIR__ . '/lib.php';

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Wpcodebox/Commands.php';

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

// restore: undoes the last set, but refuses when the snippet id is unknown.
$commands->restore([17], []);
assert_true($wpdb->row['code'] === "<?php\necho 1;\n", 'restore undoes last set');

$wpdb->row = null;
expect_failure(
    static function () use ($commands): void {
        $commands->restore([999], []);
    },
    'Snippet not found'
);
$wpdb->row = [
    'id' => 17, 'title' => 'Example', 'codeType' => 'php', 'enabled' => 1,
    'error' => 0, 'errorMessage' => '', 'lastModified' => '1',
    'code' => "<?php\necho 1;\n", 'original_code' => "<?php\necho 1;\n",
];

// create: invalid --type refused, PHP lint errors refused, valid row inserted disabled.
expect_failure(
    static function () use ($commands, $file): void {
        $commands->create([], ['title' => 'X', 'code' => $file, 'type' => 'python']);
    },
    'Invalid --type'
);

$bad = tempnam(sys_get_temp_dir(), 'agentconn_snippet_');
file_put_contents($bad, "<?php\nfunction broken(\n");
expect_failure(
    static function () use ($commands, $bad): void {
        $commands->create([], ['title' => 'X', 'code' => $bad]);
    },
    'PHP syntax error'
);
unlink($bad);

$commands->create([], ['title' => 'X', 'code' => $file]);
$inserted = end($wpdb->inserted);
assert_true($inserted['enabled'] === 0, 'created disabled by default');
assert_true(strlen($inserted['secret']) === 20, 'secret generated');

unlink($file);
echo "wpcodebox-commands: OK\n";
