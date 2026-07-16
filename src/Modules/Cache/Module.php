<?php

namespace AgentConnector\Modules\Cache;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'cache';
    }

    public function name(): string
    {
        return 'Cache (page/object purge)';
    }

    public function isAvailable(): bool
    {
        return !empty(\AgentConnector\Core\Cache::providers());
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent cache', Commands::class);
    }
}
