<?php

namespace AgentConnector\Modules\Database;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'db';
    }

    public function name(): string
    {
        return 'Database (safe SQL query/exec)';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent db', Commands::class);
    }
}
