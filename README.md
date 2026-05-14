# SPC Bunny Connector

| | |
|---|---|
| **Version** | 2.1.1 |
| **Last Updated** | April 04, 2026 |
| **Requires WordPress** | 6.0+ |
| **Requires PHP** | 8.1+ |
| **License** | GPL-2.0+ |
| **Author** | [Nahnu Media](https://nahnumedia.com) |
| **GitHub** | [jaimealnassim/SPC-Bunny](https://github.com/jaimealnassim/SPC-Bunny) |

**WordPress plugin — Super Page Cache Pro + Bunny.net CDN**

Automatically keeps your Bunny Pull Zone cache in sync with Super Page Cache. Deploys a full suite of edge rules, cleans up Perma-Cache storage, warms the cache after purges, and surfaces live CDN stats — all from a single WordPress admin panel.

---

## The Problem

Super Page Cache Pro manages your server's HTML cache. Bunny CDN serves that HTML from the edge. But when SPC clears its cache, Bunny doesn't know. Your server has fresh content while Bunny keeps serving stale HTML to visitors.

The obvious fix — hook into SPC's purge actions — turns out to be non-trivial. The hooks the SPC team documents (`swcfpc_cf_purge_whole_cache_after`, `swcfpc_cf_purge_cache_by_urls_after`) only fire when Cloudflare is actively connected. Since this stack uses Bunny instead of Cloudflare, those hooks never fire.

The correct hooks are `swcfpc_purge_all` and `swcfpc_purge_urls`, found in `cache_controller.class.php`. These fire unconditionally regardless of CDN provider. This plugin hooks those directly.

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Super Page Cache (free or Pro)
- Bunny.net account with a Pull Zone

---

## Installation

1. Download the latest zip from [Releases](../../releases)
2. In WordPress, go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → SPC Bunny Connector**
5. Enter your **Bunny Account API Key** (found in your Bunny dashboard under Account)
6. Select your **Pull Zone** from the dropdown
7. Go to the **Edge Rules tab** and click **Deploy Edge Rules**

That's it. Bunny will now purge automatically whenever SPC clears its cache.

---

## Configuration

### Settings Tab

| Setting | Description |
|---|---|
| Account API Key | Your Bunny.net account API key |
| Pull Zone | The Pull Zone connected to your WordPress site |
| DNS Zone ID | Optional — enables the DNS Stats tab if you use Bunny DNS |
| Post publish / update | Purge Bunny when a post or page is saved |
| Plugin / theme updates | Purge Bunny when plugins or themes are updated |
| Admin toolbar button | Adds a Purge Bunny button to the WP admin bar |
| Auto-warm cache after purge | Crawls your sitemap after every full purge to pre-populate the edge |
| Perma-Cache cleanup | Deletes old Perma-Cache directories after every purge |

### Edge Rules Tab

Deploys up to 20 edge rules to your Pull Zone. Each rule has an on/off toggle — enable only what your site needs. Rules are deployed in a specific order so bypass rules always take priority over cache rules.

Three action buttons are always available:

| Button | What it does |
|---|---|
| **Deploy Edge Rules** | Creates all rules fresh. Safe to run at any time. |
| **Update Edge Rules** | Wipes all existing rules from Bunny, then redeploys from scratch. Use after changing settings or if rules are out of sync. |
| **Remove All Rules** | Deletes every SPC Bunny rule from the Pull Zone. No redeploy. |

> Deploy and Update both wipe the Pull Zone before creating rules, so there are never duplicate or conflicting OrderIndex values.

**Custom Cache Exclusions** — enter one path per line to bypass edge caching for specific pages. Supports wildcards (`/shop/*`). Changes take effect on next Deploy or Update.

### Manual Purge Tab

Purge the full Bunny Pull Zone cache on demand.

### Purge Log Tab

Shows the last 20 purge events with timestamp, trigger source, and result.

### DNS Stats Tab

Query statistics for your Bunny DNS zone. Requires a DNS Zone ID in Settings.

---

## Edge Rules

All 20 rules, in deployment order:

| # | Rule | What it does |
|---|---|---|
| 1 | Force SSL | Redirects HTTP → HTTPS at the CDN edge. Faster than an origin redirect. |
| 2 | Disable Shield & WAF: WP admin | Bypasses Bunny Shield and WAF for `/wp-admin/*`. `wp-login.php` keeps Shield active for bot protection. |
| 3 | Bypass cache: logged-in users | Skips cache for `wordpress_logged_in_*`, `comment_author_*`, `wp-postpass_*` cookies. |
| 4 | Bypass cache: WP admin & PHP | No caching for `/wp-admin/*` and `*.php` requests. |
| 5 | Bypass Perma-Cache: WP admin & PHP | Admin responses must never be stored in Perma-Cache. |
| 6 | Disable Optimizer: WP admin & login | Bunny Optimizer can mangle admin JS/CSS. Disabled for admin and login pages. |
| 7 | Bypass cache: wp-cron.php | WP cron must always hit origin. Never cached or Shield-challenged. |
| 8 | Bypass cache: REST API | `/wp-json/*` is dynamic per-request. Never cached. |
| 9 | Bypass cache: POST requests | All HTTP POST requests (form submissions, AJAX writes) bypass cache entirely — even with Force Cache active. Covers all form plugins and `admin-ajax.php`. |
| 10 | Bypass cache: RSS/Atom feeds | Feeds update with every new post. Always served fresh. |
| 11–12 | WooCommerce pages + cookies | Dynamically resolved from WooCommerce settings. Supports WPML and Polylang. |
| 13–14 | SureCart pages + cookies | Checkout, dashboard, order confirmation, shop, cart. Supports WPML and Polylang. |
| 15 | Custom URL exclusions | Your own paths, configured in the Edge Rules tab. |
| 16 | Cache HTML: anonymous visitors | Caches HTTP 200 responses at Bunny edge nodes. TTL configurable (1 hour to 30 days). |
| 17 | No browser cache: HTML | Sends `Cache-Control: no-store` to browsers. Edge still caches — browsers always fetch fresh after purge. No manual reload needed. |
| 18 | Long browser cache: static assets | 1-year browser cache for CSS, JS, images, fonts. |
| 19 | Ignore query string: CSS & JS | `style.css?ver=6.4.1` and `style.css?ver=6.4.2` share one cache entry. Eliminates redundant misses from WordPress version params. |
| 20 | Security headers | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-XSS-Protection: 1; mode=block` on all responses. |

---

## Cache Warmer

After every full purge, an optional background cron job fetches your sitemap and makes an HTTP request to each URL. This pre-populates Bunny's edge cache so the first real visitor gets a HIT instead of a MISS.

**Supported sitemap plugins** — the warmer detects whichever is active:

- Yoast SEO (free + premium)
- Rank Math
- All in One SEO
- SEOPress (free + pro)
- SlimSEO
- The SEO Framework
- Squirrly SEO
- WordPress core sitemap (5.5+)

If no sitemap is found, falls back to the homepage plus the 50 most recent posts.

**Settings:** configurable batch size (1–50 URLs per run) and delay between batches (5–300 seconds).

---

## Perma-Cache Cleanup

When Bunny does a full Pull Zone purge, Perma-Cache files are **not** deleted. Bunny switches to a new directory inside your storage zone (`pullzone__yourzone__XXXXXXXX/`) and the old one accumulates indefinitely, costing storage.

This plugin connects to the Bunny Edge Storage API and automatically deletes old directories after every purge, keeping only the newest (currently active) one.

**Setup:**
1. Go to **Settings → Advanced → Perma-Cache Cleanup**
2. Enter your **Storage Zone Name** (the zone connected to Perma-Cache in your Pull Zone settings)
3. Enter the **Storage Zone Password** (found under FTP & API Access in the storage zone — different from your account API key)
4. Select your **Storage Region**
5. Set how many directories to keep (default: 1)
6. Click **Test Connection** to verify, then **Save Settings**

Cleanup runs automatically on every full purge and logs results to the Purge Log tab.

---

## Cache Sync Status

The Stats tab includes a **Cache Sync Status** card that polls every 8 seconds and shows:

- When Bunny CDN was last cleared
- When Super Page Cache was last cleared
- Whether the two are in sync (within 30 seconds of each other)

This makes it easy to confirm that clearing SPC is actually triggering a Bunny purge.

---

## How Purges Are Triggered

| Event | Trigger |
|---|---|
| SPC "Purge whole cache" button | `swcfpc_purge_all` action |
| SPC per-URL purge (post save) | `swcfpc_purge_urls` action |
| Post publish / update (classic editor) | `save_post` hook |
| Post publish / update (Bricks Builder, Gutenberg) | `rest_after_insert_{post_type}` hook |
| Plugin or theme update | `upgrader_process_complete` hook |
| Manual purge button (admin panel) | AJAX |
| Admin bar purge button | AJAX |

All purges are full Pull Zone purges. Bunny's per-URL purge is unreliable due to URL variant mismatches (www/non-www, trailing slash, query strings), so a full purge is always used.

---

## Frequently Asked Questions

**Do I need Super Page Cache Pro or does the free version work?**
Both work. The hooks this plugin uses (`swcfpc_purge_all`, `swcfpc_purge_urls`) exist in the free version.

**Why does this do a full Pull Zone purge instead of purging just the changed URL?**
Bunny's per-URL purge requires an exact match including protocol, subdomain, trailing slash, and query string variants. A single post URL typically has 4–6 valid cached variants. Missing any one means the stale version stays at the edge. A full zone purge takes the same amount of time and guarantees a clean slate.

**Will the cache warmer slow down my server after a purge?**
The warmer runs in batches via WP cron with a configurable delay between batches. The default is 5 URLs per batch with a 30-second delay. Adjust these based on your server capacity.

**Does this work with Cloudflare in front of Bunny?**
Not tested. This plugin assumes Bunny is the edge layer. If Cloudflare is in front, you'd need a separate Cloudflare purge step.

**Why does the Force SSL rule only match HTTP URLs?**
The rule triggers on `http://yourdomain.com/*` and redirects to HTTPS. HTTPS traffic is not affected.

**Can I use this without deploying edge rules?**
Yes. The cache sync (purging Bunny when SPC purges) works independently of the edge rules. Edge rules are optional and additive.

**Why do Deploy and Update both wipe existing rules first?**
Bunny requires unique `OrderIndex` values per Pull Zone. Updating rules in-place risks conflicts if a previous deploy partially succeeded or if the stored GUIDs are out of sync. Wiping first guarantees a clean slate with no duplicate index errors.

**My form submissions stopped working after deploying edge rules. What should I do?**
Rule 9 (POST bypass) ensures all form submissions bypass cache at the HTTP method level. If a specific form page also needs to bypass for logged-out users, add its path to **Custom Cache Exclusions** and redeploy.

---

## Development Notes

### Architecture

```
spc-bunny-connector/
├── spc-bunny-connector.php             # Bootstrap, SPC hook registration at file scope
└── includes/
    ├── class-spc-bunny-api.php         # Bunny REST API (Pull Zone, Storage, DNS)
    ├── class-spc-bunny-stats.php       # CDN stats fetcher
    ├── class-spc-bunny-purge.php       # Purge orchestration
    ├── class-spc-bunny-hooks.php       # WordPress action hooks
    ├── class-spc-bunny-warmer.php      # Sitemap crawler and cache warmer
    ├── class-spc-bunny-edge-rules.php  # Edge rule deployment (20 rules)
    ├── class-spc-bunny-perma-cache.php # Perma-Cache storage cleanup
    ├── class-spc-bunny-admin.php       # Admin UI
    ├── class-spc-bunny-updater.php     # GitHub auto-update initialiser
    └── class-wp-github-updater.php     # WP_GitHub_Updater library (v1.6)
```

### Why SPC hooks are registered at file scope

WordPress loads plugins alphabetically. `wp-cloudflare-page-cache` loads before `spc-bunny-connector`. By the time `plugins_loaded` fires and most plugins register their hooks, SPC may have already completed a purge triggered early in the request lifecycle (AJAX, REST, cron).

Registering `swcfpc_purge_all` and `swcfpc_purge_urls` as global functions at the top level of the main plugin file — before any `add_action` wrapper — guarantees they are in the WordPress hook registry before SPC runs, regardless of load order.

### Auto-updates

Updates are delivered via the [WP_GitHub_Updater](https://github.com/radishconcepts/WordPress-GitHub-Plugin-Updater) library. It reads the `Version:` header from `spc-bunny-connector.php` on the `main` branch and notifies WordPress when a newer version is available. No GitHub Release is required — bump the version header and push to `main`.

### Bunny API endpoints used

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/pullzone` | List Pull Zones for the dropdown |
| `GET` | `/pullzone/{id}` | Fetch Pull Zone details + `EdgeRules[]` for wipe/delete |
| `POST` | `/pullzone/{id}` | Update Pull Zone settings on deploy |
| `POST` | `/pullzone/{id}/purgeCache` | Full Pull Zone cache purge |
| `POST` | `/pullzone/{id}/edgerules/addOrUpdate` | Create edge rule |
| `DELETE` | `/pullzone/{id}/edgerules/{guid}` | Delete edge rule |
| `GET` | `/statistics?pullZone={id}` | CDN bandwidth and request stats |
| `GET` | `/dnszone` | List DNS zones |
| `GET` | `/dnszone/{id}/statistics` | DNS query statistics |
| `GET` | `/{zoneName}/__bcdn_perma_cache__/` | List Perma-Cache directories (Storage API) |
| `DELETE` | `/{zoneName}/{path}/` | Delete Perma-Cache directory (Storage API) |

> **Note:** There is no dedicated `GET /pullzone/{id}/edgerules` endpoint in the Bunny API. Edge rules are returned as the `EdgeRules` array inside the pull zone object from `GET /pullzone/{id}`.

---

## License

GPL-2.0+

---

Built by [Nahnu Media](https://nahnumedia.com)
