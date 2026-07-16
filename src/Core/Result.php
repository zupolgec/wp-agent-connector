<?php

namespace AgentConnector\Core;

/**
 * Consistent JSON output for commands, so an agent can parse results reliably.
 */
class Result
{
    public static function out($data): void
    {
        \WP_CLI::log(wp_json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public static function ok(array $extra = []): void
    {
        self::out(array_merge(['ok' => true], $extra));
    }

    public static function fail(string $message): void
    {
        \WP_CLI::error($message);
    }
}
