<?php

require __DIR__ . '/lib.php';

$GLOBALS['posts'] = [
    7 => (object) ['ID' => 7, 'post_status' => 'draft', 'post_date' => '2025-01-01 10:00:00', 'post_name' => 'hello'],
];
$GLOBALS['inserted_post'] = null;
$GLOBALS['updates'] = [];

function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}

function get_post_status($id)
{
    $post = get_post($id);
    return $post ? $post->post_status : false;
}

function get_permalink($id)
{
    return 'https://test.example.test/?p=' . $id;
}

function get_gmt_from_date($date)
{
    return $date;
}

function wp_insert_post($postarr, $wp_error = false)
{
    $id = $postarr['ID'] ?? 42;
    $post = (object) [
        'ID'          => $id,
        'post_status' => $postarr['post_status'] ?? 'draft',
        'post_date'   => $postarr['post_date'] ?? '',
        'post_name'   => 'slug-' . $id,
    ];
    $GLOBALS['posts'][$id]   = $post;
    $GLOBALS['inserted_post'] = $post;
    return $id;
}

function wp_update_post($postarr)
{
    $GLOBALS['updates'][] = $postarr;
    $post = $GLOBALS['posts'][$postarr['ID']];
    foreach ($postarr as $key => $value) {
        $post->$key = $value;
    }
    return $postarr['ID'];
}

require dirname(__DIR__) . '/src/Core/Result.php';
require dirname(__DIR__) . '/src/Modules/Content/Commands.php';

$commands = new AgentConnector\Modules\Content\Commands();

// --id must reference an existing post (wp_insert_post would silently create).
expect_failure(
    static function () use ($commands): void {
        $commands->bundle([], ['id' => 999, 'title' => 'X']);
    },
    'Post not found'
);
assert_true($GLOBALS['inserted_post'] === null, 'nothing inserted on unknown --id');

// Malformed dates are refused before any write.
expect_failure(
    static function () use ($commands): void {
        $commands->bundle([], ['title' => 'X', 'date' => '2025-13-40 99:99:99']);
    },
    'Invalid --date'
);
expect_failure(
    static function () use ($commands): void {
        $commands->publish([7], ['date' => 'yesterday']);
    },
    'Invalid --date'
);

// Impossible calendar dates fail the strtotime roundtrip.
expect_failure(
    static function () use ($commands): void {
        $commands->publish([7], ['date' => '2025-02-30 10:00:00']);
    },
    'Invalid --date'
);

// Happy path: bundle on an existing post keeps the status and applies the date.
$commands->bundle([], ['id' => 7, 'title' => 'New title', 'date' => '2025-10-11 11:00:00']);
assert_true($GLOBALS['posts'][7]->post_date === '2025-10-11 11:00:00', 'bundle date applied');

// Publish with a real date re-asserts the date after the status change.
$GLOBALS['updates'] = [];
$commands->publish([7], ['date' => '2025-11-05 11:00:00']);
assert_true($GLOBALS['posts'][7]->post_status === 'publish', 'published');
assert_true($GLOBALS['posts'][7]->post_date === '2025-11-05 11:00:00', 'date survives publish');
assert_true(count($GLOBALS['updates']) === 2, 'date re-asserted after publish');

echo "content-commands: OK\n";
