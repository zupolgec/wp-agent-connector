<?php

namespace AgentConnector\Modules\Content;

use AgentConnector\Core\Result;

/**
 * Post + media helpers that bundle several fiddly steps into one call:
 * create/update a post, sideload a featured image from a URL, assign
 * category/tag, and set a real publish date that survives the draft->publish
 * transition (WordPress otherwise resets a draft's date to "now" on publish).
 */
class Commands
{
    /**
     * Create or update a post from a bundle of fields.
     *
     * ## OPTIONS
     * [--id=<id>]
     * : Update this post instead of creating a new one.
     * [--title=<title>]
     * [--content=<file>]
     * : Path to an HTML/blocks file, or "-" for STDIN.
     * [--excerpt=<excerpt>]
     * [--status=<status>]
     * : draft (default) or publish.
     * [--date=<datetime>]
     * : Local "YYYY-MM-DD HH:MM:SS". Applied so it survives publish.
     * [--cat=<ids>]
     * : Comma-separated category term IDs.
     * [--tag=<slugs>]
     * : Comma-separated tag slugs (reused if they exist).
     * [--featured-url=<url>]
     * : Image URL to sideload and set as featured image.
     * [--alt=<text>]
     * : Alt text for the sideloaded featured image.
     *
     * ## EXAMPLES
     *   cat post.html | wp agent content bundle --title="..." --content=- \
     *     --status=publish --date="2025-10-11 11:00:00" --cat=116 --tag=arte-e-cultura \
     *     --featured-url="https://.../img.jpg" --alt="..."
     */
    public function bundle($args, $assoc)
    {
        $id      = isset($assoc['id']) ? (int) $assoc['id'] : 0;
        $status  = $assoc['status'] ?? 'draft';
        $date    = $assoc['date'] ?? '';

        if ($id && !get_post($id)) {
            // wp_insert_post would silently ignore an unknown ID and create a
            // new post: fail loudly instead of duplicating content.
            Result::fail("Post not found: {$id}");
        }
        $this->assertDate($date);

        $postarr = ['post_type' => 'post'];
        if ($id) {
            $postarr['ID'] = $id;
        }
        if (isset($assoc['title'])) {
            $postarr['post_title'] = $assoc['title'];
        }
        if (isset($assoc['excerpt'])) {
            $postarr['post_excerpt'] = $assoc['excerpt'];
        }
        if (isset($assoc['content'])) {
            $c = $assoc['content'] === '-' ? file_get_contents('php://stdin') : @file_get_contents($assoc['content']);
            if ($c === false) {
                Result::fail("Cannot read --content: {$assoc['content']}");
            }
            $postarr['post_content'] = $c;
        }
        // Create as draft first; publish + date are applied deliberately below.
        $postarr['post_status'] = $id ? get_post_status($id) : 'draft';
        if ($date !== '') {
            $postarr['post_date']     = $date;
            $postarr['post_date_gmt'] = get_gmt_from_date($date);
            $postarr['edit_date']     = true;
        }

        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) {
            Result::fail($id->get_error_message());
        }

        if (isset($assoc['cat'])) {
            wp_set_post_categories($id, array_map('intval', explode(',', $assoc['cat'])));
        }
        if (isset($assoc['tag'])) {
            wp_set_post_terms($id, array_map('trim', explode(',', $assoc['tag'])), 'post_tag');
        }

        $attachment = null;
        if (!empty($assoc['featured-url'])) {
            $attachment = $this->setFeaturedFromUrl($id, $assoc['featured-url'], $assoc['alt'] ?? '');
            if (is_wp_error($attachment)) {
                Result::fail('Featured image: ' . $attachment->get_error_message());
            }
        }

        // Apply publish + date last, then re-assert date (publish resets a
        // former draft's date to now).
        if ($status === 'publish') {
            $update = ['ID' => $id, 'post_status' => 'publish'];
            if ($date !== '') {
                $update['post_date']     = $date;
                $update['post_date_gmt'] = get_gmt_from_date($date);
                $update['edit_date']     = true;
            }
            wp_update_post($update);
            if ($date !== '') {
                wp_update_post([
                    'ID'            => $id,
                    'post_date'     => $date,
                    'post_date_gmt' => get_gmt_from_date($date),
                    'edit_date'     => true,
                ]);
            }
        }

        $post = get_post($id);
        Result::out([
            'id'         => $id,
            'status'     => $post->post_status,
            'date'       => $post->post_date,
            'url'        => get_permalink($id),
            'slug'       => $post->post_name,
            'attachment' => $attachment,
        ]);
    }

    /**
     * Publish a post with a real date that survives the draft->publish reset.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * [--date=<datetime>]
     * : Local "YYYY-MM-DD HH:MM:SS" to set (and re-assert after publish).
     *
     * ## EXAMPLES
     *   wp agent content publish 3697 --date="2025-11-05 11:00:00"
     */
    public function publish($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $date = $assoc['date'] ?? '';
        $this->assertDate($date);

        $update = ['ID' => $id, 'post_status' => 'publish'];
        if ($date !== '') {
            $update['post_date']     = $date;
            $update['post_date_gmt'] = get_gmt_from_date($date);
            $update['edit_date']     = true;
        }
        wp_update_post($update);
        if ($date !== '') {
            // Re-assert: WordPress resets a former draft's date to now on publish.
            wp_update_post(['ID' => $id, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date($date), 'edit_date' => true]);
        }
        $post = get_post($id);
        Result::out(['id' => $id, 'status' => $post->post_status, 'date' => $post->post_date, 'url' => get_permalink($id)]);
    }

    /**
     * Set a post's featured image by sideloading it from a URL.
     *
     * ## OPTIONS
     * <post_id>
     * : The post ID.
     * --url=<url>
     * : Image URL to sideload.
     * [--alt=<text>]
     * : Alt text for the image.
     *
     * ## EXAMPLES
     *   wp agent content featured 3697 --url="https://.../img.jpg" --alt="..."
     */
    public function featured($args, $assoc)
    {
        $id = (int) ($args[0] ?? 0);
        if (!get_post($id)) {
            Result::fail("Post not found: {$id}");
        }
        $url = $assoc['url'] ?? '';
        if ($url === '') {
            Result::fail('--url is required.');
        }
        $att = $this->setFeaturedFromUrl($id, $url, $assoc['alt'] ?? '');
        if (is_wp_error($att)) {
            Result::fail('Featured image: ' . $att->get_error_message());
        }
        Result::out(['id' => $id, 'attachment' => $att, 'thumbnail' => get_post_thumbnail_id($id)]);
    }

    /**
     * Refuse malformed dates instead of letting WordPress store garbage.
     * The strtotime roundtrip catches impossible dates like 2025-02-30.
     */
    private function assertDate(string $date): void
    {
        if ($date === '') {
            return;
        }
        $ts = strtotime($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)
            || $ts === false
            || date('Y-m-d H:i:s', $ts) !== $date) {
            Result::fail("Invalid --date '{$date}'. Expected format: YYYY-MM-DD HH:MM:SS.");
        }
    }

    private function setFeaturedFromUrl(int $postId, string $url, string $alt)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $file = ['name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
        $attId = media_handle_sideload($file, $postId);
        if (is_wp_error($attId)) {
            @unlink($tmp);
            return $attId;
        }
        set_post_thumbnail($postId, $attId);
        if ($alt !== '') {
            update_post_meta($attId, '_wp_attachment_image_alt', $alt);
        }
        return (int) $attId;
    }
}
