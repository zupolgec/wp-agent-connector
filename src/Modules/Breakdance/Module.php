<?php

namespace AgentConnector\Modules\Breakdance;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'bd';
    }

    public function name(): string
    {
        return 'Breakdance';
    }

    public function isAvailable(): bool
    {
        return defined('__BREAKDANCE_VERSION') || defined('BREAKDANCE_VERSION') || is_plugin_active_check('breakdance');
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent bd', Commands::class);
    }
}

if (!function_exists('AgentConnector\\Modules\\Breakdance\\is_plugin_active_check')) {
    function is_plugin_active_check(string $slug): bool
    {
        foreach ((array) get_option('active_plugins', []) as $p) {
            if (strpos($p, $slug . '/') === 0 || strpos($p, $slug . '.php') !== false) {
                return true;
            }
        }
        return false;
    }
}
