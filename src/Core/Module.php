<?php

namespace AgentConnector\Core;

/**
 * Base class for a connector module.
 *
 * A module bundles a set of WP-CLI commands around one capability
 * (TranslatePress, Breakdance, ...). The core only boots modules whose
 * dependency is present on the current site, so the plugin stays generic.
 */
abstract class Module
{
    /** Short CLI slug used as `wp agent <slug> ...` (e.g. "tp"). */
    abstract public function slug(): string;

    /** Human-readable name. */
    abstract public function name(): string;

    /** Whether this module's dependency is available on this site. */
    abstract public function isAvailable(): bool;

    /** Register WP-CLI commands. Called on cli_init, only if isAvailable(). */
    abstract public function registerCli(): void;
}
