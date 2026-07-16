<?php

namespace AgentConnector\Modules\Breakdance;

use AgentConnector\Core\Result;

/**
 * Breakdance helpers. The key one is `set`: after writing _breakdance_data you
 * MUST regenerate the post's CSS cache, otherwise the builder output renders
 * without styles.
 */
class Commands
{
    /**
     * Output the raw _breakdance_data of a post.
     *
     * ## OPTIONS
     * <post_id>
     */
    public function get($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        \WP_CLI::log((string) get_post_meta($id, '_breakdance_data', true));
    }

    /**
     * Write _breakdance_data and regenerate the CSS cache.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --data=<file>
     * : Path to the _breakdance_data payload, or "-" for STDIN.
     * [--no-cache]
     * : Skip cache regeneration (not recommended).
     *
     * ## EXAMPLES
     *   cat data.json | wp agent bd set 123 --data=-
     */
    public function set($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $src  = $assoc['data'] ?? '';
        $data = $src === '-' ? file_get_contents('php://stdin') : @file_get_contents($src);
        if ($data === false) {
            Result::fail("Cannot read --data: {$src}");
        }
        update_post_meta($id, '_breakdance_data', wp_slash($data));

        $cache = isset($assoc['no-cache']) ? 'skipped' : $this->regenerate($id);
        Result::out(['id' => $id, 'bytes' => strlen($data), 'cache' => $cache]);
    }

    /**
     * Regenerate the Breakdance CSS cache for a post.
     *
     * ## OPTIONS
     * <post_id>
     */
    public function regen($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        Result::out(['id' => $id, 'cache' => $this->regenerate($id)]);
    }

    private function regenerate(int $id): string
    {
        foreach (
            [
                '\\Breakdance\\Render\\generateCacheForPost',
                '\\Breakdance\\Data\\generateCacheForPost',
                'generateCacheForPost',
            ] as $fn
        ) {
            if (function_exists($fn)) {
                $fn($id);
                return "ok:{$fn}";
            }
        }
        return 'no-regen-function-found';
    }
}
