<?php

namespace AgentConnector\Modules\Wpcodebox;

use AgentConnector\Core\Result;

/**
 * WPCodeBox snippet access.
 *
 * NOTE: v0 ships only schema discovery. WPCodeBox stores snippets in its own
 * tables (name/version dependent), so `tables` is used once on the target site
 * to confirm the schema before `list`/`get`/`set` are wired up.
 */
class Commands
{
    /**
     * List database tables that look like they belong to WPCodeBox.
     *
     * ## EXAMPLES
     *   wp agent snippet tables
     */
    public function tables($args, $assoc)
    {
        global $wpdb;
        $like = '%wpcodebox%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $alt    = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', '%wpcb%'));
        Result::out(['tables' => array_values(array_unique(array_merge($tables, $alt)))]);
    }
}
