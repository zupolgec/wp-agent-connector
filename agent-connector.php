<?php
/**
 * Plugin Name: Agent Connector
 * Description: Modular WP-CLI toolkit for agent-driven site operations (content, SQL, TranslatePress, Breakdance, WPCodeBox). Each module self-activates only when its dependency is present, so the plugin is site-agnostic.
 * Version: 0.8.0
 * Author: internal
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGENT_CONNECTOR_FILE', __FILE__);
define('AGENT_CONNECTOR_DIR', __DIR__);
define('AGENT_CONNECTOR_VERSION', '0.8.0');

// Minimal PSR-4 autoloader: AgentConnector\Foo\Bar => src/Foo/Bar.php
spl_autoload_register(function ($class) {
    $prefix = 'AgentConnector\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = AGENT_CONNECTOR_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

\AgentConnector\Core\Plugin::instance()->boot();
