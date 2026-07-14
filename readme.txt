=== IndexNow Auto Submit ===
Contributors: mavo
Tags: indexnow, bing, seo, indexing, yandex
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically notify IndexNow search engines (Bing, Yandex, Seznam, Naver) when you publish or update content. Zero setup, visible log, safe by default.

== Description ==

IndexNow Auto Submit pings IndexNow-enabled search engines every time you publish or update a post or page, so they learn about your changes in seconds instead of waiting to re-crawl.

It was built to avoid the recurring problems reported against other IndexNow plugins:

* **Zero-click setup.** Your key and verification file are generated automatically on activation. There is no wizard button that can get stuck.
* **You can see whether it worked.** Every submission attempt is logged with its real HTTP status and response body on a dedicated admin screen. "0 submissions?" is never a mystery.
* **Reliable delivery.** Submissions run asynchronously so publishing stays fast, and failed attempts retry up to 3 times with exponential backoff — every attempt is logged.
* **No unfair URL rejections.** URLs are validated with PHP's own URL validator, with no TLD allow-list, so `.builders`, `.website`, IDN domains and non-ASCII slugs all work.
* **Safe by default.** It never submits content that is set to noindex (Yoast / Rank Math), private, password-protected, non-public, or when "Discourage search engines" is enabled site-wide.

The verification file is served virtually (no file is written to disk), so it works regardless of filesystem permissions and resolves correctly on every multisite subsite.

== How it works ==

1. On activation the plugin generates a random key and starts serving it at `https://your-site/<key>.txt`.
2. When you publish or update an eligible post, its URL is submitted to the shared IndexNow endpoint.
3. Go to **Settings > IndexNow** to see your key, the verification URL, and the log of recent submissions. You can resubmit any URL from there, or submit an arbitrary URL manually.

WP-CLI:

    wp indexnow key
    wp indexnow submit https://example.com/hello-world/

== Frequently Asked Questions ==

= Can it tell me which of my posts are already indexed? =

No. IndexNow is a one-way notification protocol — search engines only respond with whether they accepted the submission, never with indexing status. Checking real indexing status would require a separate integration with Bing Webmaster Tools or Google Search Console.

= Which post types are submitted? =

`post` and `page` by default. Extend the list with the `indexnow_eligible_post_types` filter.

== Changelog ==

= 1.0.0 =
* Initial release.
