<?php

namespace AgentConnector\Core;

/**
 * Core: discovers modules under src/Modules/*, keeps the available ones,
 * and registers their WP-CLI commands.
 */
final class Plugin
{
    /** @var Plugin|null */
    private static $instance;

    /** @var Module[] keyed by slug */
    private $modules = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        $this->discover();
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_hook('before_wp_load', function () {
                // no-op; kept for future eager needs
            });
            add_action('cli_init', [$this, 'registerCli']);
        }
    }

    private function discover(): void
    {
        foreach (glob(AGENT_CONNECTOR_DIR . '/src/Modules/*/Module.php') as $file) {
            $dir = basename(dirname($file));
            $class = "AgentConnector\\Modules\\{$dir}\\Module";
            if (!class_exists($class)) {
                continue;
            }
            $module = new $class();
            if ($module instanceof Module && $module->isAvailable()) {
                $this->modules[$module->slug()] = $module;
            }
        }
    }

    public function registerCli(): void
    {
        foreach ($this->modules as $module) {
            $module->registerCli();
        }
        // `wp agent modules` : list active modules on this site.
        \WP_CLI::add_command('agent modules', function () {
            $rows = [];
            foreach ($this->modules as $slug => $module) {
                $rows[] = ['slug' => $slug, 'name' => $module->name()];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['slug', 'name']);
        });
    }

    /** @return Module[] */
    public function modules(): array
    {
        return $this->modules;
    }
}
