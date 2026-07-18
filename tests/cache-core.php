<?php

require __DIR__ . '/lib.php';

$GLOBALS['purged'] = [];

function spinupwp_purge_post($id): void
{
    $GLOBALS['purged'][] = ['post', $id];
}

function spinupwp_purge_url($url): void
{
    $GLOBALS['purged'][] = ['url', $url];
}

function spinupwp_purge_site(): void
{
    $GLOBALS['purged'][] = ['site'];
}

require dirname(__DIR__) . '/src/Core/Cache.php';

use AgentConnector\Core\Cache;

assert_true(Cache::providers() === ['spinupwp'], 'spinupwp provider detected');

$result = Cache::purgePost(5);
assert_true($result['purged'] === ['spinupwp:post'], 'purgePost result');
assert_true($GLOBALS['purged'] === [['post', 5]], 'purgePost called provider');

$result = Cache::purgeUrl('https://x.test/a');
assert_true($result['purged'] === ['spinupwp:url'], 'purgeUrl result');

$result = Cache::purgeSite();
assert_true($result['purged'] === ['spinupwp:site'], 'purgeSite result');
assert_true(count($GLOBALS['purged']) === 3, 'all purges recorded');

echo "cache-core: OK\n";
