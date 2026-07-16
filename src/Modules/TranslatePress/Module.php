<?php

namespace AgentConnector\Modules\TranslatePress;

class Module extends \AgentConnector\Core\Module
{
    public function slug(): string
    {
        return 'tp';
    }

    public function name(): string
    {
        return 'TranslatePress';
    }

    public function isAvailable(): bool
    {
        return class_exists('TRP_Translate_Press') || get_option('trp_settings') !== false;
    }

    public function registerCli(): void
    {
        \WP_CLI::add_command('agent tp', Commands::class);
    }
}
