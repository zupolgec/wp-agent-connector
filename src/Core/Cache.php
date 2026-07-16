<?php

namespace AgentConnector\Core;

/**
 * Cache purging. Direct DB writes (meta, TranslatePress dictionary) bypass the
 * save_post hooks that page-cache plugins listen to, so after such writes the
 * tool must purge explicitly or the front-end keeps serving stale HTML.
 *
 * Currently wired for SpinupWP (page + object cache). Extend `providers()` for
 * WP Rocket / W3TC / Cloudflare as needed — callers stay unchanged.
 */
class Cache
{
    public static function purgePost(int $id): array
    {
        $done = [];
        if (function_exists('spinupwp_purge_post')) {
            spinupwp_purge_post($id);
            $done[] = 'spinupwp:post';
        }
        return ['post' => $id, 'purged' => $done];
    }

    public static function purgeUrl(string $url): array
    {
        $done = [];
        if (function_exists('spinupwp_purge_url')) {
            spinupwp_purge_url($url);
            $done[] = 'spinupwp:url';
        }
        return ['url' => $url, 'purged' => $done];
    }

    public static function purgeSite(): array
    {
        $done = [];
        if (function_exists('spinupwp_purge_site')) {
            spinupwp_purge_site();
            $done[] = 'spinupwp:site';
        }
        return ['purged' => $done];
    }

    /** Names of the purge providers detected on this site. */
    public static function providers(): array
    {
        $providers = [];
        if (function_exists('spinupwp_purge_post')) {
            $providers[] = 'spinupwp';
        }
        return $providers;
    }
}
