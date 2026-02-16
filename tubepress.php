<?php
/*
Plugin Name: TubePress.Net Simplified
Plugin URI: http://www.tubepress.net/
Description:  The Youtube Plugin for Wordpress, simplified to work with Youtube Data API (v3) using Client-Side Auth.
Author: Mario Mansour and Geoff Peters
Version: 4.2.0
Author URI: http://www.mariomansour.org/
*/

/*
To enable responsive video resizing, can add the following CSS into your theme:

.video-container {
  position: relative;
  overflow: hidden;
  width: 100%;
  padding-top: 56.25%; /* 16:9 Aspect Ratio (divide 9 by 16 = 0.5625) */
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

// set up include path for Google API PHP Client API.
// for more information, see: https://developers.google.com/api-client-library/php/
require_once '/FIXME-make-this-the-path-to/vendor/autoload.php';

/* * NOTE: Session start is no longer strictly necessary for Auth, 
 * but kept if other plugins/parts rely on it.
 */
if (!session_id()) {
    session_start();
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
$OAUTH2_CLIENT_ID = 'FIXME';

$client = new Google_Client();
$client->setClientId($OAUTH2_CLIENT_ID);
// Scopes are now handled in the JS, but good to keep here for reference or validation if needed
$client->setScopes('https://www.googleapis.com/auth/youtube.readonly');

/*
 * AUTH LOGIC REPLACEMENT
 * Instead of checking $_SESSION or exchanging a code, we look for a cookie
 * set by the browser.
 */
if (isset($_COOKIE['tp_access_token'])) {
    // We manually set the token object. The library expects an array or JSON string.
    $accessToken = array(
        'access_token' => $_COOKIE['tp_access_token'],
        'expires_in' => 3600, // Standard expiry, though we rely on the cookie's life
        'created' => time(),
        'scope' => 'https://www.googleapis.com/auth/youtube.readonly'
    );
    $client->setAccessToken($accessToken);
}

// Define an object that will be used to make all API requests.
$youtubeService = new Google_Service_YouTube($client);

// -----------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------

function getYoutubeTags($videoId) {
   return getYoutubeVideoInfo($videoId)->items[0]->snippet->tags;
}

function getYoutubeVideoInfo($videoId) {
    global $client, $youtubeService, $OAUTH2_CLIENT_ID;

    $listResponse = null;

    // Check to ensure that the access token was successfully acquired from the cookie
    if ($client->getAccessToken() && !$client->isAccessTokenExpired()) {
        try {
            // Call the API's videos.list method to retrieve the video resource.
            $listResponse = $youtubeService->videos->listVideos("snippet",
                array('id' => $videoId));
        } catch (Google_Service_Exception $e) {
            // Error handling
        } catch (Google_Exception $e) {
            // Error handling
        }
    } else {
        // If the token is missing or expired, we present the JS Auth Button
        $htmlBody = <<<END
<div class="wrap">
    <h3>Authorization Required</h3>
    <p>You need to authorize access via Google to import videos.</p>
    
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    
    <script>
      var client;
      
      function initClient() {
        client = google.accounts.oauth2.initTokenClient({
          client_id: '$OAUTH2_CLIENT_ID',
          scope: 'https://www.googleapis.com/auth/youtube.readonly',
          callback: (response) => {
            if (response.access_token) {
              // Store the token in a cookie for 1 hour (3600 seconds)
              document.cookie = "tp_access_token=" + response.access_token + "; max-age=" + response.expires_in + "; path=/";
              
              // Reload the page so PHP can read the cookie
              window.location.reload();
            }
          },
        });
      }

      function getToken() {
        // Trigger the popup
        client.requestAccessToken();
      }
      
      // Initialize the client when the script loads
      window.onload = function() {
        initClient();
      };
    </script>

    <button onclick="getToken()" class="button button-primary">Authorize with YouTube</button>
</div>
END;
        print $htmlBody;
        exit();
    }
    return $listResponse;
}

define('DEFAULT_EXCERPT', '<img src="%tp_thumbnail%" /><br />%tp_title% was uploaded by: %tp_author%<br />Duration: %tp_duration%<br />Rating: %tp_rating_img%');
define('DEFAULT_CONTENT', '%tp_player%<p>%tp_description%</p>');

if(!function_exists('json_decode') ){
    function json_decode($content, $assoc=false){
        require_once('JSON.php');
        if ( $assoc ){
            $json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
        } else {
            $json = new Services_JSON;
        }
        return $json->decode($content);
    }
}

function tp_curl_fetch_object($url) {
    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $json_response = curl_exec($curl);

    curl_close($curl);

    $responseObj = json_decode($json_response);
    return $responseObj;
}

function tp_get_list($options,$action='tag') {
    $warning = '';
    $status = 0;
    $gen_options = get_option('tp_options');
    if(!$gen_options['customfield'] && (empty($gen_options['content']) || empty($gen_options['excerpt']))) {
        _e('<div class="updated fade"><p><strong>You have to <a href="admin.php?page=tubepressnet/tubepress.php">customize the Content Template and/or Content Excerpt</a>, otherwise your posts/pages will not show the imported videos</strong></p></div>');
        return false;
    } elseif($gen_options['customfield'] && empty($gen_options['content']) && empty($gen_options['excerpt'])) {
        $warning .= __('<p><strong>Do not forget to <a href="theme-editor.php">edit your template</a> to make use of these custom fields instead of the default the_content() and the_excerpt() calls</strong></p>');
    }   
    if(!is_array($options)) return false;
    switch($action) {
        case 'id':
            $xml = getYoutubeVideoInfo($options['video_id']);
        break;
    }
    
    // Safety check: ensure we actually got an object back before trying to read it
    if (!is_object($xml)) {
         return; // Auth required screen already printed in getYoutubeVideoInfo
    }

    echo '<div class="wrap">';
    _e('<h2>Imported Video List</h2>');

    echo '<div align="center">';
    if(isset($xml->items)) {
        foreach ($xml->items as $video) {
            if(!tp_duplicate($video->id)) {
                $status = 1;
                echo "<img src='{$video->snippet->thumbnails->high->url}' alt='{$video->snippet->title}' width='120' height='90' />  ";
                tp_write_post($video,$options);
            }
        }
    } else { $status = -1; }
    switch($status) {
        case -1:
            echo '<div class="updated fade"><p>'.__('No Videos Found').$warning.'</p></div>';
        break;  
        case 0:
            echo '<div class="updated fade"><p>'.__('Videos already imported').$warning.'</p></div>';
        break;
        case 1:
            echo '<div class="updated fade"><p>'.__('Videos imported successfully').$warning.'</p></div>';
        break;
        default:
            if(!empty($warning)) {
                echo '<div class="updated fade"><p>'.$warning.'</p></div>';
            }
        break;
    }
    echo '</div></div>';
}
function tp_duplicate($id) {
    global $wpdb;
    $options = get_option('tp_options');
    $post = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_content like '%".$id."%' OR post_excerpt like '%".$id."%' LIMIT 1",ARRAY_A);
    $field = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_value='".$id."' AND post_id NOT IN (SELECT post_id FROM $wpdb->postmeta where meta_key='_wp_trash_meta_status') LIMIT 1",ARRAY_A);
    return (bool) ((!empty($post) || !empty($field)) && $options['duplicate']);
}

function tp_player($id) {
    $opt = get_option('tp_options');
    $pl = '<div class="video-container-outer"><div class="video-container"><iframe class="video-iframe" src="https://www.youtube.com/embed/' .$id. '" frameborder="0" allowfullscreen></iframe></div></div>';
    return $pl;
}

function tp_rating_c($r) {
    $img = '';
    $t = 0;
    for($i=0;$i<floor($r);$i++) { $img .= '<img src="'.plugins_url('/images/yt_rating_on.gif',__FILE__).'" />'; }
    if($r > floor($r)) { $t = 1; $img .= '<img src="'.plugins_url('/images/yt_rating_half.gif',__FILE__).'" />'; }
    for($i=0;$i<5-floor($r)-$t;$i++) { $img .= '<img src="'.plugins_url('/images/yt_rating_off.gif',__FILE__).'" />'; }
    return $img;
}

function tp_write_post($v,$opt) {
    $tpo = get_option('tp_options');
    $post_template_excerpt = $tpo['excerpt'];
    $post_template_content = $tpo['content'];
    $post_status = !empty($tpo['status']) ? $tpo['status'] : 'publish';
    $vid = (!empty($v->id)) ? $v->id : $opt['video_id'];
    
    $youtube_tags = getYoutubeTags($vid);

    $tp_tags = array("%tp_player%","%tp_id%","%tp_title%","%tp_thumbnail%","%tp_description%","%tp_url%");
    $tag_values = array(tp_player($vid),$vid,$v->snippet->title,$v->snippet->thumbnails->high->url,$v->snippet->description,"https://www.youtube.com/watch?v=".$vid);
    
    $post_template_excerpt = str_replace($tp_tags,$tag_values,$post_template_excerpt);
    $post_template_content = str_replace($tp_tags,$tag_values,$post_template_content);
    $post_category = explode(',', trim($opt['cat'], " \n\t\r\0\x0B,"));
    $tp_post = array('post_title' => $v->snippet->title,
            'post_content' => nl2br($post_template_content),
            'post_status' => $post_status,
            'post_type' => $tpo['type'],
            'post_name' => sanitize_title($v->snippet->title),
            'post_category' => $post_category,
            'post_excerpt' => nl2br($post_template_excerpt));
    $post_id = wp_insert_post($tp_post);
    wp_set_post_tags($post_id, $youtube_tags);
    if($tpo['customfield']) {
        foreach($tp_tags as $k=>$meta_key) {
            add_post_meta($post_id, str_replace("%","",$meta_key), $tag_values[$k]);
        }
    }
    wp_create_categories($post_category,$post_id);
}

function tp_category_form($options) {
    $tpo = get_option('tp_options');
    $tf = '';
    if($tpo['type'] == 'post') {
        $tf .= '<tr>';
        $tf .= '    <td>Category</td>';
        $tf .= '    <td><input name="cat" type="text" id="cat" value="'.$options['cat'].'" /></td>';
        $tf .= '    <td>Add the imported videos to this category</td>';
        $tf .= '</tr>';
    }
    return $tf;
}
function tp_order_form($options) {
    $orderoption = array("relevance"=>"Relevance","published"=>"Published","viewCount"=>"View Count","rating"=>"Rating");
    $tf = '<tr><td>Order By</td><td><select name="orderby">';
    foreach($orderoption as $k=>$v) {
        $selected = ($options['orderby'] == $k) ? ' selected="selected"' : '';
        $tf .= '<option value="'.$k.'"'.$selected.'>'.$v.'</option>';
    }
    $tf .= '</select></td><td></td></tr>';
    return $tf;
}
function tp_comment_form($options) {
    return "";
}

function tp_import_id() {
    $default = array('video_id'=>'ImtuJ-kzsAc');
    if (isset($_POST['update_tp'])) {
        $options['video_id'] = $_POST['video_id'];
        $options['cat'] = $_POST['cat'];
        //$options['comments'] = $_POST['comments'];
        update_option('tp_options_id', $options);   
        tp_get_list($_POST,'id');
    } else {
        $opt = get_option('tp_options_id');
        $options = is_array($opt) ? array_merge($default,$opt) : $default;
    }
    ?>

    <div class="wrap">
        <h2><?php _e('TubePress: Import By ID'); ?></h2>
        <?php echo tp_copyright(); ?>
        <form name="id" method="post">
        <table width="669">
            <tr>
                <td>Video ID:</td>
                <td><input name="video_id" type="text" id="video_id" value="<?php echo $options['video_id'] ?>" /></td>
                <td>https://www.youtube.com/watch?v=<strong>ImtuJ-kzsAc</strong></td>
            </tr>
            <?php _e(tp_category_form($options)); ?>
            <?php _e(tp_comment_form($options)); ?>
        </table>        
        <div class="submit"><input type="submit" name="update_tp" value="<?php _e('Import This Video &raquo;', 'update_tp') ?>"  style="font-weight:bold;" /></div></p>
        </form>
    </div>
    
<?php
}
function tp_manage_options() {
    $warning = '';
    $default = array('width'=>'425','height'=>'344','autoplay'=>'0','rel'=>'1','color'=>'1','border'=>'0', 'duplicate'=>'1', 'type'=>'post', 'status'=>'publish', 'customfield'=>'0',
            'excerpt'=>'',
            'content'=>'',
            'upgraded'=>'0',
            'show_link'=>'0');

    $data = "";
    $tp_l = empty($data) ? "TubePress" : $data;
    $data = array('link_name'=>$tp_l,'link_url'=>'http://www.tubepress.net/');
    if (isset($_POST['update_tp'])) {
        $options['width'] = $_POST['width'];
        $options['height'] = $_POST['height'];
        $options['autoplay'] = (bool) $_POST['autoplay'];
        $options['status'] = $_POST['status'];
        $options['type'] = $_POST['type'];
        $options['duplicate'] = (bool) $_POST['duplicate'];
        $options['rel'] = (bool) $_POST['rel'];
        $options['color'] = $_POST['color'];
        if ($_POST['color'] == 1) { $options['color1']  = "0xd6d6d6"; $options['color2']  = "0xf0f0f0"; }
        else if ($_POST['color'] == 2) { $options['color1']  = "0x3a3a3a"; $options['color2']  = "0x999999"; }
        else if ($_POST['color'] == 3) { $options['color1']  = "0x2b405b"; $options['color2']  = "0x6b8ab6"; }
        else if ($_POST['color'] == 4) { $options['color1']  = "0x006699"; $options['color2']  = "0x54abd6"; }
        else if ($_POST['color'] == 5) { $options['color1']  = "0x234900"; $options['color2']  = "0x4e9e00"; }
        else if ($_POST['color'] == 6) { $options['color1']  = "0xe1600f"; $options['color2']  = "0xfebd01"; }
        else if ($_POST['color'] == 7) { $options['color1']  = "0xcc2550"; $options['color2']  = "0xe87a9f"; }
        else if ($_POST['color'] == 8) { $options['color1']  = "0x402061"; $options['color2']  = "0x9461ca"; }
        else if ($_POST['color'] == 9) { $options['color1']  = "0x5d1719"; $options['color2']  = "0xcd311b"; }
        $options['border'] = (bool) $_POST['border'];
        $options['customfield'] = (bool) $_POST['customfield'];
        $options['content'] = $_POST['content'];
        $options['excerpt'] = $_POST['excerpt'];
        $options['show_link'] = (bool) $_POST['show_link'];
        if($options['show_link']) {
            tp_insert_link($data);
        } else {
            tp_remove_link();
        }
        update_option('tp_options', $options);
        if(!$options['customfield'] && (empty($options['content']) || empty($options['excerpt']))) {
            $warning .= __('<p><strong>You have to customize the Content Template and/or Content Excerpt, otherwise your posts/pages will not show the imported videos</strong></p>');
        } elseif($options['customfield'] && empty($options['content']) && empty($options['excerpt'])) {
            $warning .= __('<p><strong>Do not forget to <a href="theme-editor.php">edit your template</a> to make use of these custom fields instead of the default the_content() and the_excerpt() calls</strong></p>');
        }
        ?> <div class="updated fade"><p><?php _e('Options Saved!'); ?></p><?php if(!empty($warning)) echo $warning; ?></div> <?php
    } else {
        $opt = get_option('tp_options');
        $options = is_array($opt) ? array_merge($default,$opt) : $default;
        if ($options['upgraded'] == '0') { 
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
            var siteURL = document.getElementById('siteURL').value;
            var preview;
            var color ="";
            var getColor=document.getElementsByName('color');
                    
            for (i=0; i<9; i++) {
                if(getColor[i].checked) { color = i+1; }
            }
            preview = '<img src="<?= plugins_url('/images/',__FILE__); ?>';
            
            if(border.checked == true){
                preview += 'border';
            }
            else {
                preview += 'color';
            }
            preview += color+'.gif" alt="" />';
            
            previewImage.innerHTML = preview;
            previewImage.style.display = 'block';
        }
        function tpToggle() {
            document.getElementById('tp-preview').style.display = 'none';
        }
    </script>
    <div class="wrap">
        <h2><?php _e('TubePress Setup'); ?></h2>
        <form method="post">
        <table width="100%">
            <input name="siteURL" id="siteURL" type="hidden" value="<?php echo get_option('siteurl'); ?>" />
            <tr>
                <td><?php _e('Video Player Width'); ?></td>
                <td><input name="width" type="text" id="width" value="<?php echo $options['width']; ?>" /></td>
                <?php $type = ($options['border']) ? 'border' : 'color'; ?>
                <td rowspan="6"><div id="tp-preview" <?php if($options['color']==0) echo 'style="display:none;"';?>><img src="<?php echo plugins_url('/images/'.$type.$options['color'].'.gif',__FILE__); ?>" alt="" /></div></td>
            </tr>
            <tr>
                <td><?php _e('Video Player Height'); ?></td>
                <td><input name="height" type="text" id="height" value="<?php echo $options['height']; ?>" /></td>
            </tr>
            <tr>
                <td><?php _e('Autoplay Videos ?'); ?></td>
                <td><input name="autoplay" type="checkbox" id="autoplay" <?php if($options['autoplay']) echo 'checked="checked"'; ?> /></td>
            </tr>
            <tr>
                <td><?php _e('Hide Related Videos ?'); ?></td>
                <td><input name="rel" type="checkbox" id="rel" <?php if($options['rel']) echo 'checked="checked"'; ?> /></td>
            </tr>
            <tr>
                <td><?php _e('Show Border?'); ?></td>
                <td><input onclick="tpPreview();" name="border" type="checkbox" id="border" <?php if($options['border']) echo 'checked="checked"'; ?> /></td>
            </tr>
            <tr>
                <td><?php _e('Add TubePress link to blogroll?'); ?></td>
                <td><input name="show_link" type="checkbox" id="show_link" <?php if($options['show_link']) echo 'checked="checked"'; ?> /></td>
            </tr>
            <tr>
                <td><?php _e('Customize player color'); ?></td>
                <td>
                <table>
                    <tr>
                        <td class="tp-color" style="background: #ababab;"><input onclick="tpPreview();" type="radio" name="color" value="1" <?php if($options['color']==1) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #6a6a6a;"><input onclick="tpPreview();" type="radio" name="color" value="2" <?php if($options['color']==2) echo 'checked="checked"'; ?>></td>               
                        <td class="tp-color" style="background: #4b6589;"><input onclick="tpPreview();" type="radio" name="color" value="3" <?php if($options['color']==3) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #2a89b8;"><input onclick="tpPreview();" type="radio" name="color" value="4" <?php if($options['color']==4) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #397400;"><input onclick="tpPreview();" type="radio" name="color" value="5" <?php if($options['color']==5) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #f08f08;"><input onclick="tpPreview();" type="radio" name="color" value="6" <?php if($options['color']==6) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #da5078;"><input onclick="tpPreview();" type="radio" name="color" value="7" <?php if($options['color']==7) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #6a4196;"><input onclick="tpPreview();" type="radio" name="color" value="8" <?php if($options['color']==8) echo 'checked="checked"'; ?>></td>
                        <td class="tp-color" style="background: #95241a;"><input onclick="tpPreview();" type="radio" name="color" value="9" <?php if($options['color']==9) echo 'checked="checked"'; ?>></td>
                    </tr>
                </table>
                </td>
            </tr>
            <tr>
                <td><?php _e('Use WP JW Player'); ?></td>
                <td><input onclick="tpToggle();" name="color" id="color" type="radio" value="0" <?php if($options['color']==0 && class_exists('wpjp_JWPlayerAdmin')) echo 'checked="checked"'; ?>>
                <?php if(!class_exists('wpjp_JWPlayerAdmin')) _e('WP JW Player Plugin is required. <a href="http://downloads.wordpress.org/plugin/wp-jw-player.zip">Download it here</a>'); ?></td>
            </tr>
            <tr>
                <td colspan="4">&nbsp;</td>
            </tr>
            <tr>
                <td colspan="4"><?php _e('Customize the look of your imported videos with the tubepress template. You can use HTML code + TubePress Template Tags (Check below)'); ?></td>
            </tr>
            <tr><td>&nbsp;</td></tr>
            <tr>
                <td><?php _e('Remove Duplication'); ?></td>
                <td colspan="2"><input name="duplicate" type="checkbox" id="duplicate" value="$options['duplicate']" <?php if($options['duplicate']) echo 'checked="checked"'; ?> /></td>
            </tr>
            <tr>
                <td><?php _e('Put each video in'); ?></td>
                <td colspan="2">
                    <select name="type" id="type">
                        <option value="post" <?php if($options['type']=='post') echo 'selected="selected"'; ?>>post</option>
                        <option value="page" <?php if($options['type']=='page') echo 'selected="selected"'; ?>>page</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td><?php _e('Post Status'); ?></td>
                <td colspan="2">
                    <select name="status" id="status">
                        <option value="publish" <?php if($options['status']=='publish') echo 'selected="selected"'; ?>>Published</option>
                        <option value="pending" <?php if($options['status']=='pending') echo 'selected="selected"'; ?>>Pending</option>
                        <option value="draft" <?php if($options['status']=='draft') echo 'selected="selected"'; ?>>Draft</option>
                        <option value="private" <?php if($options['status']=='private') echo 'selected="selected"'; ?>>Private</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td><?php _e('Add Custom Fields'); ?></td>
                <td colspan="2"><input name="customfield" type="checkbox" id="customfield" value="$options['customfield']" <?php if($options['customfield']) echo 'checked="checked"'; ?> />
                Custom Fields: tp_player, tp_thumbnail, tp_title, tp_description, tp_duration, tp_author, tp_tags, tp_rating_num, tp_rating_img, tp_viewcount, tp_id, tp_url
                <br/><?php _e('<strong>Note:</strong> You need to modify your template to make use of these custom fields'); ?></td>
            </tr>
            <tr>
                <td><?php _e('Content Template'); ?></td>
                <td><textarea name="content" cols="60" rows="7"><?php echo stripslashes($options['content']); ?></textarea></td>
                <td><?php echo __('<h3>Use this code for example:</h3>').htmlentities(__(DEFAULT_CONTENT)); ?></td>
            </tr>
            <tr>
                <td><?php _e('Excerpt Template'); ?></td>
                <td><textarea name="excerpt" cols="60" rows="7"><?php echo stripslashes($options['excerpt']); ?></textarea></td>
                <td><?php echo __('<h3>Use this code for example:</h3>').htmlentities(__(DEFAULT_EXCERPT)); ?></td>
            </tr>
        </table>
        <h2><?php _e('TubePress Template Tags'); ?></h2>
        <?php _e('Use these tags to make your own content and excerpt templates. Check wordpress templates to know the difference'); ?>
        <ul>
            <li><strong>%tp_player%</strong>: Displays the video player</li>
            <li><strong>%tp_thumbnail%</strong>: Displays the thumbnail image</li>
            <li><strong>%tp_title%</strong>: Displays the title of the video</li>
            <li><strong>%tp_description%</strong>: Displays the description of the video</li>
            <li><strong>%tp_id%</strong>: Displays the video id</li>
            <li><strong>%tp_url%</strong>: Displays the youtube video url</li>
        </ul>
        <div class="submit"><input type="submit" name="update_tp" value="<?php _e('Save Options &raquo;', 'update_tp') ?>"  style="font-weight:bold;" /></div></p>
        </form>
        <?php echo tp_copyright('noformat'); ?>
    </div>
<?php
}
function tp_copyright($style=null) {
    return "";
}
function tp_insert_link($data) {
    global $wpdb;
    $link_id = $wpdb->get_var("SELECT link_id FROM $wpdb->links WHERE link_url='".$data['link_url']."'");
    if($link_id == null) {
        $link_id = wp_insert_link($data);
    }
    update_option('tp_link_id',$link_id);
}
function tp_remove_link() {
    global $wpdb;
    if($link_id = get_option('tp_link_id')) {
        wp_delete_link($link_id);
    }
}
function tp_patch() {
    global $wpdb;
    $posts = $wpdb->get_results("SELECT ID,post_content,post_excerpt FROM $wpdb->posts WHERE post_excerpt like '<table><tr><td><img src=\"https://i.ytimg.com/vi/%'",ARRAY_A);
    if(!is_array($posts)) return false;
    foreach($posts as $post) {
        $post_id = $post['ID'];
        $content = $post['post_content'];
        $excerpt = $post['post_excerpt'];
        preg_match('@/vi/([^/]+)/@si',$excerpt,$vid);
        if ($options['is_autoplay'] || $options['autoplay']) {
                $autoplay_code = '<param name="autoplay" value="1"></param>';
                $autoplay_kode = '&autoplay=1';
        } else {
            $autoplay_code= '';
            $autoplay_kode = '';
        }
        if ($options['is_rel'] || $options['rel']) {
            $rel_code = '<param name="rel" value="0"></param>';
            $rel_kode = '&rel=0';
            $href_code = '<param name="enablehref" value="false"></param><param name="allownetworking" value="internal"></param>';
            $href_kode = 'enablehref="false" allownetworking="internal"';
        } else {
            $rel_code = '';
            $rel_kode = '';
            $href_code = '';
            $href_kode = '';
        }
        $display = '<object width="'.$options["width"].'" height="'.$options["height"].'"><param name="movie" value="https://www.youtube.com/v/' .$vid[1]. '"></param>'.$autoplay_code.$rel_code.'<param name="wmode" value="transparent"></param>'.$href_code.'<embed src="https://www.youtube.com/v/' .$vid[1].$autoplay_kode.$rel_kode.'" type="application/x-shockwave-flash" wmode="transparent" '.$href_kode.' width="425" height="350"></embed></object>';
        $content = $display.$content;
        $postarr = array('ID'=>$post_id,'post_content'=>$content,'post_excerpt'=>$excerpt);
        wp_update_post($postarr);
    }
}

function tp_upgrade() {
    global $wpdb;
    $options = get_option('tp_options');
    $posts = $wpdb->get_results("SELECT ID,post_content FROM $wpdb->posts WHERE post_content like '%[/ID]%'",ARRAY_A);
    if(!is_array($posts)) return false;
    foreach($posts as $post) {
        
        $post_id = $post['ID'];
        $content = $post['post_content'];

        if (preg_match_all("|\[[A-Z]+\](.*)\[\/[A-Z]+\]|sU",$content,$match)) {

            //Convert the average rating into image

            $post_rating = "";
            if ($match[1][4] == 0) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif (($match[1][4] > 0)&&($match[1][4] < 1)) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_half_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif (($match[1][4] > 1)&&($match[1][4] < 2)) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif (($match[1][4] > 2)&&($match[1][4] < 3)) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif (($match[1][4] > 3)&&($match[1][4] < 4)) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif (($match[1][4] > 4)&&($match[1][4] < 5)) {
                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_off_11x11.gif' />"; }
            elseif ($match[1][4] == 5) {

                $post_rating .= "<img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' /><img src='http://www.youtube.com/img/pic_star_on_11x11.gif' />"; }

            //end convert average rating

            $excerpt = '<table><tr>';
            $ytimg = (empty($match[1][3])) ? "https://i.ytimg.com/vi/".$match[1][1]."/default.jpg" : $match[1][3];
            $excerpt .= '<td><img src="'.$ytimg.'" border="0">';
            $excerpt .= '</td><td>';
            $excerpt .= '</td></tr></table>';
            $excerpt .= "<p>".$match[1][0]."</p>";

            if ($options['is_autoplay'] || $options['autoplay']) {
                $autoplay_code = '<param name="autoplay" value="1"></param>';
                $autoplay_kode = '&autoplay=1';
            } else {
                $autoplay_code= '';
                $autoplay_kode = '';
            }
            if ($options['is_rel'] || $options['rel']) {
                $rel_code = '<param name="rel" value="0"></param>';
                $rel_kode = '&rel=0';
                $href_code = '<param name="enablehref" value="false"></param><param name="allownetworking" value="internal"></param>';
                $href_kode = 'enablehref="false" allownetworking="internal"';
            } else {
                $rel_code = '';
                $rel_kode = '';
                $href_code = '';
                $href_kode = '';
            }
            $display = '<object width="'.$options["width"].'" height="'.$options["height"].'"><param name="movie" value="https://www.youtube.com/v/' .$match[1][1]. '"></param>'.$autoplay_code.$rel_code.'<param name="wmode" value="transparent"></param>'.$href_code.'<embed src="https://www.youtube.com/v/' .$match[1][1].$autoplay_kode.$rel_kode.'" type="application/x-shockwave-flash" wmode="transparent" '.$href_kode.' width="425" height="350"></embed></object>';
            $display .= '<p>'.$match[1][0].'</p>';
            
            if($options['is_author'] && !empty($match[1][2])) {
                $display .= "<p>Author: ".$match[1][2]."</p>";
            }
            if($options['is_rating'] && !empty($match[1][4])) {
                $display .= "<p>Rating: ".$post_rating."</p>";
            }
            if($options['is_viewed'] && !empty($match[1][5])) {
                $display .= "<p>Viewed: ".$match[1][5]." times</p>";
            }
            if($options['is_tags'] && !empty($match[1][6])) {
                $display .= "<p>Tags: ".$match[1][6]."</p>";
            }
            if($options['is_upload'] && !empty($match[1][7])) {
                $display .= "<p>Uploaded ".date('F j, Y',$match[1][7])."</p>";
            }
            if($options['is_length'] && !empty($match[1][8])) {
                $display .= "<p>Duration: 0".floor($match[1][8]/60).":".($match[1][8] % 60)."</p>";
            }
            
            $postarr = array('ID'=>$post_id,'post_content'=>$display,'post_excerpt'=>$excerpt);
            wp_update_post($postarr);
        }
    }
}

function tp_add_options_page() {
    add_menu_page('TubePress', 'TubePress', 8, __FILE__, 'tp_manage_options');
    add_submenu_page(__FILE__, 'Setup', 'Setup', 8, __FILE__, 'tp_manage_options');
    add_submenu_page(__FILE__, 'Import By ID', 'Import By ID', 8, 'tubepress-id.php', 'tp_import_id');
}

add_action('admin_menu', 'tp_add_options_page');

?>
