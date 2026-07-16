<?php

namespace AgentConnector\Modules\Wpcodebox;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'snippet';
    }

    public function name(): string
    {
        return 'WPCodeBox snippets';
    }

    public function isAvailable(): bool
    {
        foreach ((array) get_option('active_plugins', []) as $p) {
            if (strpos($p, 'wpcodebox') !== false) {
                return true;
            }
        }
        return false;
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent snippet', Commands::class);
    }
}
