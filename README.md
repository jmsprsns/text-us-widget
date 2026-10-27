# Text Us Widget

A "Text Us" button for WordPress that opens Messages on iPhone, iPad and Mac (and Android, if you like), with tap tracking and optional Cloudflare Turnstile to keep your number away from bots.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

Settings live under **Content Powered > Text Us Widget**.

## What it does

- An iOS-style launcher: a full-width bar (or a compact button) on phones, and a corner launcher with a popover panel on tablets and computers. Bottom right or bottom left.
- Shows only on the devices you tick. Mac, iPhone and iPad (which all have Messages built in) are on by default. Android phones get the Android Messages icon instead of iMessage's. Windows and other computers are off by default, because most can't open `sms:` links.
- Your wording for every line, an optional pre-filled message, dark, light or automatic colors, your own button color and distance from the edges.
- **Load on first interaction** (on by default): until the visitor scrolls, moves the mouse, taps or presses a key, the page holds only an inert `<template>` and one small script. PageSpeed Insights and Lighthouse never interact, so they never see the widget. Once it has loaded, the `tuw_seen` cookie makes it load straight away on later page views.
- **Cloudflare Turnstile** (optional): the number is never in the page. The panel runs the check, and `/reveal` returns the number only after Cloudflare confirms the token.
- **Tap tracking**: opened, passed the check and texts started, per day, device and page, with 7, 30 and 90-day reports. Optional GA4 events.

## How it works

`Text_Us_Widget_Frontend` prints, in `wp_footer`, a `<template id="tuw-template">` with the markup (built by `Text_Us_Widget_Widget::markup()`, which the settings page preview also uses) and `assets/widget.min.js` inline, with the settings JSON and `widget.min.css` swapped in for `__TUW_CONFIG__` and `__TUW_CSS__`.

The script works out the device from the user agent (an iPad says it's a Mac, so a Mac with a touch screen is an iPad). On a device that isn't ticked, or with the `tuw_hide` cookie set, it stops: nothing is added. Otherwise it waits for the first interaction (unless `tuw_seen` is set or the option is off), then adds the styles and the markup, and fills in the icons. Icons aren't in the template, so lazy-load plugins can't rewrite them into images that never load.

Everything is the same for every visitor, so cached pages work. The script carries `data-no-delay`, `data-no-optimize`, `nowprocket`, `data-cfasync="false"` and friends, and is added to WP Rocket, Perfmatters, SiteGround and Autoptimize exclusions: it does its own waiting. Saving the settings clears WP Rocket, W3 Total Cache, WP Super Cache, SiteGround and LiteSpeed caches.

After editing `assets/widget.js` or `assets/widget.css`, run `bin/build.sh` (Node; npx fetches esbuild 0.24.0) and commit the `.min` files.

## Security design

The two REST routes are public by necessity (visitors aren't logged in, and cached pages can't carry a nonce):

- `POST /wp-json/text-us-widget/v1/hit` counts a tap. `POST /wp-json/text-us-widget/v1/reveal` checks a Turnstile token with Cloudflare and returns the number.
- Real POST only (WordPress would otherwise run them for `GET ?_method=POST`, which an `<img>` in a post could send from every visitor's browser), JSON bodies under 4 KB, and requests whose `Origin` or `Sec-Fetch-Site` says another site are refused.
- Every field is checked against a short list: the event, a device that's switched on, and a page that's published, public and not password protected (anything else is stored as 0).
- Bots, prefetches and logged-in editors aren't counted; double taps within 5 seconds merge; one connection can add 30 taps an hour, the site 2,000.
- `/reveal` answers only when Turnstile is on and both keys are set. Tokens with characters Turnstile never uses are refused without calling Cloudflare, and each connection gets 10 tries per 10 minutes (300 for the site). Cloudflare's answer must be a success for our action and, with real keys, this site's hostname.
- With Turnstile on but a key missing, the widget isn't printed at all, so the number can't end up in the page.
- No IP is stored: connections are an HMAC of the IP (IPv6 by /64) with a salt that changes daily.
- The secret key is never printed back into the settings page; leave the box blank to keep it, or set both keys in `wp-config.php`.

## For developers

| Hook or API | What it does |
|---|---|
| `<a href="#text-us">` or `data-tuw-open` | Opens the panel (or goes straight to Messages with "Skip the panel" on) |
| `textUsWidget.open()` | Opens it from your own script, once loaded |
| `document.addEventListener( 'text-us-widget', … )` | Fires on every tap: `detail.action` is `open` or `text`, `detail.device` the device type |
| `text_us_widget_show` | Filter: return false to leave the widget off the current page |
| `text_us_widget_config` | Filter: the settings handed to the browser (keys listed in `assets/widget.js`) |
| `text_us_widget_icons` | Filter: icon URLs (`a` Apple, `g` Android, `o` other) |
| `text_us_widget_client_ip` | Filter: the visitor IP used for rate limits |
| `text_us_widget_connection_limit`, `text_us_widget_site_limit` | Filters: hourly tap caps (30 and 2,000) |
| `text_us_widget_reveal_limit`, `text_us_widget_reveal_site_limit` | Filters: Turnstile checks per 10 minutes (10 and 300) |
| `text_us_widget_turnstile_hosts` | Filter: hostnames a Turnstile check may be solved on |
| `text_us_widget_purge_cache` | Action: fires after saving, for caches the plugin doesn't know |
| `TEXT_US_WIDGET_TURNSTILE_SITE_KEY`, `TEXT_US_WIDGET_TURNSTILE_SECRET_KEY` | `wp-config.php` keys that override the settings |
| `TEXT_US_WIDGET_DISABLED` | `wp-config.php`: true switches the widget off (staging copies) |

## Automatic updates

Updates come from https://github.com/jmsprsns/text-us-widget through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `text-us-widget.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `text-us-widget.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

   Or push an annotated tag (`git tag -a v1.0.1 -m 1.0.1 && git push origin v1.0.1`): with no releases, PUC uses the highest version tag.

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'TEXT_US_WIDGET_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'TEXT_US_WIDGET_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
text-us-widget.php                      Plugin header, constants, boot, activation
uninstall.php                           Deletes the settings, taps table and counters
readme.txt                              WordPress readme; feeds the "View details" popup
bin/build.sh                            Builds widget.min.js and widget.min.css (dev only)
includes/
  class-text-us-widget-plugin.php       Main singleton, settings, devices, number cleanup
  class-text-us-widget-frontend.php     Prints the template and inline script; optimizer opt-outs
  class-text-us-widget-widget.php       Widget markup and browser settings (front end and preview)
  class-text-us-widget-api.php          The /hit and /reveal REST routes
  class-text-us-widget-turnstile.php    Turnstile keys and Cloudflare verification
  class-text-us-widget-request.php      Same-origin, bot and client IP checks, daily-salted hashes
  class-text-us-widget-stats.php        Taps table, reports, daily clean-up
  class-text-us-widget-admin.php        Content Powered > Text Us Widget page and Plugins-screen link
  class-text-us-widget-menu.php         Shared top-level "Content Powered" menu
  class-text-us-widget-feedback.php     Shared Submit Feedback and Report a Bug pages
  class-text-us-widget-updater.php      GitHub update checks (PUC wiring)
  class-text-us-widget-icons.php        Inline Lucide and Simple Icons helper
assets/
  widget.js, widget.css                 Front-end source; .min files are what's printed
  imessage.svg, android-messages.webp,  The Messages app icons (Apple, Android, others)
  message.svg
  admin.css, admin.js                   Loaded only on the settings page
  icon.svg                              Icon on the update screens
  content-powered.svg                   Sumo logo for the Content Powered menu
icons/                                  Lucide icons used by the settings page (plus LICENSE)
plugin-update-checker/                  Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-text-us-widget-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-text-us-widget-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icon set in `icons/`. Print one inline with:

```php
echo Text_Us_Widget_Icons::get( 'package', 20 );           // name, size in px
echo Text_Us_Widget_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
