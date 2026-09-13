=== TubePress.NET ===
Contributors: Mario Mansour and Geoff Peters
Donate link: http://www.tubepress.net/
Tags: wordpress video plugin, youtube, video, import youtube videos, wordpress plugins, wordpress youtube, youtube wordpress
Requires at least: 2.7
Tested up to: 6.9.1
Stable tag: trunk

Import Youtube Videos directly into your wordpress blog post or pages.

== Description ==

[TubePress](http://www.tubepress.net/ "Wordpress Video Plugin Youtube"): WordPress Video Plugin for YouTube Download & Import Videos

* Complete rewrite of the core code to support ALL WP versions
* Change in the posting structure
* Added page support
* Added upgrade function for backward compatibility
* Added player customization
* And more cool features


== Installation ==

* Upload `tubepress.net` folder to the `/wp-content/plugins/` directory (or install via Plugins menu).
* Activate the plugin through the 'Plugins' menu in WordPress.
* Navigate to 'TubePress' > 'Setup' in the admin menu and enter your Google OAuth 2.0 Client ID.
  (Follow the step-by-step instructions on the Setup screen to create your Client ID in Google Cloud Console).
* In Google Cloud Console, ensure your site's domain is added under "Authorized JavaScript origins".
* Go to 'TubePress' > 'Import By ID' to start importing videos! No server Composer dependencies needed.


== Change Log ==

= 4.3.0 =
* Architecture: Implemented Zero-Backend-Token architecture. YouTube video metadata is now fetched directly in the browser via client-side OAuth 2.0, completely eliminating the need for Google tokens on the backend.
* Simplified: Removed server-side Composer vendor autoload and Google PHP Client SDK dependencies.
* Usability: Added Google OAuth Client ID setting and detailed setup guide directly within the TubePress Setup screen.
* Interactive Import: Modernized 'Import By ID' with real-time AJAX import, live preview, and post links.

= 4.2.1 =
* Security hardening: Added CSRF protection using WordPress nonces on all admin forms.
* Security hardening: Added explicit capability checks (manage_options) and replaced legacy numeric user levels.
* Security hardening: Prevented SQL injection vulnerabilities using $wpdb->prepare().
* Security hardening: Added output escaping (esc_attr, esc_html, esc_url, esc_textarea) to prevent XSS.
* Enhanced OAuth token handling with secure transients and SameSite cookie attributes.
* Lazy-loaded Google API client dependencies and added dependency check admin notice.

= 4.2.0 =
* Change to client-side auth to avoid storing secret on server
* Rewrote this Wordpress plugin so that it uses OAUTH2 request from the
browser, so no client secret will need to be stored on the web server.

= 4.0.0 =
* Simplified fork of code
* Made it work with Youtube Data API (v3)
* Removed unneeded functionality for now (now it will only work
  with importing video by id, other functionality could be added
  back later.

= 3.2.4 =
* Fixed the formatting of the description and excerpt

= 3.2.3 =
* Import by user is empty by default.
* "Add TubePress link to blogroll?" is Off by default.

= 3.2.2 =
* Removed base64 encoding of Paypal donation button. Now appears as normal HTML code.
* Blogroll link permission is added to the settings.

= 3.2.1 =
* Added option to set the post/page status (publish, pending, draft, private) for imported videos.

= 3.2.0 =
* Added support for image attribute in WP JW Player to allow image preview when using WP JW Player plugin.

= 3.1.9 =
*Support for Wordpress 3.0
*Fixed a bug in the duplicate video detection function.

= 3.1.8 =
*Importing is blocked when TubePress is not correctly setup.
*Fixed links to the plugin's internal pages in warning messages.
*Template Content and Template Excerpt

= 3.1.7 =
*Improved remove duplication function
*Added fault handling for wrong setup (Template Content, Template Excerpt and Usage of Custom Fields)

= 3.1.6 =
*Fixed Fatal error: Call to a member function fetch() on a non-object in .../wp-content/plugins/tubepressnet/tubepress.php on line 515

= 3.1.5 =
*Fixed json decoding to parse large objects, accepting max-results larger than 10

= 3.0 =
* Complete rewrite of the core code to support WP 2.6 and even later wordpress versions
* Change in the posting structure
* Added page support
* Added player customization
* Added upgrade function for backward compatibility
* And more cool features

= 2.6 =
* Added option to show/hide related videos inside the player
* Fixed some bugs
* Import single video by id

= 2.5 =
Added customized adsense

= 2.4 =
Import the featured videos in youtube

= 2.35 =
Import your favorite videos from youtube

= 2.3 =
Multi language support

= 2.2 =
Import your youtube videos or any user videos

= 2.1 =
Added tags to category import

= 2.0 =
* Added new import features from youtube
* Added customized view options
* Added customized video display properties
* Post writing changed, implementing TP code
* Fixed bug when manually posting into wordpress or changing an imported post

= 1.4 =
Added new video properties

= 1.3 =
Added Thumbnail view option

= 1.2 =
Added image format for the average rating

= 1.1 =
Added default settings

= 1.0 =
First release of TubePress with basic video import

== Info ==

Check out the website for more examples and discussions
[TubePress](http://www.tubepress.net/ "Wordpress Video Plugin")