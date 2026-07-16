<?php

namespace AgentConnector\Modules\Content;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'content';
    }

    public function name(): string
    {
        return 'Content (posts, media, dates)';
    }

    public function isAvailable(): bool
    {
        return true; // core WordPress only
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent content', Commands::class);
    }
}
