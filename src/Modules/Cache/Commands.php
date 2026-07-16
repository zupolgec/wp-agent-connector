<?php

namespace AgentConnector\Modules\Cache;

use AgentConnector\Core\Cache;
use AgentConnector\Core\Result;

/**
 * Explicit cache purging. The other modules purge automatically after direct
 * writes; this exposes it on its own.
 *
 *   wp agent cache status
 *   wp agent cache purge --post=<id>
 *   wp agent cache purge --url=<url>
 *   wp agent cache purge --site
 */
class Commands
{
    /**
     * Show detected purge providers.
     */
    public function status($args, $assoc)
    {
        Result::out(['providers' => Cache::providers()]);
    }

    /**
     * Purge cache for a post, a URL, or the whole site.
     *
     * ## OPTIONS
     * [--post=<id>]
     * : Purge the cache for this post's URL(s).
     * [--url=<url>]
     * : Purge a specific URL.
     * [--site]
     * : Purge the entire site cache.
     */
    public function purge($args, $assoc)
    {
        if (isset($assoc['post'])) {
            Result::out(Cache::purgePost((int) $assoc['post']));
            return;
        }
        if (isset($assoc['url'])) {
            Result::out(Cache::purgeUrl((string) $assoc['url']));
            return;
        }
        if (isset($assoc['site'])) {
            Result::out(Cache::purgeSite());
            return;
        }
        Result::fail('Pass --post=<id>, --url=<url> or --site.');
    }
}
