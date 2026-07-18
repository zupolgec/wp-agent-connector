<?php

namespace AgentConnector\Modules\Plugins;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'plugin';
    }

    public function name(): string
    {
        return 'Plugin files (deploy)';
    }

    public function isAvailable(): bool
    {
        return defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR);
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent plugin', Commands::class);
    }
}
