<?php

namespace AgentConnector\Modules\Self;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'self';
    }

    public function name(): string
    {
        return 'Self-update';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent self', Commands::class);
    }
}
