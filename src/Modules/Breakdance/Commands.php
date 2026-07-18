<?php

namespace AgentConnector\Modules\Breakdance;

use AgentConnector\Core\Result;

/**
 * Breakdance helpers.
 *
 * Gotchas this guards against:
 *  - After writing _breakdance_data you MUST regenerate the CSS cache, else the
 *    output renders unstyled. `set` and `patch` do it automatically.
 *  - The builder throws an IO-TS error if the tree lacks "_nextNodeId" or
 *    "status":"exported". `set` validates and refuses to write broken data
 *    (override with --force); `validate` checks an existing post.
 *  - json_decode(assoc) turns empty JSON objects into [] and re-encoding
 *    produces [] where the builder expects {}. `patch` therefore works on
 *    stdClass trees so empty objects survive the round trip.
 *  - Many elements render text from properties.content.content.* (double
 *    "content" nesting) — a value placed one level up renders as empty.
 */
class Commands
{
    /**
     * Container elements whose direct children must be a specific type.
     * The builder silently mis-renders violations, so validate flags them.
     */
    private const NESTING = [
        'EssentialElements\\Columns'           => ['EssentialElements\\Column'],
        'EssentialElements\\AdvancedAccordion' => ['EssentialElements\\AdvancedAccordionContent'],
    ];

    private const NS_PREFIX = 'EssentialElements\\';

    /**
     * List posts/templates that use Breakdance.
     *
     * ## OPTIONS
     * [--type=<post_type>]
     * : Filter by post type (e.g. page, breakdance_header, breakdance_template).
     * [--limit=<n>]
     * : Maximum rows (default 500).
     *
     * ## EXAMPLES
     *   wp agent bd list
     *   wp agent bd list --type=breakdance_template
     */
    public function list($args, $assoc)
    {
        global $wpdb;
        $limit = max(1, (int) ($assoc['limit'] ?? 500));
        $rows = $wpdb->get_results(
            "SELECT pm.post_id AS id, p.post_type, p.post_title, p.post_status
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_breakdance_data'
             ORDER BY p.post_type, pm.post_id
             LIMIT {$limit}",
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
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        \WP_CLI::log((string) get_post_meta($id, '_breakdance_data', true));
    }

    /**
     * Compact structural view of a post's Breakdance tree.
     *
     * Much lighter than `get`: per node it shows id, type (EssentialElements\
     * prefix stripped) and the first text value found in its content
     * properties. Also reports the sha256 hash of the stored data, usable as
     * --expect-hash on `set`/`patch` for optimistic locking.
     *
     * ## OPTIONS
     * <post_id>
     *
     * ## EXAMPLES
     *   wp agent bd outline 123
     */
    public function outline($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $raw = (string) get_post_meta($id, '_breakdance_data', true);
        if ($raw === '') {
            Result::fail("No _breakdance_data on post {$id}");
        }
        $tree = $this->decodeTree($raw);
        if ($tree === null) {
            Result::fail("Post {$id}: _breakdance_data is not a valid Breakdance payload (run `validate` for details)");
        }

        $count = 0;
        $items = [];
        foreach ((array) ($tree['root']['children'] ?? []) as $child) {
            if (is_array($child)) {
                $items[] = $this->outlineNode($child, $count);
            }
        }
        Result::out([
            'id'          => $id,
            'hash'        => hash('sha256', $raw),
            'status'      => $tree['status'] ?? null,
            '_nextNodeId' => $tree['_nextNodeId'] ?? null,
            'nodes'       => $count,
            'tree'        => $items,
        ]);
    }

    /**
     * Validate a post's _breakdance_data.
     *
     * Checks JSON shape, builder-required fields (root, _nextNodeId,
     * status:exported), duplicate/missing node ids, _nextNodeId sequencing,
     * and known nesting rules (e.g. Columns may only contain Column).
     * Non-EssentialElements namespaces are reported as warnings, since
     * third-party elements are legitimate.
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
        Result::out(['id' => $id, 'has_data' => true, 'hash' => hash('sha256', $raw)] + $check);
    }

    /**
     * Write _breakdance_data and regenerate the CSS cache.
     *
     * Refuses to overwrite a post that already has Breakdance data unless
     * --replace is passed or --expect-hash matches the stored data (get the
     * hash from `outline` or `validate`). A mismatching --expect-hash means
     * someone edited the post since you read it.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --data=<file>
     * : Path to the _breakdance_data payload, or "-" for STDIN.
     * [--replace]
     * : Allow overwriting existing Breakdance data without a hash check.
     * [--expect-hash=<sha256>]
     * : Only write if the stored data still has this sha256 hash.
     * [--force]
     * : Write even if validation fails.
     * [--no-cache]
     * : Skip cache regeneration (not recommended).
     *
     * ## EXAMPLES
     *   cat data.json | wp agent bd set 123 --data=-
     *   cat data.json | wp agent bd set 123 --data=- --expect-hash=abc123...
     */
    public function set($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $data = $this->readPayload($assoc);

        $current = (string) get_post_meta($id, '_breakdance_data', true);
        $this->guardOverwrite($id, $current, $assoc, true);

        $check = $this->validateData($data);
        if (!$check['valid'] && !isset($assoc['force'])) {
            Result::fail('Breakdance data invalid: ' . implode('; ', $check['issues']) . '. Nothing written. Use --force to override.');
        }

        update_post_meta($id, '_breakdance_data', wp_slash($data));

        $cache = isset($assoc['no-cache']) ? 'skipped' : $this->regenerate($id);
        $purge = \AgentConnector\Core\Cache::purgePost($id)['purged'];
        Result::out(['id' => $id, 'bytes' => strlen($data), 'hash' => hash('sha256', $data),
            'valid' => $check['valid'], 'issues' => $check['issues'],
            'warnings' => $check['warnings'] ?? [], 'cache' => $cache, 'purged' => $purge]);
    }

    /**
     * Surgically edit one node of the tree (deep merge) without rewriting the
     * whole page.
     *
     * The patch is a JSON object merged into the node: objects merge
     * recursively, arrays and scalars replace, null deletes a key. To change
     * a heading text, for example:
     *
     *   {"data":{"properties":{"content":{"content":{"text":"New title"}}}}}
     *
     * Note the double "content" nesting — many elements render from
     * properties.content.content.*; a value placed one level up renders as
     * empty. Find node ids with `outline`. Empty JSON objects in the stored
     * tree are preserved. The result is re-validated before writing and the
     * CSS cache is regenerated.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * <node_id>
     * : The id of the node inside the tree (see `outline`).
     * --data=<file>
     * : Path to the JSON patch object, or "-" for STDIN.
     * [--expect-hash=<sha256>]
     * : Only write if the stored data still has this sha256 hash.
     * [--force]
     * : Write even if post-merge validation fails.
     * [--no-cache]
     * : Skip cache regeneration (not recommended).
     *
     * ## EXAMPLES
     *   echo '{"data":{"properties":{"content":{"content":{"text":"Hi"}}}}}' | wp agent bd patch 123 7 --data=-
     */
    public function patch($args, $assoc)
    {
        $id     = (int) ($args[0] ?? 0);
        $nodeId = (int) ($args[1] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $raw = (string) get_post_meta($id, '_breakdance_data', true);
        if ($raw === '') {
            Result::fail("No _breakdance_data on post {$id}");
        }
        $this->guardOverwrite($id, $raw, $assoc, false);

        $patch = json_decode($this->readPayload($assoc));
        if (!is_object($patch)) {
            Result::fail('Patch must be a JSON object');
        }

        // Object-mode decode so empty {} survive re-encoding (assoc mode would
        // turn them into [] and break the builder's schema validation).
        $outer = json_decode($raw);
        $tree  = is_object($outer) && isset($outer->tree_json_string) ? json_decode($outer->tree_json_string) : null;
        if (!is_object($tree) || !isset($tree->root)) {
            Result::fail("Post {$id}: _breakdance_data is not a valid Breakdance payload (run `validate` for details)");
        }
        $node = $this->findNode($tree->root, $nodeId);
        if ($node === null) {
            Result::fail("Node not found in tree: {$nodeId} (use `outline` to list node ids)");
        }
        $this->mergeInto($node, $patch);

        $outer->tree_json_string = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newRaw = json_encode($outer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $check = $this->validateData($newRaw);
        if (!$check['valid'] && !isset($assoc['force'])) {
            Result::fail('Patched tree invalid: ' . implode('; ', $check['issues']) . '. Nothing written. Use --force to override.');
        }

        update_post_meta($id, '_breakdance_data', wp_slash($newRaw));

        $cache = isset($assoc['no-cache']) ? 'skipped' : $this->regenerate($id);
        $purge = \AgentConnector\Core\Cache::purgePost($id)['purged'];
        Result::out(['id' => $id, 'node' => $nodeId, 'hash' => hash('sha256', $newRaw),
            'valid' => $check['valid'], 'issues' => $check['issues'],
            'warnings' => $check['warnings'] ?? [], 'cache' => $cache, 'purged' => $purge,
            'node_data' => $node]);
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

    private function readPayload(array $assoc): string
    {
        $src  = $assoc['data'] ?? '';
        $data = $src === '-' ? file_get_contents('php://stdin') : @file_get_contents($src);
        if ($data === false) {
            Result::fail("Cannot read --data: {$src}");
        }
        return $data;
    }

    /**
     * Overwrite protection shared by set/patch. When --expect-hash is given it
     * must match the stored data; otherwise set additionally requires
     * --replace when data already exists.
     */
    private function guardOverwrite(int $id, string $current, array $assoc, bool $requireReplace): void
    {
        $hash = hash('sha256', $current);
        if (isset($assoc['expect-hash'])) {
            if (!hash_equals($hash, strtolower((string) $assoc['expect-hash']))) {
                Result::fail("Hash mismatch on post {$id}: stored data has hash {$hash}. Someone edited it since you read it — re-read and retry.");
            }
            return;
        }
        if ($requireReplace && $current !== '' && !isset($assoc['replace'])) {
            Result::fail("Post {$id} already has Breakdance data (hash {$hash}). Pass --replace to overwrite, or --expect-hash={$hash} to assert nothing changed since you read it.");
        }
    }

    /**
     * Parse _breakdance_data ({"tree_json_string": "<escaped JSON>"}) into the
     * inner tree as an assoc array, or null when the shape is wrong.
     */
    private function decodeTree(string $raw): ?array
    {
        $outer = json_decode($raw, true);
        if (!is_array($outer) || !isset($outer['tree_json_string'])) {
            return null;
        }
        $tree = json_decode($outer['tree_json_string'], true);
        return is_array($tree) ? $tree : null;
    }

    /**
     * Check the builder-required fields plus tree structure. The inner tree
     * must carry root, _nextNodeId and status:exported (else the builder
     * throws an IO-TS error, though the front-end still renders). Structural
     * problems (duplicate ids, stale _nextNodeId, nesting violations) are
     * issues; unknown element namespaces are warnings.
     */
    private function validateData(string $raw): array
    {
        $outer = json_decode($raw, true);
        if (!is_array($outer)) {
            return ['valid' => false, 'issues' => ['not valid JSON'], 'warnings' => []];
        }
        if (!isset($outer['tree_json_string'])) {
            return ['valid' => false, 'issues' => ['missing tree_json_string'], 'warnings' => []];
        }
        $tree = json_decode($outer['tree_json_string'], true);
        if (!is_array($tree)) {
            return ['valid' => false, 'issues' => ['tree_json_string is not valid JSON'], 'warnings' => []];
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

        $state = ['ids' => [], 'maxId' => 0, 'nodes' => 0, 'issues' => [], 'namespaces' => []];
        if (isset($tree['root']) && is_array($tree['root'])) {
            foreach ((array) ($tree['root']['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $this->walkValidate($child, null, $state);
                }
            }
        }
        $issues = array_merge($issues, $state['issues']);

        if (isset($tree['_nextNodeId']) && is_int($tree['_nextNodeId']) && $state['maxId'] > 0
            && $tree['_nextNodeId'] <= $state['maxId']) {
            $issues[] = "_nextNodeId ({$tree['_nextNodeId']}) must be greater than the highest node id ({$state['maxId']}) or the builder will mint duplicate ids";
        }

        $warnings = [];
        foreach (array_keys($state['namespaces']) as $type) {
            $warnings[] = "non-EssentialElements element type '{$type}' (fine if a third-party element pack provides it)";
        }

        return ['valid' => empty($issues), 'issues' => $issues, 'warnings' => $warnings, 'nodes' => $state['nodes']];
    }

    private function walkValidate(array $node, ?string $parentType, array &$state): void
    {
        $state['nodes']++;

        $id = $node['id'] ?? null;
        if (!is_int($id)) {
            $state['issues'][] = 'node with missing or non-integer id (' . json_encode($id) . ')';
            $id = null;
        } else {
            if (isset($state['ids'][$id])) {
                $state['issues'][] = "duplicate node id {$id}";
            }
            $state['ids'][$id] = true;
            $state['maxId']    = max($state['maxId'], $id);
        }

        $type = $node['data']['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $state['issues'][] = 'node ' . json_encode($id) . ' missing data.type';
            $type = null;
        } elseif (strpos($type, self::NS_PREFIX) !== 0) {
            $state['namespaces'][$type] = true;
        }

        if ($type !== null && $parentType !== null && isset(self::NESTING[$parentType])
            && !in_array($type, self::NESTING[$parentType], true)) {
            $expected = implode('|', array_map([$this, 'shortType'], self::NESTING[$parentType]));
            $state['issues'][] = $this->shortType($parentType) . " may only contain {$expected}, found "
                . $this->shortType($type) . ' (node ' . json_encode($id) . ')';
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->walkValidate($child, $type, $state);
            }
        }
    }

    private function outlineNode(array $node, int &$count): array
    {
        $count++;
        $type = $node['data']['type'] ?? null;
        $item = [
            'id'   => $node['id'] ?? null,
            'type' => is_string($type) ? $this->shortType($type) : null,
        ];
        $text = $this->primaryText($node['data']['properties']['content'] ?? null);
        if ($text !== null) {
            $item['text'] = $text;
        }
        $children = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $children[] = $this->outlineNode($child, $count);
            }
        }
        if ($children !== []) {
            $item['children'] = $children;
        }
        return $item;
    }

    /** Depth-first first non-empty string in a node's content properties. */
    private function primaryText($content): ?string
    {
        if (is_string($content)) {
            $flat = trim(strip_tags($content));
            if ($flat !== '') {
                return function_exists('mb_substr') && function_exists('mb_strlen')
                    ? (mb_strlen($flat) > 80 ? mb_substr($flat, 0, 79) . '…' : $flat)
                    : (strlen($flat) > 80 ? substr($flat, 0, 79) . '…' : $flat);
            }
            return null;
        }
        if (is_array($content)) {
            foreach ($content as $value) {
                $found = $this->primaryText($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function shortType(string $type): string
    {
        return strpos($type, self::NS_PREFIX) === 0 ? substr($type, strlen(self::NS_PREFIX)) : $type;
    }

    private function findNode(object $node, int $nodeId): ?object
    {
        if (isset($node->id) && (int) $node->id === $nodeId) {
            return $node;
        }
        foreach ((array) ($node->children ?? []) as $child) {
            if (is_object($child)) {
                $found = $this->findNode($child, $nodeId);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** Objects merge recursively, arrays/scalars replace, null deletes. */
    private function mergeInto(object $target, object $patch): void
    {
        foreach (get_object_vars($patch) as $key => $value) {
            if ($value === null) {
                unset($target->$key);
            } elseif (is_object($value) && isset($target->$key) && is_object($target->$key)) {
                $this->mergeInto($target->$key, $value);
            } else {
                $target->$key = $value;
            }
        }
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
