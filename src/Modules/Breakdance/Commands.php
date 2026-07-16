<?php

namespace AgentConnector\Modules\Breakdance;

use AgentConnector\Core\Result;

/**
 * Breakdance helpers.
 *
 * Two gotchas this guards against:
 *  - After writing _breakdance_data you MUST regenerate the CSS cache, else the
 *    output renders unstyled. `set` does it automatically.
 *  - The builder throws an IO-TS error if the tree lacks "_nextNodeId" or
 *    "status":"exported". `set` validates and refuses to write broken data
 *    (override with --force); `validate` checks an existing post.
 */
class Commands
{
    /**
     * List posts/templates that use Breakdance.
     *
     * ## OPTIONS
     * [--type=<post_type>]
     * : Filter by post type (e.g. page, breakdance_header, breakdance_template).
     *
     * ## EXAMPLES
     *   wp agent bd list
     *   wp agent bd list --type=breakdance_template
     */
    public function list($args, $assoc)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT pm.post_id AS id, p.post_type, p.post_title, p.post_status
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_breakdance_data'
             ORDER BY p.post_type, pm.post_id",
            ARRAY_A
        );
        if (!empty($assoc['type'])) {
            $rows = array_values(array_filter($rows, static function ($r) use ($assoc) {
                return $r['post_type'] === $assoc['type'];
            }));
        }
        Result::out(['count' => count($rows), 'items' => $rows]);
    }

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
     * Validate a post's _breakdance_data (JSON + required builder fields).
     *
     * ## OPTIONS
     * <post_id>
     *
     * ## EXAMPLES
     *   wp agent bd validate 123
     */
    public function validate($args, $assoc)
    {
        $id  = (int) ($args[0] ?? 0);
        $raw = (string) get_post_meta($id, '_breakdance_data', true);
        if ($raw === '') {
            Result::out(['id' => $id, 'has_data' => false, 'valid' => false, 'issues' => ['no _breakdance_data']]);
            return;
        }
        $check = $this->validateData($raw);
        Result::out(['id' => $id, 'has_data' => true] + $check);
    }

    /**
     * Write _breakdance_data and regenerate the CSS cache.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --data=<file>
     * : Path to the _breakdance_data payload, or "-" for STDIN.
     * [--force]
     * : Write even if validation fails.
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

        $check = $this->validateData($data);
        if (!$check['valid'] && !isset($assoc['force'])) {
            Result::fail('Breakdance data invalid: ' . implode('; ', $check['issues']) . '. Nothing written. Use --force to override.');
        }

        update_post_meta($id, '_breakdance_data', wp_slash($data));

        $cache = isset($assoc['no-cache']) ? 'skipped' : $this->regenerate($id);
        $purge = \AgentConnector\Core\Cache::purgePost($id)['purged'];
        Result::out(['id' => $id, 'bytes' => strlen($data), 'valid' => $check['valid'],
            'issues' => $check['issues'], 'cache' => $cache, 'purged' => $purge]);
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

    // ---- helpers ----------------------------------------------------------

    /**
     * Check the builder-required fields. _breakdance_data is
     * {"tree_json_string": "<escaped JSON>"} and the inner tree must carry
     * root, _nextNodeId and status:exported (else the builder throws an IO-TS
     * error, though the front-end still renders).
     */
    private function validateData(string $raw): array
    {
        $outer = json_decode($raw, true);
        if (!is_array($outer)) {
            return ['valid' => false, 'issues' => ['not valid JSON']];
        }
        if (!isset($outer['tree_json_string'])) {
            return ['valid' => false, 'issues' => ['missing tree_json_string']];
        }
        $tree = json_decode($outer['tree_json_string'], true);
        if (!is_array($tree)) {
            return ['valid' => false, 'issues' => ['tree_json_string is not valid JSON']];
        }

        $issues = [];
        if (!isset($tree['root'])) {
            $issues[] = 'tree missing root';
        }
        if (!isset($tree['_nextNodeId'])) {
            $issues[] = 'tree missing _nextNodeId';
        }
        $status = $tree['status'] ?? null;
        if ($status !== 'exported') {
            $issues[] = "tree status is not 'exported' (got " . json_encode($status) . ')';
        }
        return ['valid' => empty($issues), 'issues' => $issues];
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
