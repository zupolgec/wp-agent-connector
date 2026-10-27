<?php

namespace AgentConnector\Modules\Abilities;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'ability';
    }

    public function name(): string
    {
        return 'Abilities API (run plugin abilities from WP-CLI)';
    }

    public function isAvailable(): bool
    {
        return function_exists('wp_get_abilities');
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent ability', Commands::class);
    }
}
