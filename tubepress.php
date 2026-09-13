<?php
/*
Plugin Name: TubePress.Net Simplified
Plugin URI: http://www.tubepress.net/
Description:  The Youtube Plugin for Wordpress, simplified to work with Youtube Data API (v3) using Client-Side Auth.
Author: Mario Mansour and Geoff Peters
Version: 4.2.1
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

// Set up include path for Google API PHP Client API.
// For more information, see: https://developers.google.com/api-client-library/php/
if (!defined('TP_VENDOR_AUTOLOAD')) {
    define('TP_VENDOR_AUTOLOAD', __DIR__ . '/vendor/autoload.php');
}

/*
 * You can acquire an OAuth 2.0 client ID from the
 * Google Developers Console <https://console.developers.google.com/>
 * For more information about using OAuth 2.0 to access Google APIs, please see:
 * <https://developers.google.com/youtube/v3/guides/authentication>
 * Please ensure that you have enabled the YouTube Data API for your project.
 * CONFIGURATION
 * We no longer need the Client Secret on the server.
 */
// Google OAuth 2.0 Client ID. Can be overridden in wp-config.php.
if (!defined('TP_OAUTH2_CLIENT_ID')) {
    define('TP_OAUTH2_CLIENT_ID', 'FIXME');
}

define('DEFAULT_EXCERPT', '<img src="%tp_thumbnail%" /><br />%tp_title% was uploaded by: %tp_author%<br />Duration: %tp_duration%<br />Rating: %tp_rating_img%');
define('DEFAULT_CONTENT', '%tp_player%<p>%tp_description%</p>');

/**
 * Returns the path to the Composer vendor autoload file.
 */
function tp_get_autoload_path() {
    return apply_filters('tubepress_autoload_path', TP_VENDOR_AUTOLOAD);
}

/**
 * Admin notice if dependencies are missing.
 */
function tp_check_dependencies() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'tubepress') !== false) {
        $autoload = tp_get_autoload_path();
        if (!file_exists($autoload)) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('TubePress:') . '</strong> ' .
                 sprintf(
                     /* translators: %s: path to vendor/autoload.php */
                     esc_html__('Google API PHP Client autoload not found at %s. Please ensure dependencies are installed via Composer or configure the vendor path.'),
                     '<code>' . esc_html($autoload) . '</code>'
                 ) . '</p></div>';
        }
    }
}
add_action('admin_notices', 'tp_check_dependencies');

/**
 * Retrieves the stored access token from user transient or sanitized cookie.
 */
function tp_get_access_token() {
    $user_id = get_current_user_id();
    if ($user_id) {
        $token = get_transient('tp_access_token_' . $user_id);
        if (!empty($token)) {
            return sanitize_text_field($token);
        }
    }
    if (isset($_COOKIE['tp_access_token'])) {
        return sanitize_text_field(wp_unslash($_COOKIE['tp_access_token']));
    }
    return null;
}

/**
 * Lazy loader for Google Client and YouTube Service.
 */
function tp_get_youtube_service() {
    static $youtubeService = null;
    if ($youtubeService !== null) {
        return $youtubeService;
    }

    $autoload = tp_get_autoload_path();
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('Google_Client') || !class_exists('Google_Service_YouTube')) {
        return null;
    }

    $client = new Google_Client();
    $client->setClientId(TP_OAUTH2_CLIENT_ID);
    // Scopes are now handled in the JS, but good to keep here for reference or validation if needed
    $client->setScopes('https://www.googleapis.com/auth/youtube.readonly');

    $token = tp_get_access_token();
    if ($token) {
        $accessToken = array(
            'access_token' => $token,
            'expires_in'   => 3600,
            'created'      => time(),
            'scope'        => 'https://www.googleapis.com/auth/youtube.readonly',
        );
        $client->setAccessToken($accessToken);
    }

    $youtubeService = new Google_Service_YouTube($client);
    return $youtubeService;
}

/**
 * AJAX handler to securely save OAuth token in transient.
 */
function tp_ajax_save_token() {
    check_ajax_referer('tp_auth_nonce', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(esc_html__('Unauthorized'), 403);
    }
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $expires_in = isset($_POST['expires_in']) ? absint($_POST['expires_in']) : 3600;
    if (!empty($token)) {
        set_transient('tp_access_token_' . get_current_user_id(), $token, $expires_in);
        wp_send_json_success();
    }
    wp_send_json_error(esc_html__('No token provided'));
}
add_action('wp_ajax_tp_save_token', 'tp_ajax_save_token');

// -----------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------

function getYoutubeTags($videoId) {
    $info = getYoutubeVideoInfo($videoId);
    if (is_object($info) && !is_wp_error($info) && !empty($info->items[0]->snippet->tags)) {
        return (array) $info->items[0]->snippet->tags;
    }
    return array();
}

/**
 * Renders the Google OAuth client-side authorization prompt.
 */
function tp_render_auth_screen() {
    $client_id = TP_OAUTH2_CLIENT_ID;
    $nonce = wp_create_nonce('tp_auth_nonce');
    ?>
    <div class="wrap">
        <div class="notice notice-info" style="padding: 15px;">
            <h3><?php echo esc_html__('YouTube Authorization Required'); ?></h3>
            <p><?php echo esc_html__('You need to authorize access via Google to import videos.'); ?></p>
            
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <script>
            var tpClient;
            function tpInitClient() {
                if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
                    return;
                }
                tpClient = google.accounts.oauth2.initTokenClient({
                    client_id: '<?php echo esc_js($client_id); ?>',
                    scope: 'https://www.googleapis.com/auth/youtube.readonly',
                    callback: function(response) {
                        if (response.access_token) {
                            // Store the token in a cookie for 1 hour (3600 seconds)
                            var secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
                            var maxAge = parseInt(response.expires_in, 10) || 3600;
                            document.cookie = "tp_access_token=" + encodeURIComponent(response.access_token) + 
                                              "; max-age=" + maxAge + 
                                              "; path=/; SameSite=Lax" + secureFlag;
                            
                            // Also save via AJAX to transient
                            var formData = new URLSearchParams();
                            formData.append('action', 'tp_save_token');
                            formData.append('token', response.access_token);
                            formData.append('expires_in', maxAge);
                            formData.append('security', '<?php echo esc_js($nonce); ?>');

                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: formData
                            }).finally(function() {
                                // Reload the page so PHP can read the cookie
                                window.location.reload();
                            });
                        }
                    }
                });
            }

            function tpGetToken() {
                // Trigger the popup
                if (!tpClient) {
                    tpInitClient();
                }
                if (tpClient) {
                    tpClient.requestAccessToken();
                } else {
                    alert('<?php echo esc_js(__('Google Sign-In is still loading. Please wait a moment and try again.')); ?>');
                }
            }

            // Initialize the client when the script loads
            window.addEventListener('DOMContentLoaded', function() {
                tpInitClient();
            });
            </script>

            <p>
                <button type="button" onclick="tpGetToken()" class="button button-primary">
                    <?php echo esc_html__('Authorize with YouTube'); ?>
                </button>
            </p>
        </div>
    </div>
    <?php
}

function getYoutubeVideoInfo($videoId) {
    $youtubeService = tp_get_youtube_service();
    if (!$youtubeService) {
        return new WP_Error('missing_dependency', __('Google API PHP Client is not loaded or vendor autoload is missing.'));
    }

    $token = tp_get_access_token();
    if (!$token) {
        return new WP_Error('auth_required', __('Authorization required'));
    }

    try {
        $client = $youtubeService->getClient();
        if ($client->isAccessTokenExpired()) {
            return new WP_Error('auth_required', __('Access token expired'));
        }
        $listResponse = $youtubeService->videos->listVideos("snippet", array('id' => $videoId));
        return $listResponse;
    } catch (Google_Service_Exception $e) {
        return new WP_Error('google_service_error', $e->getMessage());
    } catch (Google_Exception $e) {
        return new WP_Error('google_client_error', $e->getMessage());
    } catch (Exception $e) {
        return new WP_Error('general_error', $e->getMessage());
    }
}

function tp_curl_fetch_object($url) {
    $response = wp_remote_get($url, array('sslverify' => true));
    if (is_wp_error($response)) {
        return null;
    }
    $body = wp_remote_retrieve_body($response);
    return json_decode($body);
}

function tp_get_list($options, $action = 'tag') {
    $warning = '';
    $status = 0;
    $gen_options = get_option('tp_options');
    if (!$gen_options['customfield'] && (empty($gen_options['content']) || empty($gen_options['excerpt']))) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>' .
             sprintf(
                 /* translators: %s: link to settings page */
                 esc_html__('You have to %scustomize the Content Template and/or Content Excerpt%s, otherwise your posts/pages will not show the imported videos.'),
                 '<a href="' . esc_url(admin_url('admin.php?page=tubepress.php')) . '">',
                 '</a>'
             ) . '</strong></p></div>';
        return false;
    } elseif ($gen_options['customfield'] && empty($gen_options['content']) && empty($gen_options['excerpt'])) {
        $warning .= '<p><strong>' . sprintf(
            /* translators: %s: link to theme editor */
            esc_html__('Do not forget to %sedit your template%s to make use of these custom fields instead of the default the_content() and the_excerpt() calls.'),
            '<a href="' . esc_url(admin_url('theme-editor.php')) . '">',
            '</a>'
        ) . '</strong></p>';
    }

    if (!is_array($options)) {
        return false;
    }

    $xml = null;
    switch ($action) {
        case 'id':
            $xml = getYoutubeVideoInfo($options['video_id']);
            break;
    }

    if (is_wp_error($xml)) {
        if ($xml->get_error_code() === 'auth_required') {
            tp_render_auth_screen();
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html($xml->get_error_message()) . '</p></div>';
        return;
    }

    // Safety check: ensure we actually got an object back before trying to read it
    if (!is_object($xml)) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h2>' . esc_html__('Imported Video List') . '</h2>';

    echo '<div align="center">';
    if (isset($xml->items) && is_array($xml->items)) {
        foreach ($xml->items as $video) {
            if (!tp_duplicate($video->id)) {
                $status = 1;
                $thumb_url = !empty($video->snippet->thumbnails->high->url) ? $video->snippet->thumbnails->high->url : '';
                $video_title = !empty($video->snippet->title) ? $video->snippet->title : '';
                echo "<img src='" . esc_url($thumb_url) . "' alt='" . esc_attr($video_title) . "' width='120' height='90' /> ";
                tp_write_post($video, $options);
            }
        }
    } else {
        $status = -1;
    }

    switch ($status) {
        case -1:
            echo '<div class="notice notice-warning"><p>' . esc_html__('No Videos Found') . wp_kses_post($warning) . '</p></div>';
            break;  
        case 0:
            echo '<div class="notice notice-info"><p>' . esc_html__('Videos already imported') . wp_kses_post($warning) . '</p></div>';
            break;
        case 1:
            echo '<div class="notice notice-success"><p>' . esc_html__('Videos imported successfully') . wp_kses_post($warning) . '</p></div>';
            break;
        default:
            if (!empty($warning)) {
                echo '<div class="notice notice-warning"><p>' . wp_kses_post($warning) . '</p></div>';
            }
            break;
    }
    echo '</div></div>';
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

function tp_write_post($v, $opt) {
    $tpo = get_option('tp_options', array());
    $post_template_excerpt = isset($tpo['excerpt']) ? $tpo['excerpt'] : '';
    $post_template_content = isset($tpo['content']) ? $tpo['content'] : '';
    $post_status = !empty($tpo['status']) ? $tpo['status'] : 'publish';
    $vid = (!empty($v->id)) ? $v->id : (isset($opt['video_id']) ? $opt['video_id'] : '');
    $vid = sanitize_text_field($vid);

    $youtube_tags = getYoutubeTags($vid);

    $title = isset($v->snippet->title) ? $v->snippet->title : '';
    $thumbnail = isset($v->snippet->thumbnails->high->url) ? $v->snippet->thumbnails->high->url : '';
    $description = isset($v->snippet->description) ? $v->snippet->description : '';

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
    if (!empty($opt['cat'])) {
        $raw_cats = explode(',', trim($opt['cat'], " \n\t\r\0\x0B,"));
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

function tp_import_id() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }

    $default = array('video_id' => 'ImtuJ-kzsAc', 'cat' => '');
    $options = get_option('tp_options_id', array());
    $options = is_array($options) ? array_merge($default, $options) : $default;

    if (isset($_POST['update_tp'])) {
        check_admin_referer('tp_import_id_action', 'tp_import_id_nonce');

        $video_id = isset($_POST['video_id']) ? sanitize_text_field(trim($_POST['video_id'])) : '';
        if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $video_id, $match)) {
            $video_id = $match[1];
        }

        $options['video_id'] = $video_id;
        $options['cat'] = isset($_POST['cat']) ? sanitize_text_field($_POST['cat']) : '';

        update_option('tp_options_id', $options);
        tp_get_list($options, 'id');
    }
    ?>

    <div class="wrap">
        <h2><?php echo esc_html__('TubePress: Import By ID'); ?></h2>
        <?php echo tp_copyright(); ?>
        <form name="id" method="post">
            <?php wp_nonce_field('tp_import_id_action', 'tp_import_id_nonce'); ?>
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
                <input type="submit" name="update_tp" class="button button-primary" value="<?php echo esc_attr__('Import This Video &raquo;', 'update_tp'); ?>" style="font-weight:bold;" />
            </p>
        </form>
    </div>
    
    <?php
}

function tp_manage_options() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }

    $warning = '';
    $default = array(
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
        if (isset($options['upgraded']) && $options['upgraded'] === '0') { 
            $options['upgraded'] = '1';
            update_option('tp_options', $options);
            tp_upgrade();
        }
    }
    ?>
    <style type="text/css">
        .tp-color { padding: 5px 12px; text-align: center; }
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
        <form method="post">
        <?php wp_nonce_field('tp_manage_options_action', 'tp_manage_options_nonce'); ?>
        <table class="form-table" style="max-width: 800px;">
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

function tp_copyright($style = null) {
    return "";
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
