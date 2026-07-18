<?php

require __DIR__ . '/lib.php';

// Stubs for the WP filesystem functions installFrom relies on.
function copy_dir($from, $to)
{
    if (!is_dir($from)) {
        return new WP_Error('missing source');
    }
    mkdir($to, 0777, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $dest = $to . '/' . $items->getSubPathName();
        $item->isDir() ? @mkdir($dest, 0777, true) : copy($item->getRealPath(), $dest);
    }
    return true;
}

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Self/Commands.php';

// installFrom is private and keyed to AGENT_CONNECTOR_DIR, so define the
// constant to a sandbox and drive the method via reflection.
$sandbox = sys_get_temp_dir() . '/agentconn_self_' . uniqid();
mkdir($sandbox . '/plugins/wp-agent-connector', 0777, true);
define('AGENT_CONNECTOR_DIR', $sandbox . '/plugins/wp-agent-connector');
define('AGENT_CONNECTOR_FILE', AGENT_CONNECTOR_DIR . '/agent-connector.php');

file_put_contents(AGENT_CONNECTOR_DIR . '/agent-connector.php', 'old');
file_put_contents(AGENT_CONNECTOR_DIR . '/stale-file.php', 'stale');

$release = $sandbox . '/release';
mkdir($release);
file_put_contents($release . '/agent-connector.php', 'new');

$commands = new AgentConnector\Modules\Self\Commands();
$method = new ReflectionMethod($commands, 'installFrom');
$method->setAccessible(true);

$error = $method->invoke($commands, $release);
assert_true($error === null, 'installFrom succeeded');
assert_true(file_get_contents(AGENT_CONNECTOR_DIR . '/agent-connector.php') === 'new', 'new version in place');
assert_true(!file_exists(AGENT_CONNECTOR_DIR . '/stale-file.php'), 'stale files removed by clean swap');
assert_true(count(glob($sandbox . '/plugins/.agentconn-*')) === 0, 'no staging/backup dirs left behind');

// Failure path: an unreadable source reports an error and changes nothing.
file_put_contents(AGENT_CONNECTOR_DIR . '/agent-connector.php', 'current');
$error = $method->invoke($commands, $sandbox . '/does-not-exist');
assert_true(strpos((string) $error, 'Copy failed') !== false, 'copy failure reported');
assert_true(file_get_contents(AGENT_CONNECTOR_DIR . '/agent-connector.php') === 'current', 'target untouched on failure');

// Cleanup.
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
}
rmdir($sandbox);

echo "self-commands: OK\n";
