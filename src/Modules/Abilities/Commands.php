<?php

namespace AgentConnector\Modules\Abilities;

use AgentConnector\Core\Cache;
use AgentConnector\Core\Result;

/**
 * Run WordPress abilities (Abilities API, WP 6.9+) over WP-CLI: the same tools
 * plugins expose to MCP agents (Breakdance, SCF, TranslatePress, ...), without
 * an HTTP endpoint or application passwords.
 *
 * Abilities check the current user's capabilities, so pass the global --user.
 * An ability annotated readonly runs as is; anything else writes, and needs
 * --yes to run or --dry-run to only validate input and permissions.
 *
 *   wp agent ability list [--namespace=breakdance]
 *   wp agent ability schema breakdance/edit-post
 *   wp agent ability run breakdance/get-post-tree --input=- --user=1 <<< '{"post_id":2}'
 *   cat ops.json | wp agent ability run breakdance/edit-post --input=- --user=1 --yes
 */
class Commands
{
    /**
     * List registered abilities.
     *
     * ## OPTIONS
     * [--namespace=<ns>]
     * : Only abilities whose name starts with "<ns>/".
     *
     * @subcommand list
     */
    public function list($args, $assoc)
    {
        $ns   = isset($assoc['namespace']) ? rtrim((string) $assoc['namespace'], '/') . '/' : '';
        $rows = [];
        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            if ($ns !== '' && strpos($name, $ns) !== 0) {
                continue;
            }
            $rows[] = [
                'name'  => $name,
                'label' => $ability->get_label(),
                'mode'  => $this->mode($ability),
            ];
        }
        Result::out($rows);
    }

    /**
     * Show an ability's description, input/output schema and annotations.
     *
     * ## OPTIONS
     * <name>
     * : Ability name, e.g. breakdance/edit-post.
     */
    public function schema($args, $assoc)
    {
        $ability = $this->ability($args[0]);
        Result::out([
            'name'          => $ability->get_name(),
            'label'         => $ability->get_label(),
            'category'      => $ability->get_category(),
            'mode'          => $this->mode($ability),
            'description'   => $ability->get_description(),
            'input_schema'  => $ability->get_input_schema(),
            'output_schema' => $ability->get_output_schema(),
            'annotations'   => $ability->get_meta()['annotations'] ?? null,
        ]);
    }

    /**
     * Execute an ability.
     *
     * ## OPTIONS
     * <name>
     * : Ability name.
     * [--input=<file>]
     * : JSON input: a file path, or "-" for STDIN. Omit for abilities without input.
     * [--yes]
     * : Required to run an ability that is not readonly.
     * [--dry-run]
     * : Validate input and permissions without executing.
     */
    public function run($args, $assoc)
    {
        $ability = $this->ability($args[0]);
        $input   = $this->readInput($assoc);
        // An object schema rejects null: no --input then means "{}".
        if ($input === null && (($ability->get_input_schema()['type'] ?? null) === 'object')) {
            $input = [];
        }

        if (get_current_user_id() < 1) {
            Result::fail('Abilities check user capabilities: pass the global --user=<id|login>.');
        }

        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) {
            Result::fail('Invalid input: ' . $valid->get_error_message());
        }
        $allowed = $ability->check_permissions($input);
        if (is_wp_error($allowed) || $allowed !== true) {
            Result::fail('Permission denied for ' . $ability->get_name()
                . (is_wp_error($allowed) ? ': ' . $allowed->get_error_message() : '.'));
        }

        $write = $this->mode($ability) === 'write';

        if (!empty($assoc['dry-run'])) {
            Result::out(['name' => $ability->get_name(), 'mode' => $this->mode($ability), 'dry_run' => true, 'valid' => true]);
            return;
        }
        if ($write && empty($assoc['yes'])) {
            Result::fail($ability->get_name() . ' writes to the site: pass --yes to run it, or --dry-run to only validate.');
        }

        $result = $ability->execute($input);
        if (is_wp_error($result)) {
            Result::fail($ability->get_name() . ': ' . $result->get_error_message());
        }

        $out = ['name' => $ability->get_name(), 'result' => $result];
        // Abilities write straight to post meta/options, which page caches don't see.
        if ($write && is_array($input) && isset($input['post_id']) && is_int($input['post_id'])) {
            $out['purged'] = Cache::purgePost($input['post_id'])['purged'];
        }
        Result::out($out);
    }

    private function ability(string $name)
    {
        $ability = wp_get_ability($name);
        if (!$ability) {
            Result::fail("Unknown ability: {$name}. See `wp agent ability list`.");
        }
        return $ability;
    }

    /** "read" only when the ability declares itself readonly; anything else may write. */
    private function mode($ability): string
    {
        $annotations = $ability->get_meta()['annotations'] ?? [];
        return ($annotations['readonly'] ?? null) === true ? 'read' : 'write';
    }

    private function readInput(array $assoc)
    {
        if (!isset($assoc['input'])) {
            return null;
        }
        $src  = (string) $assoc['input'];
        $json = $src === '-' ? file_get_contents('php://stdin') : @file_get_contents($src);
        if ($json === false) {
            Result::fail("Cannot read input from {$src}.");
        }
        $input = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Result::fail('Invalid JSON input: ' . json_last_error_msg());
        }
        return $input;
    }
}
