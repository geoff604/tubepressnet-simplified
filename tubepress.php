<?php
/*
Plugin Name: TubePress.Net Simplified
Plugin URI: http://www.tubepress.net/
Description:  The Youtube Plugin for Wordpress, simplified to work with Youtube Data API (v3) using Client-Side Auth without backend tokens.
Author: Mario Mansour and Geoff Peters
Version: 4.3.0
Author URI: http://www.mariomansour.org/
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
To enable responsive video resizing, can add the following CSS into your theme:
16:9 Aspect Ratio (divide 9 by 16 = 0.5625)

.video-container {
  position: relative;
  overflow: hidden;
  width: 100%;
  padding-top: 56.25%;
}

.video-iframe {
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  right: 0;
  width: 100%;
  height: 100%;
}
*/

/*
 * You can acquire an OAuth 2.0 client ID from the
 * Google Developers Console <https://console.developers.google.com/>
 * For more information about using OAuth 2.0 to access Google APIs, please see:
 * <https://developers.google.com/youtube/v3/guides/authentication>
 * Please ensure that you have enabled the YouTube Data API for your project.
 * CONFIGURATION
 * We no longer need the Client Secret or Access Token on the server.
 */

// Optional constant fallback for OAuth 2.0 Client ID (can also be configured in TubePress Setup screen).
if (!defined('TP_OAUTH2_CLIENT_ID')) {
    define('TP_OAUTH2_CLIENT_ID', '');
}

define('DEFAULT_EXCERPT', '<img src="%tp_thumbnail%" /><br />%tp_title% was uploaded by: %tp_author%<br />Duration: %tp_duration%<br />Rating: %tp_rating_img%');
define('DEFAULT_CONTENT', '%tp_player%<p>%tp_description%</p>');

/**
 * Retrieves the configured Google OAuth 2.0 Client ID.
 */
function tp_get_client_id() {
    $options = get_option('tp_options', array());
    if (!empty($options['client_id'])) {
        return trim($options['client_id']);
    }
    if (defined('TP_OAUTH2_CLIENT_ID') && TP_OAUTH2_CLIENT_ID !== '' && TP_OAUTH2_CLIENT_ID !== 'FIXME') {
        return TP_OAUTH2_CLIENT_ID;
    }
    return '';
}

// -----------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------

function tp_player($id) {
    $safe_id = sanitize_text_field($id);
    return '<div class="video-container-outer"><div class="video-container"><iframe class="video-iframe" src="https://www.youtube.com/embed/' . esc_attr($safe_id) . '" frameborder="0" allowfullscreen></iframe></div></div>';
}

function tp_rating_c($r) {
    $r = floatval($r);
    $img = '';
    $t = 0;
    for ($i = 0; $i < floor($r); $i++) {
        $img .= '<img src="' . esc_url(plugins_url('/images/yt_rating_on.gif', __FILE__)) . '" alt="*" />';
    }
    if ($r > floor($r)) {
        $t = 1;
        $img .= '<img src="' . esc_url(plugins_url('/images/yt_rating_half.gif', __FILE__)) . '" alt="½" />';
    }
    $remaining = 5 - floor($r) - $t;
    for ($i = 0; $i < $remaining; $i++) {
        $img .= '<img src="' . esc_url(plugins_url('/images/yt_rating_off.gif', __FILE__)) . '" alt="" />';
    }
    return $img;
}

function tp_duplicate($id) {
    global $wpdb;
    $options = get_option('tp_options');
    if (empty($options['duplicate'])) {
        return false;
    }
    $safe_id = sanitize_text_field($id);
    if (empty($safe_id)) {
        return false;
    }
    $like_id = '%' . $wpdb->esc_like($safe_id) . '%';

    $post = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s LIMIT 1",
            $like_id,
            $like_id
        ),
        ARRAY_A
    );

    $field = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_trash_meta_status') LIMIT 1",
            $safe_id
        ),
        ARRAY_A
    );

    return (bool) (!empty($post) || !empty($field));
}

/**
 * Creates a WordPress post from sanitized video metadata received from client-side fetch.
 */
function tp_write_post_from_metadata($vid, $title, $description, $thumbnail, $youtube_tags = array(), $cat_string = '') {
    $tpo = get_option('tp_options', array());
    $post_template_excerpt = isset($tpo['excerpt']) ? $tpo['excerpt'] : '';
    $post_template_content = isset($tpo['content']) ? $tpo['content'] : '';
    $post_status = !empty($tpo['status']) ? $tpo['status'] : 'publish';

    $tp_tags = array("%tp_player%", "%tp_id%", "%tp_title%", "%tp_thumbnail%", "%tp_description%", "%tp_url%");
    $tag_values = array(
        tp_player($vid),
        $vid,
        $title,
        $thumbnail,
        $description,
        "https://www.youtube.com/watch?v=" . $vid
    );

    $post_template_excerpt = str_replace($tp_tags, $tag_values, $post_template_excerpt);
    $post_template_content = str_replace($tp_tags, $tag_values, $post_template_content);

    $post_category = array();
    if (!empty($cat_string)) {
        $raw_cats = explode(',', trim($cat_string, " \n\t\r\0\x0B,"));
        foreach ($raw_cats as $raw_cat) {
            $cleaned = sanitize_text_field(trim($raw_cat));
            if ($cleaned !== '') {
                $post_category[] = $cleaned;
            }
        }
    }

    $tp_post = array(
        'post_title'   => $title,
        'post_content' => nl2br($post_template_content),
        'post_status'  => $post_status,
        'post_type'    => !empty($tpo['type']) ? $tpo['type'] : 'post',
        'post_name'    => sanitize_title($title),
        'post_excerpt' => nl2br($post_template_excerpt),
    );

    $post_id = wp_insert_post($tp_post);
    if ($post_id && !is_wp_error($post_id)) {
        if (!empty($youtube_tags)) {
            wp_set_post_tags($post_id, $youtube_tags);
        }
        if (!empty($tpo['customfield'])) {
            foreach ($tp_tags as $k => $meta_key) {
                add_post_meta($post_id, str_replace("%", "", $meta_key), $tag_values[$k]);
            }
        }
        if (!empty($post_category)) {
            $cat_ids = array();
            foreach ($post_category as $cat_name) {
                $cat_id = get_cat_ID($cat_name);
                if (!$cat_id) {
                    $cat_id = wp_create_category($cat_name);
                }
                if ($cat_id) {
                    $cat_ids[] = (int) $cat_id;
                }
            }
            if (!empty($cat_ids)) {
                wp_set_post_categories($post_id, $cat_ids);
            }
        }
    }
    return $post_id;
}

/**
 * AJAX endpoint for client-side video import.
 * Note: The Google OAuth token never touches this backend endpoint.
 */
function tp_ajax_client_import_video() {
    check_ajax_referer('tp_client_import_action', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(esc_html__('You do not have permission to perform this action.'), 403);
    }

    $video_id = isset($_POST['video_id']) ? sanitize_text_field(trim($_POST['video_id'])) : '';
    if (empty($video_id) || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $video_id)) {
        wp_send_json_error(esc_html__('Invalid YouTube Video ID.'));
    }

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    if (empty($title)) {
        wp_send_json_error(esc_html__('Video title cannot be empty.'));
    }

    $description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
    $thumbnail   = isset($_POST['thumbnail']) ? esc_url_raw(wp_unslash($_POST['thumbnail'])) : '';
    
    $tags = array();
    if (!empty($_POST['tags'])) {
        $decoded = json_decode(wp_unslash($_POST['tags']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $tag) {
                $tags[] = sanitize_text_field($tag);
            }
        }
    }

    $cat = isset($_POST['cat']) ? sanitize_text_field(wp_unslash($_POST['cat'])) : '';

    if (tp_duplicate($video_id)) {
        wp_send_json_error(esc_html__('This video has already been imported into WordPress.'));
    }

    $post_id = tp_write_post_from_metadata($video_id, $title, $description, $thumbnail, $tags, $cat);
    if (!$post_id || is_wp_error($post_id)) {
        $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : __('Failed to create WordPress post.');
        wp_send_json_error(esc_html($error_msg));
    }

    wp_send_json_success(array(
        'post_id'   => $post_id,
        'title'     => $title,
        'thumbnail' => $thumbnail,
        'view_url'  => get_permalink($post_id),
        'edit_url'  => get_edit_post_link($post_id, 'raw'),
    ));
}
add_action('wp_ajax_tp_client_import_video', 'tp_ajax_client_import_video');

function tp_category_form($options) {
    $tpo = get_option('tp_options', array());
    $post_type = isset($tpo['type']) ? $tpo['type'] : 'post';
    $tf = '';
    if ($post_type === 'post') {
        $cat_val = isset($options['cat']) ? esc_attr($options['cat']) : '';
        $tf .= '<tr>';
        $tf .= '    <th scope="row"><label for="cat">' . esc_html__('Category') . '</label></th>';
        $tf .= '    <td><input name="cat" type="text" id="cat" class="regular-text" value="' . $cat_val . '" />';
        $tf .= '    <p class="description">' . esc_html__('Add the imported videos to this category (comma-separated).') . '</p></td>';
        $tf .= '</tr>';
    }
    return $tf;
}

function tp_order_form($options) {
    $orderoption = array("relevance" => "Relevance", "published" => "Published", "viewCount" => "View Count", "rating" => "Rating");
    $current = isset($options['orderby']) ? $options['orderby'] : 'relevance';
    $tf = '<tr><th scope="row">' . esc_html__('Order By') . '</th><td><select name="orderby">';
    foreach ($orderoption as $k => $v) {
        $selected = ($current === $k) ? ' selected="selected"' : '';
        $tf .= '<option value="' . esc_attr($k) . '"' . $selected . '>' . esc_html($v) . '</option>';
    }
    $tf .= '</select></td></tr>';
    return $tf;
}

function tp_comment_form($options) {
    return "";
}

function tp_copyright($style = null) {
    return "";
}

/**
 * Import By ID Screen.
 * Uses client-side Google OAuth and directly fetches YouTube metadata in browser.
 */
function tp_import_id() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }

    $client_id = tp_get_client_id();
    $default = array('video_id' => 'ImtuJ-kzsAc', 'cat' => '');
    $options = get_option('tp_options_id', array());
    $options = is_array($options) ? array_merge($default, $options) : $default;
    $nonce = wp_create_nonce('tp_client_import_action');
    ?>

    <div class="wrap">
        <h2><?php echo esc_html__('TubePress: Import By ID'); ?></h2>
        <?php echo tp_copyright(); ?>

        <?php if (empty($client_id)) : ?>
            <div class="notice notice-warning" style="padding: 15px; margin-top: 15px;">
                <h3><?php echo esc_html__('Google OAuth Client ID Required'); ?></h3>
                <p><?php echo esc_html__('To import videos, you need to configure your Google OAuth 2.0 Client ID in the setup screen.'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tubepress.php')); ?>" class="button button-primary">
                        <?php echo esc_html__('Go to TubePress Setup to Configure Client ID'); ?>
                    </a>
                </p>
            </div>
        <?php else : ?>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <script type="text/javascript">
            var tpConfig = {
                clientId: <?php echo json_encode($client_id); ?>,
                ajaxUrl: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo json_encode($nonce); ?>
            };

            var tpTokenClient = null;
            var currentAccessToken = null;

            function tpExtractVideoId(input) {
                if (!input) return '';
                input = input.trim();
                var match = input.match(/(?:youtube(?:-nocookie)?\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i);
                if (match && match[1]) {
                    return match[1];
                }
                if (/^[a-zA-Z0-9_-]{11}$/.test(input)) {
                    return input;
                }
                return '';
            }

            function tpGetClient() {
                if (!tpTokenClient && typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
                    tpTokenClient = google.accounts.oauth2.initTokenClient({
                        client_id: tpConfig.clientId,
                        scope: 'https://www.googleapis.com/auth/youtube.readonly',
                        callback: function(tokenResponse) {
                            if (tokenResponse.error) {
                                tpShowStatus('Google authorization error: ' + tokenResponse.error, 'error');
                                return;
                            }
                            currentAccessToken = tokenResponse.access_token;
                            tpFetchAndImport();
                        }
                    });
                }
                return tpTokenClient;
            }

            function tpShowStatus(message, type) {
                var container = document.getElementById('tp-import-status');
                container.style.display = 'block';
                var alertClass = 'notice-info';
                if (type === 'error') alertClass = 'notice-error';
                if (type === 'success') alertClass = 'notice-success';
                container.className = 'notice ' + alertClass;
                container.innerHTML = '<p>' + message + '</p>';
            }

            function tpStartImport() {
                var videoInput = document.getElementById('video_id').value.trim();
                var videoId = tpExtractVideoId(videoInput);
                if (!videoId) {
                    alert(<?php echo json_encode(__('Please enter a valid YouTube video ID or URL.')); ?>);
                    return;
                }

                if (currentAccessToken) {
                    tpFetchAndImport();
                } else {
                    var client = tpGetClient();
                    if (client) {
                        tpShowStatus(<?php echo json_encode(__('Requesting Google authorization...')); ?>, 'info');
                        client.requestAccessToken();
                    } else {
                        alert(<?php echo json_encode(__('Google Identity library is still loading. Please wait a moment and try again.')); ?>);
                    }
                }
            }

            async function tpFetchAndImport() {
                var videoInput = document.getElementById('video_id').value.trim();
                var videoId = tpExtractVideoId(videoInput);
                var catElement = document.getElementById('cat');
                var category = catElement ? catElement.value.trim() : '';

                tpShowStatus(<?php echo json_encode(__('Fetching video details directly from YouTube...')); ?>, 'info');

                try {
                    var ytUrl = 'https://www.googleapis.com/youtube/v3/videos?part=snippet&id=' + encodeURIComponent(videoId);
                    var ytResponse = await fetch(ytUrl, {
                        headers: {
                            'Authorization': 'Bearer ' + currentAccessToken
                        }
                    });

                    if (ytResponse.status === 401) {
                        currentAccessToken = null;
                        var client = tpGetClient();
                        if (client) {
                            tpShowStatus(<?php echo json_encode(__('Authorization expired. Requesting re-authorization...')); ?>, 'info');
                            client.requestAccessToken();
                        }
                        return;
                    }

                    var ytData = await ytResponse.json();
                    if (ytData.error) {
                        tpShowStatus('YouTube API Error: ' + (ytData.error.message || JSON.stringify(ytData.error)), 'error');
                        return;
                    }

                    if (!ytData.items || ytData.items.length === 0) {
                        tpShowStatus(<?php echo json_encode(__('No video found on YouTube with this ID.')); ?>, 'error');
                        return;
                    }

                    var videoItem = ytData.items[0];
                    var snippet = videoItem.snippet || {};

                    tpShowStatus(<?php echo json_encode(__('Video found! Creating WordPress post...')); ?>, 'info');

                    var formData = new URLSearchParams();
                    formData.append('action', 'tp_client_import_video');
                    formData.append('security', tpConfig.nonce);
                    formData.append('video_id', videoId);
                    formData.append('title', snippet.title || '');
                    formData.append('description', snippet.description || '');
                    var thumbUrl = (snippet.thumbnails && snippet.thumbnails.high && snippet.thumbnails.high.url) ? 
                                    snippet.thumbnails.high.url : 
                                    ((snippet.thumbnails && snippet.thumbnails.default) ? snippet.thumbnails.default.url : '');
                    formData.append('thumbnail', thumbUrl);
                    formData.append('tags', JSON.stringify(snippet.tags || []));
                    formData.append('cat', category);

                    var wpResponse = await fetch(tpConfig.ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    });

                    var wpData = await wpResponse.json();
                    if (wpData.success) {
                        var res = wpData.data;
                        var successHtml = '<strong>' + <?php echo json_encode(__('Video imported successfully!')); ?> + '</strong><br/>';
                        if (res.thumbnail) {
                            successHtml += '<div style="margin-top: 10px;"><img src="' + encodeURI(res.thumbnail) + '" width="120" height="90" style="vertical-align: middle; margin-right: 15px; border-radius: 4px;" />';
                        }
                        successHtml += '<span style="font-size: 15px; font-weight: 600;">' + (' ' + res.title).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></div>';
                        successHtml += '<div style="margin-top: 10px;">';
                        if (res.view_url) {
                            successHtml += '<a href="' + encodeURI(res.view_url) + '" target="_blank" class="button">' + <?php echo json_encode(__('View Post')); ?> + '</a> ';
                        }
                        if (res.edit_url) {
                            successHtml += '<a href="' + encodeURI(res.edit_url) + '" target="_blank" class="button">' + <?php echo json_encode(__('Edit Post')); ?> + '</a>';
                        }
                        successHtml += '</div>';
                        tpShowStatus(successHtml, 'success');
                    } else {
                        tpShowStatus(wpData.data || <?php echo json_encode(__('Failed to import video.')); ?>, 'error');
                    }
                } catch (err) {
                    tpShowStatus(<?php echo json_encode(__('An error occurred during import: ')); ?> + err.message, 'error');
                }
            }

            window.addEventListener('DOMContentLoaded', function() {
                tpGetClient();
            });
            </script>

            <div id="tp-import-status" class="notice" style="display:none; margin: 15px 0; padding: 12px;"></div>

            <form id="tp-import-form" onsubmit="event.preventDefault(); tpStartImport();">
                <table class="form-table" style="max-width: 669px;">
                    <tr>
                        <th scope="row"><label for="video_id"><?php echo esc_html__('Video ID or URL:'); ?></label></th>
                        <td>
                            <input name="video_id" type="text" id="video_id" class="regular-text" value="<?php echo esc_attr($options['video_id']); ?>" />
                            <p class="description">e.g. <strong>ImtuJ-kzsAc</strong> or https://www.youtube.com/watch?v=ImtuJ-kzsAc</p>
                        </td>
                    </tr>
                    <?php echo tp_category_form($options); ?>
                    <?php echo tp_comment_form($options); ?>
                </table>
                <p class="submit">
                    <button type="submit" id="tp-submit-btn" class="button button-primary" style="font-weight:bold;">
                        <?php echo esc_html__('Import This Video &raquo;'); ?>
                    </button>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Setup and Options Screen.
 */
function tp_manage_options() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }

    $warning = '';
    $default = array(
        'client_id'   => '',
        'width'       => '425',
        'height'      => '344',
        'autoplay'    => '0',
        'rel'         => '1',
        'color'       => '1',
        'border'      => '0',
        'duplicate'   => '1',
        'type'        => 'post',
        'status'      => 'publish',
        'customfield' => '0',
        'excerpt'     => '',
        'content'     => '',
        'upgraded'    => '0',
        'show_link'   => '0'
    );

    if (isset($_POST['update_tp'])) {
        check_admin_referer('tp_manage_options_action', 'tp_manage_options_nonce');

        $options = array();
        $options['client_id']   = isset($_POST['client_id']) ? sanitize_text_field(trim($_POST['client_id'])) : '';
        $options['width']       = isset($_POST['width']) ? absint($_POST['width']) : 425;
        $options['height']      = isset($_POST['height']) ? absint($_POST['height']) : 344;
        $options['autoplay']    = !empty($_POST['autoplay']) ? 1 : 0;

        $valid_statuses = array('publish', 'pending', 'draft', 'private');
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'publish';
        $options['status']      = in_array($status, $valid_statuses, true) ? $status : 'publish';

        $valid_types = array('post', 'page');
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'post';
        $options['type']        = in_array($type, $valid_types, true) ? $type : 'post';

        $options['duplicate']   = !empty($_POST['duplicate']) ? 1 : 0;
        $options['rel']         = !empty($_POST['rel']) ? 1 : 0;

        $color = isset($_POST['color']) ? absint($_POST['color']) : 1;
        $options['color']       = ($color >= 0 && $color <= 9) ? $color : 1;

        $color_map = array(
            1 => array('0xd6d6d6', '0xf0f0f0'),
            2 => array('0x3a3a3a', '0x999999'),
            3 => array('0x2b405b', '0x6b8ab6'),
            4 => array('0x006699', '0x54abd6'),
            5 => array('0x234900', '0x4e9e00'),
            6 => array('0xe1600f', '0xfebd01'),
            7 => array('0xcc2550', '0xe87a9f'),
            8 => array('0x402061', '0x9461ca'),
            9 => array('0x5d1719', '0xcd311b'),
        );
        if (isset($color_map[$options['color']])) {
            $options['color1'] = $color_map[$options['color']][0];
            $options['color2'] = $color_map[$options['color']][1];
        }

        $options['border']      = !empty($_POST['border']) ? 1 : 0;
        $options['customfield'] = !empty($_POST['customfield']) ? 1 : 0;
        $options['content']     = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        $options['excerpt']     = isset($_POST['excerpt']) ? wp_kses_post(wp_unslash($_POST['excerpt'])) : '';
        $options['show_link']   = !empty($_POST['show_link']) ? 1 : 0;

        $data = array('link_name' => 'TubePress', 'link_url' => 'http://www.tubepress.net/');
        if ($options['show_link']) {
            tp_insert_link($data);
        } else {
            tp_remove_link();
        }

        $options['upgraded'] = '1';
        update_option('tp_options', $options);

        if (!$options['customfield'] && (empty($options['content']) || empty($options['excerpt']))) {
            $warning .= '<p><strong>' . esc_html__('You have to customize the Content Template and/or Content Excerpt, otherwise your posts/pages will not show the imported videos') . '</strong></p>';
        } elseif ($options['customfield'] && empty($options['content']) && empty($options['excerpt'])) {
            $warning .= '<p><strong>' . sprintf(
                /* translators: %s: link to theme editor */
                esc_html__('Do not forget to %sedit your template%s to make use of these custom fields instead of the default the_content() and the_excerpt() calls'),
                '<a href="' . esc_url(admin_url('theme-editor.php')) . '">',
                '</a>'
            ) . '</strong></p>';
        }
        ?>
        <div class="updated notice is-dismissible">
            <p><?php echo esc_html__('Options Saved!'); ?></p>
            <?php if (!empty($warning)) echo wp_kses_post($warning); ?>
        </div>
        <?php
    } else {
        $opt = get_option('tp_options');
        $options = is_array($opt) ? array_merge($default, $opt) : $default;
        if (empty($options['client_id']) && defined('TP_OAUTH2_CLIENT_ID') && TP_OAUTH2_CLIENT_ID !== 'FIXME') {
            $options['client_id'] = TP_OAUTH2_CLIENT_ID;
        }
    }

    $site_origin = wp_parse_url(home_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(home_url(), PHP_URL_HOST);
    $port = wp_parse_url(home_url(), PHP_URL_PORT);
    if ($port) {
        $site_origin .= ':' . $port;
    }
    ?>
    <style type="text/css">
        .tp-color { padding: 5px 12px; text-align: center; }
        .tp-guide-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-left: 4px solid #72aee6;
            padding: 15px 20px;
            margin: 20px 0;
            max-width: 850px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .tp-guide-box h3 { margin-top: 0; }
        .tp-guide-box ol { margin-left: 20px; }
        .tp-guide-box li { margin-bottom: 8px; }
        .tp-guide-box code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
    </style>
    <script type="text/javascript">
        function tpPreview() {
            var border = document.getElementById('border');
            var previewImage = document.getElementById('tp-preview');
            var color = "1";
            var getColor = document.getElementsByName('color');
                    
            for (var i = 0; i < getColor.length; i++) {
                if (getColor[i].checked) { color = getColor[i].value; }
            }
            var type = (border && border.checked) ? 'border' : 'color';
            var basePath = <?php echo json_encode(plugins_url('/images/', __FILE__)); ?>;
            previewImage.innerHTML = '<img src="' + basePath + encodeURIComponent(type + color) + '.gif" alt="" />';
            previewImage.style.display = 'block';
        }
        function tpToggle() {
            var previewImage = document.getElementById('tp-preview');
            if (previewImage) {
                previewImage.style.display = 'none';
            }
        }
    </script>
    <div class="wrap">
        <h2><?php echo esc_html__('TubePress Setup'); ?></h2>
        
        <div class="tp-guide-box">
            <h3><?php echo esc_html__('Google API Client-Side Authentication Setup'); ?></h3>
            <p>
                <?php echo esc_html__('TubePress uses modern Google Client-Side OAuth 2.0 to import YouTube videos directly from your browser. This means your Google access token and secrets are never stored on your WordPress server.'); ?>
            </p>
            <h4><?php echo esc_html__('How to get a Google OAuth 2.0 Client ID:'); ?></h4>
            <ol>
                <li>
                    <?php echo sprintf(
                        /* translators: %s: Google Cloud Console URL */
                        esc_html__('Open the %sGoogle Cloud Console Credentials Page%s and create or select a project.'),
                        '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer"><strong>',
                        '</strong> &raquo;</a>'
                    ); ?>
                </li>
                <li>
                    <?php echo sprintf(
                        /* translators: %s: Google API Library URL */
                        esc_html__('Enable the %sYouTube Data API v3%s for your project in the Google API Library.'),
                        '<a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com" target="_blank" rel="noopener noreferrer">',
                        ' &raquo;</a>'
                    ); ?>
                </li>
                <li>
                    <?php echo esc_html__('Configure the OAuth Consent Screen (choose "External", enter an App Name such as "My TubePress Importer", and add your email).'); ?>
                </li>
                <li>
                    <?php echo esc_html__('Click "+ CREATE CREDENTIALS" > "OAuth client ID". Choose "Web application" as the Application Type.'); ?>
                </li>
                <li>
                    <?php echo sprintf(
                        /* translators: %s: Site origin URL */
                        esc_html__('Under "Authorized JavaScript origins", add this site\'s origin: %s'),
                        '<code>' . esc_html($site_origin) . '</code>'
                    ); ?>
                    <br/>
                    <small style="color: #666;"><?php echo esc_html__('(Note: Google does not require a redirect URI because authorization runs client-side via popup).'); ?></small>
                </li>
                <li>
                    <?php echo esc_html__('Copy your Client ID (e.g. xxxxxxxx.apps.googleusercontent.com) and paste it into the field below.'); ?>
                </li>
            </ol>
            <p>
                <strong><?php echo esc_html__('Official Google Guides:'); ?></strong> 
                <a href="https://developers.google.com/youtube/v3/guides/authentication" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('YouTube Data API Auth Guide'); ?></a> &bull;
                <a href="https://developers.google.com/identity/gsi/web/guides/overview" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Google Identity Services (GSI) Guide'); ?></a>
            </p>
        </div>

        <form method="post">
        <?php wp_nonce_field('tp_manage_options_action', 'tp_manage_options_nonce'); ?>
        <table class="form-table" style="max-width: 850px;">
            <tr>
                <th scope="row"><label for="client_id"><strong><?php echo esc_html__('Google OAuth Client ID:'); ?></strong></label></th>
                <td colspan="2">
                    <input name="client_id" type="text" id="client_id" class="large-text code" value="<?php echo esc_attr($options['client_id']); ?>" placeholder="xxxxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com" />
                    <p class="description">
                        <?php echo esc_html__('Obtained from the Google Cloud Console under APIs & Services > Credentials.'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="width"><?php echo esc_html__('Video Player Width'); ?></label></th>
                <td><input name="width" type="text" id="width" class="small-text" value="<?php echo esc_attr($options['width']); ?>" /></td>
                <?php $type = (!empty($options['border'])) ? 'border' : 'color'; ?>
                <td rowspan="6">
                    <div id="tp-preview" <?php if ($options['color'] == 0) echo 'style="display:none;"'; ?>>
                        <img src="<?php echo esc_url(plugins_url('/images/' . $type . $options['color'] . '.gif', __FILE__)); ?>" alt="" />
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="height"><?php echo esc_html__('Video Player Height'); ?></label></th>
                <td><input name="height" type="text" id="height" class="small-text" value="<?php echo esc_attr($options['height']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Autoplay Videos ?'); ?></th>
                <td><label><input name="autoplay" type="checkbox" id="autoplay" value="1" <?php checked(!empty($options['autoplay'])); ?> /> <?php echo esc_html__('Enable autoplay'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Hide Related Videos ?'); ?></th>
                <td><label><input name="rel" type="checkbox" id="rel" value="1" <?php checked(!empty($options['rel'])); ?> /> <?php echo esc_html__('Hide related videos'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Show Border?'); ?></th>
                <td><label><input onclick="tpPreview();" name="border" type="checkbox" id="border" value="1" <?php checked(!empty($options['border'])); ?> /> <?php echo esc_html__('Show border'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Add TubePress link to blogroll?'); ?></th>
                <td><label><input name="show_link" type="checkbox" id="show_link" value="1" <?php checked(!empty($options['show_link'])); ?> /> <?php echo esc_html__('Add link'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Customize player color'); ?></th>
                <td colspan="2">
                <table>
                    <tr>
                        <td class="tp-color" style="background: #ababab;"><input onclick="tpPreview();" type="radio" name="color" value="1" <?php checked($options['color'], 1); ?>></td>
                        <td class="tp-color" style="background: #6a6a6a;"><input onclick="tpPreview();" type="radio" name="color" value="2" <?php checked($options['color'], 2); ?>></td>               
                        <td class="tp-color" style="background: #4b6589;"><input onclick="tpPreview();" type="radio" name="color" value="3" <?php checked($options['color'], 3); ?>></td>
                        <td class="tp-color" style="background: #2a89b8;"><input onclick="tpPreview();" type="radio" name="color" value="4" <?php checked($options['color'], 4); ?>></td>
                        <td class="tp-color" style="background: #397400;"><input onclick="tpPreview();" type="radio" name="color" value="5" <?php checked($options['color'], 5); ?>></td>
                        <td class="tp-color" style="background: #f08f08;"><input onclick="tpPreview();" type="radio" name="color" value="6" <?php checked($options['color'], 6); ?>></td>
                        <td class="tp-color" style="background: #da5078;"><input onclick="tpPreview();" type="radio" name="color" value="7" <?php checked($options['color'], 7); ?>></td>
                        <td class="tp-color" style="background: #6a4196;"><input onclick="tpPreview();" type="radio" name="color" value="8" <?php checked($options['color'], 8); ?>></td>
                        <td class="tp-color" style="background: #95241a;"><input onclick="tpPreview();" type="radio" name="color" value="9" <?php checked($options['color'], 9); ?>></td>
                    </tr>
                </table>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Remove Duplication'); ?></th>
                <td colspan="2"><label><input name="duplicate" type="checkbox" id="duplicate" value="1" <?php checked(!empty($options['duplicate'])); ?> /> <?php echo esc_html__('Do not import duplicate videos'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="type"><?php echo esc_html__('Put each video in'); ?></label></th>
                <td colspan="2">
                    <select name="type" id="type">
                        <option value="post" <?php selected($options['type'], 'post'); ?>>post</option>
                        <option value="page" <?php selected($options['type'], 'page'); ?>>page</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="status"><?php echo esc_html__('Post Status'); ?></label></th>
                <td colspan="2">
                    <select name="status" id="status">
                        <option value="publish" <?php selected($options['status'], 'publish'); ?>><?php echo esc_html__('Published'); ?></option>
                        <option value="pending" <?php selected($options['status'], 'pending'); ?>><?php echo esc_html__('Pending'); ?></option>
                        <option value="draft" <?php selected($options['status'], 'draft'); ?>><?php echo esc_html__('Draft'); ?></option>
                        <option value="private" <?php selected($options['status'], 'private'); ?>><?php echo esc_html__('Private'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Add Custom Fields'); ?></th>
                <td colspan="2">
                    <label><input name="customfield" type="checkbox" id="customfield" value="1" <?php checked(!empty($options['customfield'])); ?> /> <?php echo esc_html__('Enable custom fields'); ?></label>
                    <p class="description"><?php echo esc_html__('Custom Fields: tp_player, tp_thumbnail, tp_title, tp_description, tp_duration, tp_author, tp_tags, tp_rating_num, tp_rating_img, tp_viewcount, tp_id, tp_url'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="content"><?php echo esc_html__('Content Template'); ?></label></th>
                <td><textarea name="content" id="content" cols="60" rows="7" class="large-text code"><?php echo esc_textarea($options['content']); ?></textarea></td>
                <td><h3><?php echo esc_html__('Example Template:'); ?></h3><code><?php echo esc_html(DEFAULT_CONTENT); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><label for="excerpt"><?php echo esc_html__('Excerpt Template'); ?></label></th>
                <td><textarea name="excerpt" id="excerpt" cols="60" rows="7" class="large-text code"><?php echo esc_textarea($options['excerpt']); ?></textarea></td>
                <td><h3><?php echo esc_html__('Example Template:'); ?></h3><code><?php echo esc_html(DEFAULT_EXCERPT); ?></code></td>
            </tr>
        </table>
        <h2><?php echo esc_html__('TubePress Template Tags'); ?></h2>
        <p><?php echo esc_html__('Use these tags to make your own content and excerpt templates:'); ?></p>
        <ul>
            <li><strong>%tp_player%</strong>: <?php echo esc_html__('Displays the video player'); ?></li>
            <li><strong>%tp_thumbnail%</strong>: <?php echo esc_html__('Displays the thumbnail image'); ?></li>
            <li><strong>%tp_title%</strong>: <?php echo esc_html__('Displays the title of the video'); ?></li>
            <li><strong>%tp_description%</strong>: <?php echo esc_html__('Displays the description of the video'); ?></li>
            <li><strong>%tp_id%</strong>: <?php echo esc_html__('Displays the video id'); ?></li>
            <li><strong>%tp_url%</strong>: <?php echo esc_html__('Displays the youtube video url'); ?></li>
        </ul>
        <p class="submit">
            <input type="submit" name="update_tp" class="button button-primary" value="<?php echo esc_attr__('Save Options &raquo;', 'update_tp'); ?>" style="font-weight:bold;" />
        </p>
        </form>
        <?php echo tp_copyright('noformat'); ?>
    </div>
<?php
}

function tp_insert_link($data) {
    global $wpdb;
    if (!function_exists('wp_insert_link')) {
        return;
    }
    $url = esc_url_raw($data['link_url']);
    $link_id = $wpdb->get_var($wpdb->prepare("SELECT link_id FROM {$wpdb->links} WHERE link_url = %s", $url));
    if ($link_id === null) {
        $link_id = wp_insert_link($data);
    }
    update_option('tp_link_id', $link_id);
}

function tp_remove_link() {
    if (!function_exists('wp_delete_link')) {
        return;
    }
    $link_id = get_option('tp_link_id');
    if ($link_id) {
        wp_delete_link($link_id);
        delete_option('tp_link_id');
    }
}

function tp_patch() {
    // Legacy helper kept for backward compatibility
}

function tp_upgrade() {
    global $wpdb;
    $posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%[/ID]%'", ARRAY_A);
    if (!is_array($posts)) {
        return false;
    }
    foreach ($posts as $post) {
        $post_id = $post['ID'];
        $content = $post['post_content'];

        if (preg_match_all("|\[[A-Z]+\](.*)\[\/[A-Z]+\]|sU", $content, $match)) {
            $excerpt = '<table><tr>';
            $ytimg = (empty($match[1][3])) ? "https://i.ytimg.com/vi/" . $match[1][1] . "/default.jpg" : $match[1][3];
            $excerpt .= '<td><img src="' . esc_url($ytimg) . '" border="0"></td><td></td></tr></table>';
            $excerpt .= "<p>" . esc_html($match[1][0]) . "</p>";

            $vid_id = sanitize_text_field($match[1][1]);
            $display = tp_player($vid_id);
            $display .= '<p>' . esc_html($match[1][0]) . '</p>';

            $postarr = array('ID' => $post_id, 'post_content' => $display, 'post_excerpt' => $excerpt);
            wp_update_post($postarr);
        }
    }
}

function tp_add_options_page() {
    add_menu_page('TubePress', 'TubePress', 'manage_options', 'tubepress.php', 'tp_manage_options');
    add_submenu_page('tubepress.php', 'Setup', 'Setup', 'manage_options', 'tubepress.php', 'tp_manage_options');
    add_submenu_page('tubepress.php', 'Import By ID', 'Import By ID', 'manage_options', 'tubepress-id.php', 'tp_import_id');
}
add_action('admin_menu', 'tp_add_options_page');
