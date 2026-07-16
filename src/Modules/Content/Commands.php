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
