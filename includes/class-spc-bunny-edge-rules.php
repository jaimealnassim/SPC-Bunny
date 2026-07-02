<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;

class SPC_Bunny_Edge_Rules {

    private const GUIDS_OPT    = 'spc_bunny_edge_rule_guids';
    private const MAX_PATTERNS = 5;

    // Rule keys in deployment order (OrderIndex)
    public const RULE_FORCE_SSL              = 'force_ssl';               // 1  — HTTPS everywhere
    public const RULE_DISABLE_SHIELD_ADMIN   = 'disable_shield_admin';    // 2  — Shield bypass for admin
    public const RULE_BYPASS_LOGGED_IN       = 'bypass_logged_in';        // 3  — Logged-in users
    public const RULE_BYPASS_ADMIN           = 'bypass_admin';            // 4  — WP admin & PHP
    public const RULE_ADMIN_BYPASS_PC        = 'admin_bypass_perma_cache'; // 5 — Perma-Cache for admin
    public const RULE_ADMIN_DISABLE_OPT      = 'admin_disable_optimizer'; // 6  — Optimizer for admin
    public const RULE_BYPASS_CRON            = 'bypass_cron';             // 7  — wp-cron.php
    public const RULE_BYPASS_REST            = 'bypass_rest';             // 8  — REST API
    public const RULE_BYPASS_FEEDS           = 'bypass_feeds';            // 9  — RSS/Atom feeds
    public const RULE_BYPASS_WOO             = 'bypass_woo';              // 10 — WooCommerce pages
    public const RULE_BYPASS_WOO_COOKIE      = 'bypass_woo_cookie';       // 11 — WooCommerce cookies
    public const RULE_BYPASS_SC              = 'bypass_sc';               // 12 — SureCart pages
    public const RULE_BYPASS_SC_COOKIE       = 'bypass_sc_cookie';        // 13 — SureCart cookies
    public const RULE_CUSTOM_BYPASS          = 'custom_bypass';           // 14 — Custom exclusions
    public const RULE_CACHE_HTML             = 'cache_html';              // 15 — Cache HTML
    public const RULE_NO_BROWSER_CACHE       = 'no_browser_cache_html';   // 16 — No browser cache for HTML
    public const RULE_STATIC_BROWSER_CACHE   = 'static_browser_cache';    // 17 — Long browser cache for assets
    public const RULE_STATIC_IGNORE_QS       = 'static_ignore_qs';        // 18 — Ignore ?ver= on assets
    public const RULE_SECURITY_HEADERS       = 'security_headers';        // 19 — Security response headers

    // Static asset extensions that should get long browser cache
    private const STATIC_EXTS = [ 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf' ];

    private SPC_Bunny_API $api;
    private string $host;
    private string $host_www;

    public function __construct() {
        $this->api      = new SPC_Bunny_API();
        $parsed         = wp_parse_url( home_url() );
        $this->host     = $parsed['host'] ?? '';
        $this->host_www = str_starts_with( $this->host, 'www.' )
            ? $this->host
            : 'www.' . $this->host;
    }

    public function deploy( int $ttl = 604800, bool $force_cache = false ): array {
        if ( ! $this->api->is_configured() ) {
            return [ 'success' => false, 'results' => [ [ 'label' => 'Error', 'success' => false, 'message' => __( 'Configure API key and Pull Zone first.', 'spc-bunny' ) ] ] ];
        }

        $results = [];
        $guids   = get_option( self::GUIDS_OPT, [] );

        // Clean up stale GUIDs from removed rules
        $stale_keys = [ 'uploads_bypass_perma_cache' ];
        foreach ( $stale_keys as $stale ) {
            if ( ! empty( $guids[ $stale ] ) ) {
                $this->api->delete_edge_rule( $guids[ $stale ] );
                unset( $guids[ $stale ] );
            }
        }

        // ── Pull Zone settings ────────────────────────────────────────────────
        $pz = $this->api->update_pull_zone( [
            'DisableSmartCache'          => true,
            'EnableCacheSlice'           => false,
            'IgnoreQueryStrings'         => false,
            'EnableQueryStringOrdering'  => true,
            'DisableCookies'             => false,
            'CacheControlMaxAgeOverride' => $force_cache ? $ttl : -1,
            'BrowserCacheTime'           => 0,
        ] );
        $results['pull_zone_settings'] = [
            'label'   => 'Pull Zone settings',
            'success' => ! is_wp_error( $pz ),
            'message' => is_wp_error( $pz ) ? $pz->get_error_message() : 'Applied',
        ];

        $h    = $this->host;
        $hwww = $this->host_www;

        // ── Rule 1: Force SSL ─────────────────────────────────────────────────
        // Redirect all HTTP to HTTPS at the CDN edge — faster than origin redirect.
        $guids = $this->upsert( self::RULE_FORCE_SSL, [
            'OrderIndex'          => 1,
            'ActionType'          => 0,  // ForceSSL
            'ActionParameter1'    => '',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Force SSL: redirect HTTP to HTTPS',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "http://{$h}/*", "http://{$hwww}/*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 2: Disable Shield & WAF — WP admin only ──────────────────────
        // wp-login.php intentionally keeps Shield for bot protection.
        $guids = $this->upsert( self::RULE_DISABLE_SHIELD_ADMIN, [
            'OrderIndex'          => 2,
            'ActionType'          => 27,  // DisableShieldChallenge
            'ActionParameter1'    => '',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Disable Shield & WAF: WP admin',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/wp-admin/*", "https://{$hwww}/wp-admin/*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
            'ExtraActions'        => [ [
                'ActionType'       => 23,  // DisableWAF
                'ActionParameter1' => '',
                'ActionParameter2' => '',
                'ActionParameter3' => '',
            ] ],
        ], $guids, $results );

        // ── Rule 3: Bypass cache — logged-in users ────────────────────────────
        $guids = $this->upsert( self::RULE_BYPASS_LOGGED_IN, [
            'OrderIndex'          => 3,
            'ActionType'          => 3,
            'ActionParameter1'    => '0',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Bypass: logged-in users',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 1,
                'Parameter1'          => 'cookie',
                'PatternMatches'      => [ '*wordpress_logged_in_*', '*comment_author_*', '*wp-postpass_*' ],
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 4: Bypass cache — WP admin & PHP ────────────────────────────
        $admin_patterns = $this->both_hosts( [ '/wp-admin/*', '/*.php*' ] );
        $guids = $this->upsert_batches( self::RULE_BYPASS_ADMIN, '[SPC Bunny] Bypass: WP admin & PHP', '0', $admin_patterns, $guids, $results, 4 );

        // ── Rule 5: Bypass Perma-Cache — WP admin & PHP ───────────────────────
        $guids = $this->upsert( self::RULE_ADMIN_BYPASS_PC, [
            'OrderIndex'          => 5,
            'ActionType'          => 15,  // BypassPermaCache
            'ActionParameter1'    => '',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Bypass Perma-Cache: WP admin & PHP',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/wp-admin/*", "https://{$hwww}/wp-admin/*", "https://{$h}/*.php*", "https://{$hwww}/*.php*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 6: Disable Optimizer — WP admin & login ─────────────────────
        $guids = $this->upsert( self::RULE_ADMIN_DISABLE_OPT, [
            'OrderIndex'          => 6,
            'ActionType'          => 12,  // DisableOptimizer
            'ActionParameter1'    => '',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Disable Optimizer: WP admin & login',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/wp-admin/*", "https://{$hwww}/wp-admin/*", "https://{$h}/wp-login.php*", "https://{$hwww}/wp-login.php*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 7: Bypass — wp-cron.php ─────────────────────────────────────
        // WP cron must always hit origin fresh. Never cache or Shield-challenge it.
        $guids = $this->upsert( self::RULE_BYPASS_CRON, [
            'OrderIndex'          => 7,
            'ActionType'          => 3,
            'ActionParameter1'    => '0',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Bypass: wp-cron.php',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/wp-cron.php*", "https://{$hwww}/wp-cron.php*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 8: Bypass — REST API ─────────────────────────────────────────
        // /wp-json/* responses are dynamic. Never cache them.
        $guids = $this->upsert( self::RULE_BYPASS_REST, [
            'OrderIndex'          => 8,
            'ActionType'          => 3,
            'ActionParameter1'    => '0',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Bypass: REST API',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/wp-json/*", "https://{$hwww}/wp-json/*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 9: Bypass — RSS/Atom feeds ──────────────────────────────────
        // Feeds change with every new post. Aggregators poll frequently.
        $guids = $this->upsert( self::RULE_BYPASS_FEEDS, [
            'OrderIndex'          => 9,
            'ActionType'          => 3,
            'ActionParameter1'    => '0',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Bypass: RSS/Atom feeds',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/feed/*", "https://{$hwww}/feed/*", "https://{$h}/*/feed/*", "https://{$hwww}/*/feed/*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
        ], $guids, $results );

        // ── Rule 10+11: WooCommerce ───────────────────────────────────────────
        if ( class_exists( 'WooCommerce' ) ) {
            $woo = $this->both_hosts( $this->get_woo_paths() );
            $guids = $this->upsert_batches( self::RULE_BYPASS_WOO, '[SPC Bunny] Bypass: WooCommerce pages', '0', $woo, $guids, $results, 10 );

            $guids = $this->upsert( self::RULE_BYPASS_WOO_COOKIE, [
                'OrderIndex'          => 11,
                'ActionType'          => 3,
                'ActionParameter1'    => '0',
                'TriggerMatchingType' => 0,
                'Description'         => '[SPC Bunny] Bypass: WooCommerce session cookies',
                'Enabled'             => true,
                'Triggers'            => [ [
                    'Type'                => 1,
                    'Parameter1'          => 'cookie',
                    'PatternMatches'      => [ '*woocommerce_cart_hash*', '*woocommerce_items_in_cart*', '*woocommerce_session_*' ],
                    'PatternMatchingType' => 0,
                ] ],
            ], $guids, $results );
        }

        // ── Rule 12+13: SureCart ──────────────────────────────────────────────
        if ( class_exists( 'SureCart' ) ) {
            $sc = $this->both_hosts( $this->get_surecart_paths() );
            if ( ! empty( $sc ) ) {
                $guids = $this->upsert_batches( self::RULE_BYPASS_SC, '[SPC Bunny] Bypass: SureCart pages', '0', $sc, $guids, $results, 12 );
            }
            $guids = $this->upsert( self::RULE_BYPASS_SC_COOKIE, [
                'OrderIndex'          => 13,
                'ActionType'          => 3,
                'ActionParameter1'    => '0',
                'TriggerMatchingType' => 0,
                'Description'         => '[SPC Bunny] Bypass: SureCart session cookies',
                'Enabled'             => true,
                'Triggers'            => [ [
                    'Type'                => 1,
                    'Parameter1'          => 'cookie',
                    'PatternMatches'      => [ '*sc_session*', '*surecart_*', '*sc_cart*' ],
                    'PatternMatchingType' => 0,
                ] ],
            ], $guids, $results );
        }

        // ── Rule 14: Custom URL exclusions ────────────────────────────────────
        $custom_paths = $this->get_custom_bypass_paths();
        if ( ! empty( $custom_paths ) ) {
            $guids = $this->upsert_batches( self::RULE_CUSTOM_BYPASS, '[SPC Bunny] Bypass: custom URLs', '0', $custom_paths, $guids, $results, 14 );
        } elseif ( isset( $guids[ self::RULE_CUSTOM_BYPASS ] ) ) {
            $this->api->delete_edge_rule( $guids[ self::RULE_CUSTOM_BYPASS ] );
            unset( $guids[ self::RULE_CUSTOM_BYPASS ] );
        }

        // ── Rule 15: Cache HTML — anonymous visitors ──────────────────────────
        $guids = $this->upsert( self::RULE_CACHE_HTML, [
            'OrderIndex'          => 15,
            'ActionType'          => 3,
            'ActionParameter1'    => (string) $ttl,
            'TriggerMatchingType' => 1,
            'Description'         => sprintf( '[SPC Bunny] Cache HTML (%ds TTL)', $ttl ),
            'Enabled'             => true,
            'Triggers'            => [
                [
                    'Type'                => 0,
                    'PatternMatches'      => array_values( array_unique( [ "https://{$h}/*", "https://{$hwww}/*" ] ) ),
                    'PatternMatchingType' => 0,
                ],
                [
                    'Type'                => 8,
                    'PatternMatches'      => [ '200' ],
                    'PatternMatchingType' => 0,
                ],
            ],
        ], $guids, $results );

        // ── Rule 16: No browser cache for HTML ────────────────────────────────
        // Bunny edge caches; browsers do not. Fresh page after every purge.
        $guids = $this->upsert( self::RULE_NO_BROWSER_CACHE, [
            'OrderIndex'          => 16,
            'ActionType'          => 16,  // OverrideBrowserCacheTime
            'ActionParameter1'    => '0',
            'TriggerMatchingType' => 1,
            'Description'         => '[SPC Bunny] No browser cache: HTML pages',
            'Enabled'             => true,
            'Triggers'            => [
                [
                    'Type'                => 0,
                    'PatternMatches'      => array_values( array_unique( [ "https://{$h}/*", "https://{$hwww}/*" ] ) ),
                    'PatternMatchingType' => 0,
                ],
                [
                    'Type'                => 8,
                    'PatternMatches'      => [ '200' ],
                    'PatternMatchingType' => 0,
                ],
            ],
        ], $guids, $results );

        // ── Rule 17: Long browser cache for static assets ─────────────────────
        // ActionType 16 = OverrideBrowserCacheTime. ActionParameter1 = seconds.
        // 31536000 = 1 year. Affects CSS, JS, images, fonts.
        $static_patterns = [];
        foreach ( self::STATIC_EXTS as $ext ) {
            $static_patterns[] = "https://{$h}/*.{$ext}";
            if ( $h !== $hwww ) {
                $static_patterns[] = "https://{$hwww}/*.{$ext}";
            }
        }
        $static_patterns = array_values( array_unique( $static_patterns ) );
        // Use upsert_batches with ActionType 16 override and '31536000' as param
        $guids = $this->upsert_batches( self::RULE_STATIC_BROWSER_CACHE, '[SPC Bunny] Long browser cache: static assets', '31536000', $static_patterns, $guids, $results, 17, null, 16 );

        // ── Rule 18: Ignore query string for static assets ────────────────────
        // ?ver=6.4.1 and ?ver=6.4.2 share the same cache entry. Eliminates
        // redundant cache misses from WordPress version params on assets.
        $qs_patterns = [];
        foreach ( [ 'css', 'js' ] as $ext ) {
            $qs_patterns[] = "https://{$h}/*.{$ext}?*";
            if ( $h !== $hwww ) {
                $qs_patterns[] = "https://{$hwww}/*.{$ext}?*";
            }
        }
        $guids = $this->upsert_batches( self::RULE_STATIC_IGNORE_QS, '[SPC Bunny] Ignore query string: CSS & JS', '', array_values( array_unique( $qs_patterns ) ), $guids, $results, 18, null, 11 );

        // ── Rule 19: Security response headers ───────────────────────────────
        // X-Content-Type-Options, X-Frame-Options, Referrer-Policy on all responses.
        // Use ExtraActions to set multiple headers in one rule.
        $guids = $this->upsert( self::RULE_SECURITY_HEADERS, [
            'OrderIndex'          => 19,
            'ActionType'          => 5,  // SetResponseHeader
            'ActionParameter1'    => 'X-Content-Type-Options',
            'ActionParameter2'    => 'nosniff',
            'TriggerMatchingType' => 0,
            'Description'         => '[SPC Bunny] Security headers',
            'Enabled'             => true,
            'Triggers'            => [ [
                'Type'                => 0,
                'PatternMatches'      => array_values( array_unique( [ "https://{$h}/*", "https://{$hwww}/*" ] ) ),
                'PatternMatchingType' => 0,
            ] ],
            'ExtraActions'        => [
                [ 'ActionType' => 5, 'ActionParameter1' => 'X-Frame-Options',     'ActionParameter2' => 'SAMEORIGIN',                  'ActionParameter3' => '' ],
                [ 'ActionType' => 5, 'ActionParameter1' => 'Referrer-Policy',     'ActionParameter2' => 'strict-origin-when-cross-origin', 'ActionParameter3' => '' ],
                [ 'ActionType' => 5, 'ActionParameter1' => 'X-XSS-Protection',   'ActionParameter2' => '1; mode=block',               'ActionParameter3' => '' ],
            ],
        ], $guids, $results );

        update_option( self::GUIDS_OPT, $guids, false );
        $all_ok = ! in_array( false, array_column( $results, 'success' ), true );
        return [ 'success' => $all_ok, 'results' => $results ];
    }

    public function remove_all(): array {
        $guids   = get_option( self::GUIDS_OPT, [] );
        $results = [];
        foreach ( $guids as $key => $guid ) {
            $r = $this->api->delete_edge_rule( $guid );
            $results[ $key ] = [
                'label'   => $key,
                'success' => ! is_wp_error( $r ),
                'message' => is_wp_error( $r ) ? $r->get_error_message() : 'Deleted',
            ];
        }
        delete_option( self::GUIDS_OPT );
        $all_ok = ! in_array( false, array_column( $results, 'success' ), true );
        return [ 'success' => $all_ok, 'results' => $results ];
    }

    public function get_deployed_guids(): array {
        return get_option( self::GUIDS_OPT, [] );
    }

    public function get_woo_paths(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [];
        }
        $page_ids = array_filter( [
            (int) wc_get_page_id( 'cart' ),
            (int) wc_get_page_id( 'checkout' ),
            (int) wc_get_page_id( 'myaccount' ),
            (int) wc_get_page_id( 'shop' ),
        ] );
        $paths = [];
        foreach ( $page_ids as $id ) {
            $paths = array_merge( $paths, $this->resolve_paths( $id ) );
        }
        return array_values( array_unique( $paths ) );
    }

    public function get_surecart_paths(): array {
        if ( ! class_exists( 'SureCart' ) ) {
            return [];
        }
        $paths     = [];
        $page_keys = [ 'checkout', 'dashboard', 'order_confirmation', 'shop', 'cart' ];
        foreach ( $page_keys as $key ) {
            $id = (int) get_option( "surecart_page_id_{$key}", 0 );
            if ( $id > 0 ) {
                $paths = array_merge( $paths, $this->resolve_paths( $id ) );
            }
        }
        if ( method_exists( 'SureCart', 'pages' ) ) {
            try {
                $sc_pages = SureCart::pages();
                if ( is_object( $sc_pages ) && method_exists( $sc_pages, 'all' ) ) {
                    foreach ( (array) $sc_pages->all() as $page ) {
                        $id = is_object( $page ) && isset( $page->id ) ? (int) $page->id : 0;
                        if ( $id > 0 ) {
                            $paths = array_merge( $paths, $this->resolve_paths( $id ) );
                        }
                    }
                }
            } catch ( \Exception $e ) {
                // SureCart API unavailable
            }
        }
        return array_values( array_unique( array_filter( $paths ) ) );
    }

    public function get_custom_bypass_paths(): array {
        $opts  = get_option( 'spc_bunny_settings', [] );
        $raw   = (string) ( $opts['custom_bypass_paths'] ?? '' );
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $paths = [];
        foreach ( $lines as $line ) {
            if ( str_starts_with( $line, 'http' ) ) {
                $line = wp_parse_url( $line, PHP_URL_PATH ) ?? $line;
            }
            $line = '/' . ltrim( $line, '/' );
            if ( ! str_ends_with( $line, '*' ) ) {
                $line = rtrim( $line, '/' ) . '/*';
            }
            $paths = array_merge( $paths, $this->both_hosts( [ $line ] ) );
        }
        return array_values( array_unique( array_filter( $paths ) ) );
    }

    private function resolve_paths( int $page_id ): array {
        $paths = [];
        $url   = get_permalink( $page_id );
        if ( $url ) {
            $paths[] = $this->to_wildcard( $url );
        }
        if ( function_exists( 'icl_get_languages' ) ) {
            foreach ( icl_get_languages( 'skip_missing=0' ) as $lang ) {
                $tid = (int) apply_filters( 'wpml_object_id', $page_id, 'page', false, $lang['language_code'] );
                if ( $tid && $tid !== $page_id ) {
                    $turl = get_permalink( $tid );
                    if ( $turl ) { $paths[] = $this->to_wildcard( $turl ); }
                }
            }
        }
        if ( function_exists( 'pll_get_post_translations' ) ) {
            foreach ( pll_get_post_translations( $page_id ) as $tid ) {
                if ( (int) $tid !== $page_id ) {
                    $turl = get_permalink( $tid );
                    if ( $turl ) { $paths[] = $this->to_wildcard( $turl ); }
                }
            }
        }
        return array_values( array_unique( array_filter( $paths ) ) );
    }

    private function to_wildcard( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH ) ?? '/';
        return rtrim( $path, '/' ) . '/*';
    }

    private function both_hosts( array $paths ): array {
        $out = [];
        foreach ( $paths as $p ) {
            $out[] = 'https://' . $this->host     . $p;
            $out[] = 'https://' . $this->host_www . $p;
        }
        return array_values( array_unique( $out ) );
    }

    private function is_rule_enabled( string $key ): bool {
        $opts    = get_option( 'spc_bunny_settings', [] );
        $enabled = $opts['enabled_rules'] ?? null;
        // If setting not yet saved (fresh install), all rules are enabled by default
        if ( ! is_array( $enabled ) ) {
            return true;
        }
        return in_array( $key, $enabled, true );
    }

    private function upsert_batches( string $base, string $label, string $cache_time, array $patterns, array $guids, array &$results, int $order_index = 10, ?int $browser_cache = null, ?int $action_type_override = null ): array {
        $chunks = array_chunk( $patterns, self::MAX_PATTERNS );
        $total  = count( $chunks );
        foreach ( $chunks as $i => $chunk ) {
            $key  = $i === 0 ? $base : $base . '_' . $i;
            $desc = $total === 1 ? $label : sprintf( '%s (%d/%d)', $label, $i + 1, $total );
            $action = $action_type_override ?? 3;
            $param1 = $action === 11 ? '' : $cache_time;  // IgnoreQueryString takes no param
            $rule   = [
                'OrderIndex'          => $order_index,
                'ActionType'          => $action,
                'ActionParameter1'    => $param1,
                'TriggerMatchingType' => 0,
                'Description'         => $desc,
                'Enabled'             => true,
                'Triggers'            => [ [
                    'Type'                => 0,
                    'PatternMatches'      => array_values( $chunk ),
                    'PatternMatchingType' => 0,
                ] ],
            ];
            if ( $browser_cache !== null && $action === 3 ) {
                // For cache rules, also set browser cache time via ActionParameter2 if it differs
                // (handled as ExtraAction for cleanliness)
                $rule['ExtraActions'] = [ [
                    'ActionType'       => 16,
                    'ActionParameter1' => (string) $browser_cache,
                    'ActionParameter2' => '',
                    'ActionParameter3' => '',
                ] ];
            }
            $guids = $this->upsert( $key, $rule, $guids, $results );
        }
        for ( $i = $total; isset( $guids[ $base . '_' . $i ] ); $i++ ) {
            $stale = $base . '_' . $i;
            $this->api->delete_edge_rule( $guids[ $stale ] );
            unset( $guids[ $stale ] );
        }
        return $guids;
    }

    private function upsert( string $key, array $rule, array $guids, array &$results ): array {
        // Skip deployment if disabled in settings; delete from Bunny if previously deployed
        if ( ! $this->is_rule_enabled( $key ) ) {
            if ( ! empty( $guids[ $key ] ) ) {
                $this->api->delete_edge_rule( $guids[ $key ] );
                unset( $guids[ $key ] );
            }
            return $guids;
        }
        $guid     = $guids[ $key ] ?? null;
        $response = $this->api->upsert_edge_rule( $rule, $guid );
        if ( is_wp_error( $response ) ) {
            $results[ $key ] = [ 'label' => $rule['Description'], 'success' => false, 'message' => $response->get_error_message() ];
            return $guids;
        }
        $new_guid = $response['Guid'] ?? $guid;
        $results[ $key ] = [ 'label' => $rule['Description'], 'success' => true, 'message' => $guid ? 'Updated' : 'Created' ];
        if ( $new_guid ) {
            $guids[ $key ] = $new_guid;
        }
        return $guids;
    }
}
