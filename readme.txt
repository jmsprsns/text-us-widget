=== Text Us Widget ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: text us, sms, imessage, click to text, turnstile
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A "Text Us" button that opens Messages on iPhone, iPad and Mac (and Android, if you like), with tap tracking and optional Cloudflare Turnstile to keep your number away from bots.

== Description ==

Text Us Widget adds a small iOS-style "Text Us" button to the corner of your site. Tapping it opens a panel, and the Send button opens the visitor's Messages app with your number filled in. People who would never fill in a contact form will send a text.

It only shows where texting works. iPhone, iPad and Mac all have Messages built in, so those are on by default. Android phones open their own texting app and get the Android Messages icon instead of iMessage's. Windows and other computers stay hidden unless you switch them on, because most can't open text links.

**The widget**

* A full-width bar on phones (or a compact button in the corner) and a corner launcher with a popover panel on tablets and computers
* Bottom right or bottom left, with your own distance from the edges
* Dark, light, or automatic to match the visitor's dark mode, and your own button color
* Your title, subtitle, button label, panel heading, panel text and Send button wording
* An optional pre-filled message, so you know a text came from your website
* The iMessage icon on Apple devices, the Messages icon on Android, or your own site icon
* Slides in after the first scroll or a delay you choose, and is already in place on later page views
* Visitors can close it with its small X, and it stays hidden for as many days as you choose
* Hide it on pages like /checkout/ or everything under /members/*
* Link any button or menu item to #text-us to open it

**Spam protection**

Bots scrape phone numbers from web pages and sell them to spam lists. With Cloudflare Turnstile switched on, your number is never in the page's code: the panel runs a quick (usually invisible) human check, and only then does your site send the number back. Turnstile is free and works without moving your site to Cloudflare. The Spam Protection tab walks you through getting the keys and can test them on the spot.

**Tap tracking**

* Counts how often the widget is opened, how many visitors pass the check and how many tap Send
* 7, 30 or 90-day charts with a change against the period before
* Broken down by device (iPhone, Mac, Android and so on) and by page
* Optional Google Analytics 4 events (text_us_open and text_us_text)
* Taps by logged-in editors aren't counted, and bots are ignored

**Fast by design**

* "Load on first interaction" (on by default): nothing is added to the page until the visitor scrolls, moves the mouse, taps or presses a key. PageSpeed Insights and Lighthouse never interact, so your score doesn't change. After that, a small cookie makes it load straight away on later page views.
* About 6 KB gzipped, inline: no extra files, no jQuery, and Turnstile only loads when someone opens the panel
* Works with page caching: the device check happens in the visitor's browser
* Tells WP Rocket, LiteSpeed, Perfmatters, Autoptimize, Cloudflare Rocket Loader and "delay JavaScript" plugins to leave it alone, and clears your page cache when you save

**Privacy**

No IP address, name or number is stored with a tap: just the date, the type of device and the page, plus a code for the connection that changes every day (for rate limits). The two cookies (tuw_seen and tuw_hide) only remember whether the widget has loaded before and whether it was closed. Suggested text is added to Settings > Privacy.

Find it under Content Powered > Text Us Widget in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners; iMessage is a trademark of Apple Inc. and Android is a trademark of Google LLC.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `text-us-widget` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Text Us Widget, enter your number on the Widget tab and save.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Why doesn't it show on my Windows computer? =

Text links (sms:) open Messages on iPhone, iPad and Mac, and the texting app on Android phones. Most Windows computers can't open them unless Phone Link is set up, so Windows is off by default. Switch it on under Display if your visitors use Phone Link, and consider ticking "Show the number" so they can copy it.

= Does it hurt my PageSpeed score? =

Not with "Load on first interaction" on (the default). Speed tests never scroll or move the mouse, so the widget is never added while they measure.

= Do I need a Cloudflare account for Turnstile? =

A free one, to get the keys. Your site doesn't need to use Cloudflare for anything else.

= What number should I use? =

One that can receive texts: a mobile, or a business line from a provider that supports texting. Include + and the country code.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/text-us-widget/releases for the full release history.
