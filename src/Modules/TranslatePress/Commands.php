<?php

namespace AgentConnector\Modules\TranslatePress;

use AgentConnector\Core\Result;

/**
 * Automates the TranslatePress post-translation workflow that would otherwise
 * be done by hand against the wzi_trp_* tables.
 *
 * Runs server-side, so it can render the post to scope strings precisely and
 * trigger TP's own string registration by fetching the translated page.
 *
 * Typical flow (per language):
 *   wp agent tp strings <post_id> --lang=en_US        # dump untranslated strings
 *   # ...produce a JSON map {id: "translation", ...}...
 *   wp agent tp apply <post_id> --lang=en_US --map=en.json --slug=<slug>
 *   wp agent tp verify <post_id>
 */
class Commands
{
    /** @var array */
    private $settings;
    /** @var string e.g. it_IT */
    private $default;
    /** @var string[] */
    private $langs;
    /** @var array<string,string> code => url slug */
    private $urlSlugs;

    public function __construct()
    {
        $this->settings = get_option('trp_settings') ?: [];
        $this->default  = $this->settings['default-language'] ?? 'it_IT';
        $this->langs    = $this->settings['translation-languages'] ?? [];
        $this->urlSlugs = $this->settings['url-slugs'] ?? [];
    }

    /**
     * List configured languages.
     *
     * ## EXAMPLES
     *   wp agent tp languages
     */
    public function languages($args, $assoc)
    {
        Result::out([
            'default'   => $this->default,
            'targets'   => array_values(array_diff($this->langs, [$this->default])),
            'url_slugs' => $this->urlSlugs,
        ]);
    }

    /**
     * Register + list the untranslated strings that belong to a post, for a language.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --lang=<code>
     * : Target language code, e.g. en_US or fr_FR.
     *
     * ## EXAMPLES
     *   wp agent tp strings 3697 --lang=en_US
     */
    public function strings($args, $assoc)
    {
        global $wpdb;
        $id   = (int) ($args[0] ?? 0);
        $code = $assoc['lang'] ?? '';
        $this->assertPost($id);
        $this->assertLang($code);

        $table  = $this->table($code);
        $before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$table}");

        $this->register($id, $code);

        $haystack = $this->haystack($id);
        $rows = $wpdb->get_results(
            "SELECT id, original FROM {$table} WHERE (translated IS NULL OR translated = '')",
            ARRAY_A
        );

        $out = [];
        foreach ($rows as $r) {
            $original = $r['original'];
            if ($original === '' || $this->isUrl($original)) {
                continue;
            }
            $isNew  = ((int) $r['id']) > $before;
            $inPost = $this->contains($haystack, $original);
            if ($isNew || $inPost) {
                $out[] = ['id' => (int) $r['id'], 'original' => $original];
            }
        }

        Result::out([
            'post'    => $id,
            'lang'    => $code,
            'count'   => count($out),
            'strings' => $out,
        ]);
    }

    /**
     * Apply translations (and optionally the translated slug) for a post.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --lang=<code>
     * : Target language code.
     * --map=<file>
     * : Path to a JSON map, or "-" for STDIN. Keys are dictionary row ids
     *   (from `strings`) or exact original strings; values are translations.
     * [--slug=<slug>]
     * : Translated URL slug for this language.
     * [--dry-run]
     * : Report what would change without writing.
     *
     * ## EXAMPLES
     *   cat en.json | wp agent tp apply 3697 --lang=en_US --map=- --slug=david-vr-florence
     */
    public function apply($args, $assoc)
    {
        global $wpdb;
        $id   = (int) ($args[0] ?? 0);
        $code = $assoc['lang'] ?? '';
        $this->assertPost($id);
        $this->assertLang($code);

        $mapArg = $assoc['map'] ?? '';
        $json   = $mapArg === '-' ? file_get_contents('php://stdin') : @file_get_contents($mapArg);
        if ($json === false) {
            Result::fail("Cannot read --map: {$mapArg}");
        }
        $map = json_decode($json, true);
        if (!is_array($map)) {
            Result::fail('Invalid --map JSON');
        }

        $dry     = isset($assoc['dry-run']);
        $table   = $this->table($code);
        $applied = 0;
        $missed  = [];

        foreach ($map as $key => $translation) {
            $where = ctype_digit((string) $key) ? ['id' => (int) $key] : ['original' => (string) $key];
            if ($dry) {
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE " . (isset($where['id']) ? 'id=%d' : 'original=%s'),
                        array_values($where)[0]
                    )
                );
                $exists ? $applied++ : ($missed[] = $key);
                continue;
            }
            $n = $wpdb->update($table, ['translated' => $translation, 'status' => 2], $where);
            if ($n) {
                $applied += $n;
            } else {
                $missed[] = $key;
            }
        }

        $slug = null;
        if (!empty($assoc['slug'])) {
            $slug = $dry ? '(dry-run)' : $this->setSlug($id, $code, $assoc['slug']);
        }

        Result::out([
            'post'    => $id,
            'lang'    => $code,
            'applied' => $applied,
            'missed'  => $missed,
            'slug'    => $slug,
            'dry_run' => $dry,
        ]);
    }

    /**
     * Fetch each translated page and report HTTP status (smoke test).
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     *
     * ## EXAMPLES
     *   wp agent tp verify 3697
     */
    public function verify($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        $this->assertPost($id);
        $report = [];
        foreach (array_diff($this->langs, [$this->default]) as $code) {
            $url = $this->translatedUrl($id, $code);
            $res = wp_remote_get($url, $this->requestArgs());
            $report[$code] = [
                'url'  => $url,
                'code' => is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res),
            ];
        }
        Result::out(['post' => $id, 'languages' => $report]);
    }

    /**
     * Translation coverage for a post, per language.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     *
     * ## EXAMPLES
     *   wp agent tp status 3697
     */
    public function status($args, $assoc)
    {
        global $wpdb;
        $id = (int) ($args[0] ?? 0);
        $this->assertPost($id);
        $haystack = $this->haystack($id);

        $languages = [];
        foreach (array_diff($this->langs, [$this->default]) as $code) {
            $table  = $this->table($code);
            $before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$table}");
            $this->register($id, $code);

            $rows = $wpdb->get_results("SELECT id, original, translated FROM {$table}", ARRAY_A);
            $translated = 0;
            $untranslated = 0;
            foreach ($rows as $r) {
                if ($r['original'] === '' || $this->isUrl($r['original'])) {
                    continue;
                }
                $scoped = ((int) $r['id']) > $before || $this->contains($haystack, $r['original']);
                if (!$scoped) {
                    continue;
                }
                if ($r['translated'] !== null && $r['translated'] !== '') {
                    $translated++;
                } else {
                    $untranslated++;
                }
            }
            $total = $translated + $untranslated;
            $languages[$code] = [
                'translated'   => $translated,
                'untranslated' => $untranslated,
                'total'        => $total,
                'coverage'     => $total > 0 ? round($translated / $total * 100) . '%' : 'n/a',
            ];
        }

        Result::out(['post' => $id, 'languages' => $languages]);
    }

    // ---- helpers ----------------------------------------------------------

    private function table(string $code): string
    {
        global $wpdb;
        return $wpdb->prefix . 'trp_dictionary_' . strtolower($this->default) . '_' . strtolower($code);
    }

    private function assertPost(int $id): void
    {
        if ($id <= 0 || !get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
    }

    private function assertLang(string $code): void
    {
        if ($code === '' || $code === $this->default || !in_array($code, $this->langs, true)) {
            Result::fail("Unknown target language '{$code}'. Available: "
                . implode(', ', array_diff($this->langs, [$this->default])));
        }
    }

    private function translatedUrl(int $id, string $code): string
    {
        $langSlug = $this->urlSlugs[$code] ?? '';
        $home = home_url('/');
        $url  = str_replace($home, $home . ($langSlug !== '' ? $langSlug . '/' : ''), get_permalink($id));

        // Use the translated post slug when one exists (canonical URL); before
        // translation it does not, and the Italian slug under /<lang>/ still works.
        $translatedSlug = $this->translatedSlug($id, $code);
        if ($translatedSlug !== null && $translatedSlug !== '') {
            $original = get_post($id)->post_name;
            $url = preg_replace('~/' . preg_quote($original, '~') . '/?$~', '/' . $translatedSlug . '/', $url);
        }
        return $url;
    }

    private function translatedSlug(int $id, string $code): ?string
    {
        global $wpdb;
        $original     = get_post($id)->post_name;
        $originals    = $wpdb->prefix . 'trp_slug_originals';
        $translations = $wpdb->prefix . 'trp_slug_translations';
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT t.translated FROM {$translations} t JOIN {$originals} o ON t.original_id = o.id"
            . " WHERE o.original = %s AND t.language = %s",
            $original,
            $code
        ));
        return $val !== null ? (string) $val : null;
    }

    private function register(int $id, string $code): void
    {
        wp_remote_get($this->translatedUrl($id, $code), $this->requestArgs());
    }

    private function requestArgs(): array
    {
        return [
            'timeout'    => 30,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (AgentConnector) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        ];
    }

    /** Text of the post that TP would extract strings from. */
    private function haystack(int $id): string
    {
        $post  = get_post($id);
        $parts = [$post->post_title, $post->post_excerpt, apply_filters('the_content', $post->post_content)];
        $thumb = get_post_thumbnail_id($id);
        if ($thumb) {
            $parts[] = (string) get_post_meta($thumb, '_wp_attachment_image_alt', true);
        }
        return implode("\n", array_filter($parts));
    }

    /**
     * Fallback scoping for strings that were already registered before this run
     * (so the id-delta signal misses them). Deliberately strict: short tokens
     * like "Con", "e", "wp" occur as substrings of unrelated pages' HTML and
     * would be false positives, so we require a minimum length and true word
     * boundaries (not a sub-token match). Fresh post strings are already caught
     * precisely by the id-delta, so a slightly conservative fallback is safe.
     */
    private function contains(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        // TP stores rendered HTML (entities like &#8217;); test decoded too.
        $decoded = html_entity_decode($needle, ENT_QUOTES | ENT_HTML5);
        foreach (array_unique([$needle, $decoded]) as $candidate) {
            if (mb_strlen($candidate) < 6) {
                continue;
            }
            if ($this->boundedMatch($haystack, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** True if $needle appears in $haystack flanked by non-alphanumeric context. */
    private function boundedMatch(string $haystack, string $needle): bool
    {
        $len    = strlen($needle);
        $offset = 0;
        while (($pos = strpos($haystack, $needle, $offset)) !== false) {
            $before = $pos > 0 ? $haystack[$pos - 1] : ' ';
            $after  = ($pos + $len) < strlen($haystack) ? $haystack[$pos + $len] : ' ';
            if (!ctype_alnum($before) && !ctype_alnum($after)) {
                return true;
            }
            $offset = $pos + 1;
        }
        return false;
    }

    private function isUrl(string $s): bool
    {
        return (bool) preg_match('~^https?://~i', $s);
    }

    private function setSlug(int $id, string $code, string $translated): string
    {
        global $wpdb;
        $original = get_post($id)->post_name;
        $originals    = $wpdb->prefix . 'trp_slug_originals';
        $translations = $wpdb->prefix . 'trp_slug_translations';

        $oid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$originals} WHERE original = %s", $original));
        if (!$oid) {
            $wpdb->insert($originals, ['original' => $original, 'type' => 'other']);
            $oid = $wpdb->insert_id;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$translations} WHERE original_id = %d AND language = %s",
            $oid,
            $code
        ));
        if ($existing) {
            $wpdb->update($translations, ['translated' => $translated, 'status' => 2], ['id' => $existing]);
        } else {
            $wpdb->insert($translations, [
                'original_id' => $oid,
                'translated'  => $translated,
                'language'    => $code,
                'status'      => 2,
            ]);
        }
        return $translated;
    }
}
