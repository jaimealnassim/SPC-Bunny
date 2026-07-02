<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;

class SPC_Bunny_Admin {

    private const MENU = 'spc-bunny-settings';
    private const OPT  = 'spc_bunny_settings';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu'          ]        );
        add_action( 'admin_init',            [ $this, 'register_settings' ]        );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'    ]        );
        add_action( 'wp_ajax_spc_bunny_test_connection', [ $this, 'ajax_test'   ] );
        add_action( 'wp_ajax_spc_bunny_fetch_zones',     [ $this, 'ajax_zones'  ] );
        add_action( 'wp_ajax_spc_bunny_fetch_stats',     [ $this, 'ajax_stats'  ] );
        add_action( 'wp_ajax_spc_bunny_warm_now',        [ $this, 'ajax_warm'   ] );
        add_action( 'wp_ajax_spc_bunny_deploy_rules',    [ $this, 'ajax_deploy' ] );
        add_action( 'wp_ajax_spc_bunny_remove_rules',    [ $this, 'ajax_remove'      ] );
        add_action( 'wp_ajax_spc_bunny_sync_status',     [ $this, 'ajax_sync_status'      ] );
        add_action( 'wp_ajax_spc_bunny_test_perma_cache', [ $this, 'ajax_test_perma_cache' ] );
        add_action( 'wp_ajax_spc_bunny_fetch_dns_zones',  [ $this, 'ajax_fetch_dns_zones'  ] );
        add_action( 'wp_ajax_spc_bunny_fetch_dns_stats',  [ $this, 'ajax_fetch_dns_stats'  ] );
        add_action( 'wp_ajax_spc_bunny_cleanup_perma',    [ $this, 'ajax_cleanup_perma'    ] );
        add_filter( 'plugin_action_links_' . plugin_basename( SPC_BUNNY_FILE ), [ $this, 'action_links' ] );
    }

    public function add_menu(): void {
        add_options_page( 'SPC Bunny Connector', 'SPC Bunny', 'manage_options', self::MENU, [ $this, 'render_page' ] );
    }

    public function action_links( array $links ): array {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU ) ) . '">' . __( 'Settings', 'spc-bunny' ) . '</a>' );
        return $links;
    }

    public function register_settings(): void {
        register_setting( 'spc_bunny_group', self::OPT, [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
    }

    public function sanitize( mixed $raw ): array {
        $raw = is_array( $raw ) ? $raw : [];
        return [
            'api_key'               => sanitize_text_field( $raw['api_key']       ?? '' ),
            'pull_zone_id'          => sanitize_text_field( $raw['pull_zone_id']  ?? '' ),
            'pull_zone_name'        => sanitize_text_field( $raw['pull_zone_name'] ?? '' ),
            'trigger_post_save'     => ! empty( $raw['trigger_post_save'] ),
            'trigger_plugin_update' => ! empty( $raw['trigger_plugin_update'] ),
            'trigger_spc'           => ! empty( $raw['trigger_spc'] ),
            'enable_logging'        => ! empty( $raw['enable_logging'] ),
            'enable_warmer'         => ! empty( $raw['enable_warmer'] ),
            'post_types'            => array_map( 'sanitize_key', (array) ( $raw['post_types'] ?? [ 'post', 'page' ] ) ),
            'warmer_batch_size'     => max( 1, min( 50, (int) ( $raw['warmer_batch_size'] ?? 5 ) ) ),
            'warmer_batch_delay'    => max( 5, min( 300, (int) ( $raw['warmer_batch_delay'] ?? 30 ) ) ),
            'enable_admin_bar'      => ! empty( $raw['enable_admin_bar'] ),
            'dns_zone_id'           => sanitize_text_field( $raw['dns_zone_id']           ?? '' ),
            'custom_bypass_paths'   => sanitize_textarea_field( $raw['custom_bypass_paths']   ?? '' ),
            'enabled_rules'         => is_array( $raw['enabled_rules'] ?? null ) ? array_map( 'sanitize_key', $raw['enabled_rules'] ) : null,
            'perma_cache_enabled'       => ! empty( $raw['perma_cache_enabled'] ),
            'perma_cache_zone_name'     => sanitize_text_field( $raw['perma_cache_zone_name']     ?? '' ),
            'perma_cache_zone_password' => sanitize_text_field( $raw['perma_cache_zone_password'] ?? '' ),
            'perma_cache_region'        => sanitize_key(        $raw['perma_cache_region']        ?? 'de' ),
            'perma_cache_keep'          => max( 1, min( 5, (int) ( $raw['perma_cache_keep'] ?? 1 ) ) ),
        ];
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_' . self::MENU ) {
            return;
        }
        wp_enqueue_style(  'spc-bunny-admin', SPC_BUNNY_URL . 'assets/admin.css', [], SPC_BUNNY_VERSION );
        wp_enqueue_script( 'spc-bunny-admin', SPC_BUNNY_URL . 'assets/admin.js',  [ 'jquery' ], SPC_BUNNY_VERSION, true );
        wp_localize_script( 'spc-bunny-admin', 'spcBunny', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'noncePurge'    => wp_create_nonce( 'spc_bunny_manual_purge' ),
            'nonceBarPurge' => wp_create_nonce( 'spc_bunny_admin_bar_purge' ),
            'nonceSync'     => wp_create_nonce( 'spc_bunny_fetch_stats' ),
            'nonceTest'     => wp_create_nonce( 'spc_bunny_test_connection' ),
            'nonceZones'    => wp_create_nonce( 'spc_bunny_fetch_zones' ),
            'nonceStats'    => wp_create_nonce( 'spc_bunny_fetch_stats' ),
            'nonceWarm'     => wp_create_nonce( 'spc_bunny_warm_now' ),
            'nonceDeploy'   => wp_create_nonce( 'spc_bunny_deploy_rules' ),
            'nonceRemove'   => wp_create_nonce( 'spc_bunny_remove_rules' ),
            'i18n'          => [
                'testing'       => __( 'Testing...', 'spc-bunny' ),
                'testOk'        => __( 'Connected', 'spc-bunny' ),
                'loading'       => __( 'Loading...', 'spc-bunny' ),
                'selectZone'    => __( '— Select a Pull Zone —', 'spc-bunny' ),
                'purging'       => __( 'Purging...', 'spc-bunny' ),
                'purgeOk'       => __( 'Cache purged', 'spc-bunny' ),
                'deploying'     => __( 'Deploying...', 'spc-bunny' ),
                'deployOk'      => __( 'Edge rules deployed', 'spc-bunny' ),
                'removing'      => __( 'Removing...', 'spc-bunny' ),
                'removeOk'      => __( 'Edge rules removed', 'spc-bunny' ),
                'confirmRemove' => __( 'Remove all SPC Bunny edge rules from Bunny CDN?', 'spc-bunny' ),
                'error'         => __( 'Error: ', 'spc-bunny' ),
            ],
        ] );
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajax_test(): void {
        check_ajax_referer( 'spc_bunny_test_connection', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $api   = new SPC_Bunny_API();
        $zones = $api->get_pull_zones();
        if ( is_wp_error( $zones ) ) { wp_send_json_error( [ 'message' => $zones->get_error_message() ] ); return; }
        wp_send_json_success( [ 'message' => sprintf( _n( '%d pull zone found', '%d pull zones found', count( $zones ), 'spc-bunny' ), count( $zones ) ) ] );
    }

    public function ajax_zones(): void {
        check_ajax_referer( 'spc_bunny_fetch_zones', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $api   = new SPC_Bunny_API();
        $zones = $api->get_pull_zones();
        if ( is_wp_error( $zones ) ) { wp_send_json_error( [ 'message' => $zones->get_error_message() ] ); return; }
        $out = [];
        foreach ( $zones as $z ) {
            $hostnames = [];
            foreach ( $z['Hostnames'] ?? [] as $h ) {
                $hostnames[] = $h['Value'] ?? '';
            }
            $out[] = [ 'id' => $z['Id'], 'name' => $z['Name'], 'hostnames' => $hostnames ];
        }
        wp_send_json_success( $out );
    }

    public function ajax_stats(): void {
        check_ajax_referer( 'spc_bunny_fetch_stats', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $days   = absint( $_POST['days'] ?? 7 );
        $stats  = ( new SPC_Bunny_Stats() )->get( $days );
        $health = ( new SPC_Bunny_Stats() )->get_cache_status();
        if ( is_wp_error( $stats ) ) { wp_send_json_error( [ 'message' => $stats->get_error_message() ] ); return; }
        $bunny_last = get_option( 'spc_bunny_last_purge', '' );
        $spc_last   = get_option( 'spc_bunny_spc_last_purge', '' );
        $in_sync    = $bunny_last && $spc_last && ( abs( strtotime( $bunny_last ) - strtotime( $spc_last ) ) <= 10 );
        wp_send_json_success( [
            'stats'      => $stats,
            'health'     => $health,
            'bunny_last' => $bunny_last,
            'spc_last'   => $spc_last,
            'in_sync'    => $in_sync,
        ] );
    }

    public function ajax_sync_status(): void {
        check_ajax_referer( 'spc_bunny_fetch_stats', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $bunny_last = get_option( 'spc_bunny_last_purge', '' );
        $spc_last   = get_option( 'spc_bunny_spc_last_purge', '' );
        $in_sync    = $bunny_last && $spc_last && ( abs( strtotime( $bunny_last ) - strtotime( $spc_last ) ) <= 10 );
        wp_send_json_success( [
            'bunny_last' => $bunny_last,
            'spc_last'   => $spc_last,
            'in_sync'    => $in_sync,
        ] );
    }

    public function ajax_test_perma_cache(): void {
        check_ajax_referer( 'spc_bunny_manual_purge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $zone_name = sanitize_text_field( $_POST['zone_name']     ?? '' );
        $zone_pass = sanitize_text_field( $_POST['zone_password'] ?? '' );
        $region    = sanitize_key(        $_POST['region']        ?? 'de' );
        if ( ! $zone_name || ! $zone_pass ) {
            wp_send_json_error( [ 'message' => __( 'Storage zone name and password are required.', 'spc-bunny' ) ] );
            return;
        }
        $pc     = new SPC_Bunny_Perma_Cache( $zone_name, $zone_pass, $region );
        $result = $pc->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }
        wp_send_json_success( [
            'message' => sprintf(
                __( 'Connected. Found %d Perma-Cache folder(s): %s', 'spc-bunny' ),
                $result['folders_found'],
                implode( ', ', $result['folders'] ) ?: __( 'none yet', 'spc-bunny' )
            ),
        ] );
    }

    public function ajax_cleanup_perma(): void {
        check_ajax_referer( 'spc_bunny_manual_purge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $zone_name = sanitize_text_field( $_POST['zone_name']     ?? '' );
        $zone_pass = sanitize_text_field( $_POST['zone_password'] ?? '' );
        $region    = sanitize_key(        $_POST['region']        ?? 'de' );
        $keep      = max( 1, min( 5, (int) ( $_POST['keep'] ?? 1 ) ) );
        if ( ! $zone_name || ! $zone_pass ) {
            wp_send_json_error( [ 'message' => __( 'Storage zone name and password are required.', 'spc-bunny' ) ] );
            return;
        }
        $pc      = new SPC_Bunny_Perma_Cache( $zone_name, $zone_pass, $region );
        $results = $pc->cleanup( $keep );
        $deleted = count( $results['deleted'] );
        $errors  = $results['errors'];
        if ( $deleted === 0 && empty( $errors ) ) {
            wp_send_json_success( [ 'message' => __( 'Nothing to clean up — only the active folder exists.', 'spc-bunny' ) ] );
            return;
        }
        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );
            return;
        }
        wp_send_json_success( [
            'message' => sprintf(
                __( 'Deleted %d old Perma-Cache folder(s). Kept: %s', 'spc-bunny' ),
                $deleted,
                implode( ', ', $results['kept'] )
            ),
        ] );
    }

    public function ajax_fetch_dns_zones(): void {
        check_ajax_referer( 'spc_bunny_fetch_stats', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $api    = new SPC_Bunny_API();
        $zones  = $api->get_dns_zones();
        if ( is_wp_error( $zones ) ) { wp_send_json_error( [ 'message' => $zones->get_error_message() ] ); return; }
        $out = [];
        foreach ( $zones as $z ) {
            $out[] = [ 'id' => (string) $z['Id'], 'domain' => $z['Domain'] ?? '' ];
        }
        wp_send_json_success( $out );
    }

    public function ajax_fetch_dns_stats(): void {
        check_ajax_referer( 'spc_bunny_fetch_stats', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $zone_id = sanitize_text_field( $_POST['zone_id'] ?? '' );
        $days    = absint( $_POST['days'] ?? 7 );
        if ( ! $zone_id ) { wp_send_json_error( [ 'message' => 'No DNS zone selected.' ] ); return; }
        $api   = new SPC_Bunny_API();
        $raw   = $api->get_dns_stats( $zone_id, $days );
        if ( is_wp_error( $raw ) ) { wp_send_json_error( [ 'message' => $raw->get_error_message() ] ); return; }
        // Sum the query chart values for totals
        $query_chart  = $raw['QueriesServedChart']  ?? $raw['TotalQueriesServedChart'] ?? [];
        $total_queries = is_array( $query_chart ) ? (int) array_sum( $query_chart ) : (int) ( $raw['TotalQueriesServed'] ?? 0 );
        wp_send_json_success( [
            'total_queries' => number_format( $total_queries ),
            'raw'           => $raw,
        ] );
    }

    public function ajax_warm(): void {
        check_ajax_referer( 'spc_bunny_warm_now', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        SPC_Bunny_Warmer::schedule( 0 );
        wp_send_json_success( [ 'message' => sprintf( __( 'Warming started — %d URLs queued.', 'spc-bunny' ), SPC_Bunny_Warmer::get_queue_count() ) ] );
    }

    public function ajax_deploy(): void {
        check_ajax_referer( 'spc_bunny_deploy_rules', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $ttl         = absint( $_POST['ttl']         ?? 604800 );
        $force_cache = ! empty( $_POST['force_cache'] );
        update_option( 'spc_bunny_edge_ttl',    $ttl,         false );
        update_option( 'spc_bunny_force_cache', $force_cache, false );
        // Save custom bypass paths into settings so deploy() can read them
        $settings = get_option( 'spc_bunny_settings', [] );
        $settings['custom_bypass_paths'] = sanitize_textarea_field( $_POST['custom_bypass_paths'] ?? '' );
        $raw_enabled = $_POST['enabled_rules'] ?? null;
        $settings['enabled_rules'] = is_array( $raw_enabled ) ? array_map( 'sanitize_key', $raw_enabled ) : null;
        update_option( 'spc_bunny_settings', $settings, false );
        $result = ( new SPC_Bunny_Edge_Rules() )->deploy( $ttl, $force_cache );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_remove(): void {
        check_ajax_referer( 'spc_bunny_remove_rules', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); return; }
        $result = ( new SPC_Bunny_Edge_Rules() )->remove_all();
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    // ── Page ─────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $opts        = get_option( self::OPT, [] );
        $api         = new SPC_Bunny_API();
        $er          = new SPC_Bunny_Edge_Rules();
        $log         = get_option( 'spc_bunny_purge_log', [] );
        $deployed    = $er->get_deployed_guids();
        $is_deployed = ! empty( $deployed );
        $saved_ttl   = (int) get_option( 'spc_bunny_edge_ttl', 604800 );
        $force_cache = (bool) get_option( 'spc_bunny_force_cache', false );
        $health      = ( new SPC_Bunny_Stats() )->get_cache_status();
        ?>
        <div class="wrap spc-bunny-wrap">

            <div class="spc-bunny-header">
                <div class="spc-bunny-header__logo">
                    <span class="spc-bunny-header__icon">&#9889;</span>
                    <div>
                        <h1>SPC Bunny Connector</h1>
                        <p><?php esc_html_e( 'Syncs Super Page Cache with Bunny.net CDN', 'spc-bunny' ); ?></p>
                    </div>
                </div>
                <div class="spc-bunny-badge <?php echo $api->is_configured() ? 'is-ok' : 'is-warn'; ?>">
                    <?php echo $api->is_configured() ? esc_html__( 'Configured', 'spc-bunny' ) : esc_html__( 'Not configured', 'spc-bunny' ); ?>
                </div>
            </div>

            <nav class="spc-bunny-tabs" role="tablist">
                <a href="#tab-stats"     class="spc-bunny-tab is-active"><?php esc_html_e( 'Stats', 'spc-bunny' ); ?></a>
                <a href="#tab-settings"  class="spc-bunny-tab"><?php esc_html_e( 'Settings', 'spc-bunny' ); ?></a>
                <a href="#tab-edgerules" class="spc-bunny-tab"><?php esc_html_e( 'Edge Rules', 'spc-bunny' ); ?></a>
                <a href="#tab-purge"     class="spc-bunny-tab"><?php esc_html_e( 'Manual Purge', 'spc-bunny' ); ?></a>
                <a href="#tab-log"       class="spc-bunny-tab"><?php esc_html_e( 'Purge Log', 'spc-bunny' ); ?></a>
                <a href="#tab-dns"       class="spc-bunny-tab"><?php esc_html_e( 'DNS Stats', 'spc-bunny' ); ?></a>
            </nav>

            <?php /* ── STATS ── */ ?>
            <div id="tab-stats" class="spc-bunny-panel">
                <div class="spc-bunny-stats-header">
                    <div class="spc-bunny-health">
                        <span><?php esc_html_e( 'Homepage cache:', 'spc-bunny' ); ?></span>
                        <span class="spc-bunny-health__badge spc-bunny-health--<?php echo esc_attr( $health['status'] ); ?>"><?php echo esc_html( $health['label'] ); ?></span>
                        <?php if ( $health['server'] ) : ?><span class="spc-bunny-health__server"><?php echo esc_html( $health['server'] ); ?></span><?php endif; ?>
                    </div>
                    <div class="spc-bunny-stats-controls">
                        <select id="js-stats-days">
                            <option value="1"><?php esc_html_e( 'Last 24 hours', 'spc-bunny' ); ?></option>
                            <option value="7" selected><?php esc_html_e( 'Last 7 days', 'spc-bunny' ); ?></option>
                            <option value="30"><?php esc_html_e( 'Last 30 days', 'spc-bunny' ); ?></option>
                        </select>
                        <button id="js-refresh-stats" class="button"><?php esc_html_e( 'Refresh', 'spc-bunny' ); ?></button>
                    </div>
                </div>

                <?php if ( ! $api->is_configured() ) : ?>
                    <div class="spc-bunny-notice is-warn"><?php esc_html_e( 'Configure your API key and Pull Zone in Settings to see statistics.', 'spc-bunny' ); ?></div>
                <?php endif; ?>

                <div id="js-stats-grid" class="spc-bunny-stats-grid">
                    <div class="spc-bunny-stat-card" id="stat-hit-rate">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Cache Hit Rate', 'spc-bunny' ); ?></div>
                        <div class="spc-bunny-stat-card__bar"><div class="spc-bunny-stat-card__fill" style="width:0%"></div></div>
                    </div>
                    <div class="spc-bunny-stat-card" id="stat-bandwidth">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Bandwidth', 'spc-bunny' ); ?></div>
                        <div class="spc-bunny-stat-card__sub-row">
                            <span class="spc-bunny-stat-card__sub-item">
                                <span class="spc-bunny-stat-card__dot is-green"></span>
                                <span><?php esc_html_e( 'Cached:', 'spc-bunny' ); ?></span>
                                <strong id="stat-bandwidth-cached">&#8212;</strong>
                            </span>
                            <span class="spc-bunny-stat-card__sub-item">
                                <span class="spc-bunny-stat-card__dot is-muted"></span>
                                <span><?php esc_html_e( 'Uncached:', 'spc-bunny' ); ?></span>
                                <strong id="stat-bandwidth-origin">&#8212;</strong>
                            </span>
                        </div>
                    </div>
                    <div class="spc-bunny-stat-card" id="stat-requests">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Requests Served', 'spc-bunny' ); ?></div>
                        <div class="spc-bunny-stat-card__sub"></div>
                    </div>
                    <div class="spc-bunny-stat-card" id="stat-origin-time">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Avg Origin Response', 'spc-bunny' ); ?></div>
                        <div class="spc-bunny-stat-card__sub"></div>
                    </div>
                </div>
                <div id="js-stats-error" class="spc-bunny-notice is-warn" hidden></div>

                <?php
                $bunny_last  = get_option( 'spc_bunny_last_purge', '' );
                $spc_last    = get_option( 'spc_bunny_spc_last_purge', '' );
                $both_set    = $bunny_last && $spc_last;
                $in_sync     = $both_set && ( abs( strtotime( $bunny_last ) - strtotime( $spc_last ) ) <= 10 );
                ?>
                <div class="spc-bunny-card spc-bunny-sync-card">
                    <h2><?php esc_html_e( 'Cache Sync Status', 'spc-bunny' ); ?></h2>
                    <div class="spc-bunny-sync-grid">
                        <div class="spc-bunny-sync-item">
                            <span class="spc-bunny-sync-item__icon">&#9889;</span>
                            <div>
                                <strong><?php esc_html_e( 'Bunny CDN last cleared', 'spc-bunny' ); ?></strong>
                                <span class="spc-bunny-sync-item__time" id="spc-sync-bunny-time"><?php echo $bunny_last ? esc_html( $bunny_last ) : esc_html__( 'Never', 'spc-bunny' ); ?></span>
                            </div>
                        </div>
                        <div class="spc-bunny-sync-item">
                            <span class="spc-bunny-sync-item__icon">&#128266;</span>
                            <div>
                                <strong><?php esc_html_e( 'Super Page Cache last cleared', 'spc-bunny' ); ?></strong>
                                <span class="spc-bunny-sync-item__time" id="spc-sync-spc-time"><?php echo $spc_last ? esc_html( $spc_last ) : esc_html__( 'Never (or SPC trigger not enabled)', 'spc-bunny' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if ( $both_set ) : ?>
                        <div class="spc-bunny-sync-status <?php echo $in_sync ? 'is-synced' : 'is-drifted'; ?>" id="spc-sync-status">
                            <?php if ( $in_sync ) : ?>
                                &#10003; <?php esc_html_e( 'Both caches cleared together — in sync.', 'spc-bunny' ); ?>
                            <?php else : ?>
                                &#9888; <?php esc_html_e( 'Caches cleared at different times — they may be out of sync.', 'spc-bunny' ); ?>
                            <?php endif; ?>
                        </div>
                    <?php elseif ( ! $spc_last ) : ?>
                        <p class="description" style="margin-top:8px"><?php esc_html_e( 'SPC sync time will appear once you enable "Super Page Cache clear events" in Settings and press SPC purge button.', 'spc-bunny' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Cache Warmer', 'spc-bunny' ); ?></h2>
                    <p><?php esc_html_e( 'After a full purge, the warmer crawls your sitemap to pre-populate Bunny\'s edge cache so visitors get a HIT immediately.', 'spc-bunny' ); ?></p>
                    <?php if ( SPC_Bunny_Warmer::is_warming() ) : ?>
                        <div class="spc-bunny-notice is-warn"><?php printf( esc_html__( 'Warming in progress — %d URLs remaining.', 'spc-bunny' ), SPC_Bunny_Warmer::get_queue_count() ); ?></div>
                    <?php endif; ?>
                    <div class="spc-bunny-actions">
                        <button id="js-warm-now" class="button" <?php disabled( ! $api->is_configured() ); ?>><?php esc_html_e( 'Warm Cache Now', 'spc-bunny' ); ?></button>
                        <span id="js-warm-result" class="spc-bunny-inline" aria-live="polite"></span>
                    </div>
                    <p class="description" style="margin-top:8px"><?php esc_html_e( 'Enable auto-warm in Settings → Advanced to run automatically after every full purge.', 'spc-bunny' ); ?></p>
                </div>
            </div>

            <?php /* ── SETTINGS ── */ ?>
            <div id="tab-settings" class="spc-bunny-panel" hidden>
                <form method="post" action="options.php">
                    <?php settings_fields( 'spc_bunny_group' ); ?>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Bunny.net API', 'spc-bunny' ); ?></h2>
                        <div class="spc-bunny-field">
                            <label for="spc_api_key"><?php esc_html_e( 'Account API Key', 'spc-bunny' ); ?></label>
                            <div class="spc-bunny-row">
                                <input type="password" id="spc_api_key" name="<?php echo esc_attr( self::OPT ); ?>[api_key]" value="<?php echo esc_attr( $opts['api_key'] ?? '' ); ?>" class="regular-text" autocomplete="off" />
                                <button type="button" id="js-test" class="button"><?php esc_html_e( 'Test Connection', 'spc-bunny' ); ?></button>
                                <span id="js-test-result" class="spc-bunny-inline" aria-live="polite"></span>
                            </div>
                        </div>
                        <div class="spc-bunny-field">
                            <label for="spc_pull_zone"><?php esc_html_e( 'Pull Zone', 'spc-bunny' ); ?></label>
                            <div class="spc-bunny-row">
                                <select id="spc_pull_zone" name="<?php echo esc_attr( self::OPT ); ?>[pull_zone_id]" class="regular-text">
                                    <option value=""><?php esc_html_e( '— Select a Pull Zone —', 'spc-bunny' ); ?></option>
                                    <?php if ( ! empty( $opts['pull_zone_id'] ) ) : ?>
                                        <option value="<?php echo esc_attr( $opts['pull_zone_id'] ); ?>" selected><?php echo esc_html( $opts['pull_zone_name'] ?? 'Zone #' . $opts['pull_zone_id'] ); ?></option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" id="js-load-zones" class="button"><?php esc_html_e( 'Load Zones', 'spc-bunny' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Purge Triggers', 'spc-bunny' ); ?></h2>
                        <?php $this->cb( 'trigger_post_save',     $opts, __( 'Post publish / update', 'spc-bunny' ),             __( 'Full CDN purge when a post/page is published, updated, or deleted (including Bricks Builder saves).', 'spc-bunny' ) ); ?>
                        <?php $this->cb( 'trigger_plugin_update', $opts, __( 'Plugin / theme / core update', 'spc-bunny' ),      __( 'Full CDN purge when plugins, themes, or WordPress core are updated.', 'spc-bunny' ) ); ?>
                        <?php $this->cb( 'trigger_spc',           $opts, __( 'Super Page Cache clear events', 'spc-bunny' ),     __( 'Full CDN purge whenever SPC clears its cache — purge button, post updates, and scheduled purges.', 'spc-bunny' ) ); ?>

                    </div>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Post Types', 'spc-bunny' ); ?></h2>
                        <div class="spc-bunny-checkboxes">
                            <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) : $watched = $opts['post_types'] ?? [ 'post', 'page' ]; ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $watched, true ) ); ?> />
                                    <?php echo esc_html( $type->label ); ?> <code><?php echo esc_html( $type->name ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Advanced', 'spc-bunny' ); ?></h2>
                        <?php $this->cb( 'enable_admin_bar', $opts, __( 'Show Purge Bunny button in admin toolbar', 'spc-bunny' ), __( 'Adds a Purge Bunny button to the WordPress admin toolbar. Script is only loaded on this settings page.', 'spc-bunny' ) ); ?>
                        <?php $this->cb( 'enable_logging',   $opts, __( 'Enable debug logging', 'spc-bunny' ),                    __( 'Write purge events and API errors to PHP error_log.', 'spc-bunny' ) ); ?>
                        <?php $this->cb( 'enable_warmer',    $opts, __( 'Auto-warm cache after full purge', 'spc-bunny' ),         __( 'Crawls your sitemap via background cron after every full CDN purge. Batch size and delay configurable below.', 'spc-bunny' ) ); ?>

                        <div class="spc-bunny-warmer-settings" style="margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f1">
                            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end">
                                <div class="spc-bunny-field" style="margin:0">
                                    <label for="spc_warmer_batch_size"><?php esc_html_e( 'URLs per batch', 'spc-bunny' ); ?></label>
                                    <input type="number" id="spc_warmer_batch_size"
                                        name="<?php echo esc_attr( self::OPT ); ?>[warmer_batch_size]"
                                        value="<?php echo esc_attr( $opts['warmer_batch_size'] ?? 5 ); ?>"
                                        min="1" max="50" style="width:80px" />
                                    <p class="description"><?php esc_html_e( '1–50. Higher = faster warm but more origin load.', 'spc-bunny' ); ?></p>
                                </div>
                                <div class="spc-bunny-field" style="margin:0">
                                    <label for="spc_warmer_batch_delay"><?php esc_html_e( 'Delay between batches (seconds)', 'spc-bunny' ); ?></label>
                                    <input type="number" id="spc_warmer_batch_delay"
                                        name="<?php echo esc_attr( self::OPT ); ?>[warmer_batch_delay]"
                                        value="<?php echo esc_attr( $opts['warmer_batch_delay'] ?? 30 ); ?>"
                                        min="5" max="300" style="width:80px" />
                                    <p class="description"><?php esc_html_e( '5–300s. Lower = faster warm overall.', 'spc-bunny' ); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Perma-Cache Cleanup', 'spc-bunny' ); ?></h2>
                        <p><?php esc_html_e( 'When Bunny does a full Pull Zone purge, existing Perma-Cache files are NOT deleted — Bunny switches to a new directory and the old one accumulates, costing storage. Enable this to automatically delete old Perma-Cache directories after every purge.', 'spc-bunny' ); ?></p>
                        <?php $this->cb( 'perma_cache_enabled', $opts, __( 'Enable Perma-Cache cleanup after every full purge', 'spc-bunny' ), '' ); ?>

                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f1">
                            <div class="spc-bunny-field">
                                <label for="spc_perma_zone_name"><?php esc_html_e( 'Storage Zone Name', 'spc-bunny' ); ?></label>
                                <input type="text" id="spc_perma_zone_name"
                                    name="<?php echo esc_attr( self::OPT ); ?>[perma_cache_zone_name]"
                                    value="<?php echo esc_attr( $opts['perma_cache_zone_name'] ?? '' ); ?>"
                                    class="regular-text" placeholder="my-storage-zone" />
                                <p class="description"><?php esc_html_e( 'Name of the storage zone connected to Perma-Cache on your Pull Zone.', 'spc-bunny' ); ?></p>
                            </div>
                            <div class="spc-bunny-field">
                                <label for="spc_perma_zone_password"><?php esc_html_e( 'Storage Zone Password', 'spc-bunny' ); ?></label>
                                <input type="password" id="spc_perma_zone_password"
                                    name="<?php echo esc_attr( self::OPT ); ?>[perma_cache_zone_password]"
                                    value="<?php echo esc_attr( $opts['perma_cache_zone_password'] ?? '' ); ?>"
                                    class="regular-text" autocomplete="off" />
                                <p class="description"><?php esc_html_e( 'Found in the storage zone under FTP & API Access. This is the zone password, NOT your account API key.', 'spc-bunny' ); ?></p>
                            </div>
                            <div class="spc-bunny-field">
                                <label for="spc_perma_region"><?php esc_html_e( 'Storage Region', 'spc-bunny' ); ?></label>
                                <select id="spc_perma_region" name="<?php echo esc_attr( self::OPT ); ?>[perma_cache_region]">
                                    <?php foreach ( SPC_Bunny_Perma_Cache::REGIONS as $code => $host ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $opts['perma_cache_region'] ?? 'de', $code ); ?>>
                                            <?php echo esc_html( strtoupper( $code ) . ' — ' . $host ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Primary region of your storage zone. Check the storage zone settings page.', 'spc-bunny' ); ?></p>
                            </div>
                            <div class="spc-bunny-field">
                                <label for="spc_perma_keep"><?php esc_html_e( 'Directories to keep', 'spc-bunny' ); ?></label>
                                <input type="number" id="spc_perma_keep"
                                    name="<?php echo esc_attr( self::OPT ); ?>[perma_cache_keep]"
                                    value="<?php echo esc_attr( $opts['perma_cache_keep'] ?? 1 ); ?>"
                                    min="1" max="5" style="width:60px" />
                                <p class="description"><?php esc_html_e( '1 = keep only the current active directory. Anything older is deleted.', 'spc-bunny' ); ?></p>
                            </div>
                            <div class="spc-bunny-actions" style="margin-top:12px;gap:8px;flex-wrap:wrap">
                                <button type="button" id="js-test-perma" class="button"><?php esc_html_e( 'Test Connection', 'spc-bunny' ); ?></button>
                                <button type="button" id="js-cleanup-perma" class="button"><?php esc_html_e( 'Run Cleanup Now', 'spc-bunny' ); ?></button>
                                <span id="js-perma-result" class="spc-bunny-inline" aria-live="polite"></span>
                            </div>
                        </div>
                    </div>

                    <div class="spc-bunny-card">
                        <h2><?php esc_html_e( 'Bunny DNS', 'spc-bunny' ); ?></h2>
                        <p><?php esc_html_e( 'Optional. Select your Bunny DNS zone to enable the DNS Stats tab. Leave blank if you are not using Bunny DNS.', 'spc-bunny' ); ?></p>
                        <div class="spc-bunny-field">
                            <label for="spc_dns_zone_id"><?php esc_html_e( 'DNS Zone ID', 'spc-bunny' ); ?></label>
                            <div class="spc-bunny-row">
                                <input type="text" id="spc_dns_zone_id"
                                    name="<?php echo esc_attr( self::OPT ); ?>[dns_zone_id]"
                                    value="<?php echo esc_attr( $opts['dns_zone_id'] ?? '' ); ?>"
                                    class="regular-text" placeholder="12345678" />
                                <button type="button" id="js-load-dns-zones-settings" class="button"><?php esc_html_e( 'Find My Zone ID', 'spc-bunny' ); ?></button>
                                <span id="js-dns-zone-result" class="spc-bunny-inline" aria-live="polite"></span>
                            </div>
                            <p class="description"><?php esc_html_e( 'Numeric DNS Zone ID — click Find My Zone ID to look it up, or find it in your Bunny DNS dashboard URL.', 'spc-bunny' ); ?></p>
                            <div id="js-dns-zone-list" style="display:none;margin-top:8px">
                                <select id="js-dns-zone-picker" class="regular-text">
                                    <option value=""><?php esc_html_e( '— Select a zone —', 'spc-bunny' ); ?></option>
                                </select>
                                <button type="button" id="js-dns-zone-use" class="button" style="margin-left:6px"><?php esc_html_e( 'Use This Zone', 'spc-bunny' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <?php submit_button( __( 'Save Settings', 'spc-bunny' ) ); ?>
                </form>
            </div>

            <?php /* ── EDGE RULES ── */ ?>
            <div id="tab-edgerules" class="spc-bunny-panel" hidden>
                <div class="spc-bunny-card">
                    <h2>
                        <?php esc_html_e( 'HTML Caching Edge Rules', 'spc-bunny' ); ?>
                        <span class="spc-bunny-badge <?php echo $is_deployed ? 'is-ok' : 'is-neutral'; ?>"><?php echo $is_deployed ? esc_html__( 'Deployed', 'spc-bunny' ) : esc_html__( 'Not deployed', 'spc-bunny' ); ?></span>
                    </h2>
                    <p><?php esc_html_e( 'Deploys Edge Rules to your Pull Zone and disables Smart Cache — enabling full HTML caching at the CDN edge.', 'spc-bunny' ); ?></p>
                    <?php if ( ! $api->is_configured() ) : ?>
                        <div class="spc-bunny-notice is-warn"><?php esc_html_e( 'Configure your API key and Pull Zone in Settings first.', 'spc-bunny' ); ?></div>
                    <?php endif; ?>

                    <div class="spc-bunny-rules-list">
                        <?php
                        $rules = [
                            SPC_Bunny_Edge_Rules::RULE_FORCE_SSL            => [ '&#128274;', __( '1.  Force SSL',                              'spc-bunny' ), __( 'Redirects HTTP to HTTPS at the CDN edge. Faster than origin redirect.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_DISABLE_SHIELD_ADMIN => [ '&#128737;', __( '2.  Disable Shield & WAF: WP admin',          'spc-bunny' ), __( 'Bypasses Shield and WAF for /wp-admin/*. wp-login.php keeps Shield active.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_LOGGED_IN     => [ '&#128100;', __( '3.  Bypass cache: logged-in users',           'spc-bunny' ), __( 'Skips cache for wordpress_logged_in_*, comment_author_*, wp-postpass_* cookies.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_ADMIN         => [ '&#128296;', __( '4.  Bypass cache: WP admin & PHP',            'spc-bunny' ), __( 'No caching for /wp-admin/* and *.php requests.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_ADMIN_BYPASS_PC      => [ '&#9888;',   __( '5.  Bypass Perma-Cache: WP admin & PHP',      'spc-bunny' ), __( 'Admin and PHP responses must never be permanently stored in Perma-Cache.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_ADMIN_DISABLE_OPT    => [ '&#128683;', __( '6.  Disable Optimizer: WP admin & login',     'spc-bunny' ), __( 'Prevents Bunny Optimizer mangling admin and login page assets.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_CRON          => [ '&#128336;', __( '7.  Bypass cache: wp-cron.php',               'spc-bunny' ), __( 'WP cron must always hit origin. Never cache or Shield-challenge it.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_REST          => [ '&#128279;', __( '8.  Bypass cache: REST API',                  'spc-bunny' ), __( '/wp-json/* responses are dynamic and must never be cached.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_FEEDS         => [ '&#128240;', __( '9.  Bypass cache: RSS/Atom feeds',            'spc-bunny' ), __( 'Feeds change with every post. Always serve fresh to aggregators.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_WOO           => [ '&#128722;', __( '10. Bypass cache: WooCommerce pages',         'spc-bunny' ), __( 'Cart, checkout, account, shop — dynamically resolved from WooCommerce settings.', 'spc-bunny' ), class_exists( 'WooCommerce' ) ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_WOO_COOKIE    => [ '&#127811;', __( '11. Bypass cache: WooCommerce cookies',       'spc-bunny' ), __( 'Session-based bypass for woocommerce_cart_hash, items_in_cart, session_*.', 'spc-bunny' ), class_exists( 'WooCommerce' ) ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_SC            => [ '&#128722;', __( '12. Bypass cache: SureCart pages',            'spc-bunny' ), __( 'Checkout, dashboard, order confirmation, shop, cart — from SureCart settings.', 'spc-bunny' ), class_exists( 'SureCart' ) ],
                            SPC_Bunny_Edge_Rules::RULE_BYPASS_SC_COOKIE     => [ '&#127811;', __( '13. Bypass cache: SureCart cookies',          'spc-bunny' ), __( 'Session-based bypass for sc_session*, surecart_*, sc_cart*.', 'spc-bunny' ), class_exists( 'SureCart' ) ],
                            SPC_Bunny_Edge_Rules::RULE_CUSTOM_BYPASS        => [ '&#9998;',   __( '14. Bypass cache: custom URLs',              'spc-bunny' ), __( 'User-defined paths to always serve fresh. Configure in the Custom Exclusions card above.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_CACHE_HTML           => [ '&#9889;',   __( '15. Cache HTML: anonymous visitors',         'spc-bunny' ), __( 'Caches HTTP 200 responses at Bunny edge. TTL controlled by the slider above.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_NO_BROWSER_CACHE     => [ '&#128683;', __( '16. No browser cache: HTML',                 'spc-bunny' ), __( 'Sends Cache-Control: no-store to browsers for HTML. Edge still caches — browsers always fetch fresh after purge.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_STATIC_BROWSER_CACHE => [ '&#128190;', __( '17. Long browser cache: static assets',      'spc-bunny' ), __( 'Sets 1-year browser cache for CSS, JS, images and fonts. Complements rule 16.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_STATIC_IGNORE_QS     => [ '&#128257;', __( '18. Ignore query string: CSS & JS',          'spc-bunny' ), __( '?ver=x params share one cache entry. Eliminates redundant misses from WordPress version params.', 'spc-bunny' ), true ],
                            SPC_Bunny_Edge_Rules::RULE_SECURITY_HEADERS     => [ '&#128421;', __( '19. Security headers',                       'spc-bunny' ), __( 'Adds X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection on all responses.', 'spc-bunny' ), true ],
                        ];
                        $enabled_rules_setting = $opts['enabled_rules'] ?? null;
                        foreach ( $rules as $key => [ $icon, $title, $desc, $show ] ) :
                            if ( ! $show ) { continue; }
                            $active      = isset( $deployed[ $key ] ) || isset( $deployed[ $key . '_1' ] );
                            $rule_key_str = esc_attr( $key );
                            // Enabled if setting is null (default all on) or key is in the array
                            $rule_enabled = $enabled_rules_setting === null || in_array( $key, $enabled_rules_setting, true );
                        ?>
                            <div class="spc-bunny-rule <?php echo $active ? 'is-active' : ''; ?> <?php echo $rule_enabled ? '' : 'is-disabled-rule'; ?>">
                                <span class="spc-bunny-rule__icon"><?php echo $icon; ?></span>
                                <div class="spc-bunny-rule__body">
                                    <strong><?php echo esc_html( $title ); ?></strong>
                                    <p><?php echo esc_html( $desc ); ?></p>
                                    <?php if ( $key === SPC_Bunny_Edge_Rules::RULE_BYPASS_WOO && class_exists( 'WooCommerce' ) ) :
                                        $woo_paths = $er->get_woo_paths();
                                        if ( $woo_paths ) : ?>
                                            <div class="spc-bunny-woo-paths"><?php foreach ( $woo_paths as $p ) : ?><code><?php echo esc_html( $p ); ?></code><?php endforeach; ?></div>
                                        <?php endif; endif; ?>
                                    <?php if ( $key === SPC_Bunny_Edge_Rules::RULE_BYPASS_SC && class_exists( 'SureCart' ) ) :
                                        $sc_paths = $er->get_surecart_paths();
                                        if ( $sc_paths ) : ?>
                                            <div class="spc-bunny-woo-paths"><?php foreach ( $sc_paths as $p ) : ?><code><?php echo esc_html( $p ); ?></code><?php endforeach; ?></div>
                                        <?php endif; endif; ?>
                                </div>
                                <label class="spc-bunny-rule__toggle" title="<?php esc_attr_e( 'Enable/disable this rule', 'spc-bunny' ); ?>">
                                    <input type="checkbox"
                                        name="spc_bunny_rule_enabled[]"
                                        value="<?php echo $rule_key_str; ?>"
                                        <?php checked( $rule_enabled ); ?> />
                                    <span class="spc-bunny-toggle-label"><?php echo $rule_enabled ? esc_html__( 'On', 'spc-bunny' ) : esc_html__( 'Off', 'spc-bunny' ); ?></span>
                                </label>
                                <span class="spc-bunny-pill <?php echo $active ? 'is-ok' : 'is-neutral'; ?>"><?php echo $active ? '&#10003;' : '&mdash;'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Edge Cache TTL', 'spc-bunny' ); ?></h2>
                    <select id="js-ttl">
                        <?php foreach ( [ 3600 => '1 Hour', 86400 => '1 Day', 604800 => '1 Week (recommended)', 2592000 => '30 Days' ] as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_ttl, $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Cache-Control Mode', 'spc-bunny' ); ?></h2>
                    <div class="spc-bunny-cache-mode">
                        <label class="spc-bunny-mode-option <?php echo ! $force_cache ? 'is-selected' : ''; ?>">
                            <input type="radio" name="js-force-cache" value="0" <?php checked( ! $force_cache ); ?> />
                            <div class="spc-bunny-mode-body">
                                <strong><?php esc_html_e( 'Respect Origin Headers', 'spc-bunny' ); ?></strong>
                                <p><?php esc_html_e( 'Bunny obeys the Cache-Control header your server sends. Requires SPC to send a public, cacheable header.', 'spc-bunny' ); ?></p>
                            </div>
                        </label>
                        <label class="spc-bunny-mode-option <?php echo $force_cache ? 'is-selected' : ''; ?>">
                            <input type="radio" name="js-force-cache" value="1" <?php checked( $force_cache ); ?> />
                            <div class="spc-bunny-mode-body">
                                <strong><?php esc_html_e( 'Force Cache (Override Origin Headers)', 'spc-bunny' ); ?><?php if ( $force_cache ) : ?> <span class="spc-bunny-badge is-ok" style="font-size:10px">Active</span><?php endif; ?></strong>
                                <p><?php esc_html_e( 'Bunny ignores the origin Cache-Control header and caches for the TTL above. Use this if your origin sends no-cache.', 'spc-bunny' ); ?></p>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Pull Zone Settings Applied on Deploy', 'spc-bunny' ); ?></h2>
                    <table class="spc-bunny-settings-table">
                        <tr><td><?php esc_html_e( 'Smart Cache', 'spc-bunny' ); ?></td><td class="spc-err">Disabled</td></tr>
                        <tr><td><?php esc_html_e( 'Strip Response Cookies', 'spc-bunny' ); ?></td><td class="spc-err">Disabled</td></tr>
                        <tr><td><?php esc_html_e( 'Query String Sort', 'spc-bunny' ); ?></td><td class="spc-ok">Enabled</td></tr>
                        <tr><td><?php esc_html_e( 'Cache-Control Override', 'spc-bunny' ); ?></td><td><?php echo $force_cache ? esc_html( "Force {$saved_ttl}s" ) : 'Respect origin'; ?></td></tr>
                        <tr><td><?php esc_html_e( 'Browser Cache', 'spc-bunny' ); ?></td><td>Do Not Cache</td></tr>
                    </table>
                </div>

                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Custom Cache Exclusions', 'spc-bunny' ); ?></h2>
                    <p><?php esc_html_e( 'Enter one URL path per line that should bypass Bunny edge cache entirely. Use this for pages that must always be dynamic, e.g. a WooCommerce /shop/ page if you prefer live inventory counts over cached HTML.', 'spc-bunny' ); ?></p>
                    <div class="spc-bunny-field">
                        <label for="spc_custom_bypass"><?php esc_html_e( 'Bypass these paths', 'spc-bunny' ); ?></label>
                        <textarea id="spc_custom_bypass" name="spc_bunny_custom_bypass_paths"
                            rows="5" class="large-text code"
                            placeholder="/shop/&#10;/my-dynamic-page/&#10;/members/*"><?php echo esc_textarea( $opts['custom_bypass_paths'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One path per line. Wildcards supported: /shop/* bypasses all shop sub-pages. Saved and deployed together with the Deploy button.', 'spc-bunny' ); ?></p>
                    </div>
                </div>

                <div class="spc-bunny-actions">
                    <button id="js-deploy" class="button button-primary" <?php disabled( ! $api->is_configured() ); ?>><?php echo $is_deployed ? esc_html__( 'Update Edge Rules', 'spc-bunny' ) : esc_html__( 'Deploy Edge Rules', 'spc-bunny' ); ?></button>
                    <?php if ( $is_deployed ) : ?><button id="js-remove" class="button spc-bunny-remove-btn"><?php esc_html_e( 'Remove All', 'spc-bunny' ); ?></button><?php endif; ?>
                    <span id="js-deploy-result" class="spc-bunny-inline" aria-live="polite"></span>
                </div>

                <div id="js-deploy-table" hidden class="spc-bunny-card" style="margin-top:12px">
                    <h2><?php esc_html_e( 'Deployment Results', 'spc-bunny' ); ?></h2>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Rule', 'spc-bunny' ); ?></th><th><?php esc_html_e( 'Status', 'spc-bunny' ); ?></th><th><?php esc_html_e( 'Message', 'spc-bunny' ); ?></th></tr></thead><tbody id="js-deploy-rows"></tbody></table>
                </div>
            </div>

            <?php /* ── MANUAL PURGE ── */ ?>
            <div id="tab-purge" class="spc-bunny-panel" hidden>
                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Manual Cache Purge', 'spc-bunny' ); ?></h2>
                    <p><?php esc_html_e( 'Immediately purge all cached HTML from your Bunny Pull Zone.', 'spc-bunny' ); ?></p>
                    <?php if ( ! $api->is_configured() ) : ?>
                        <div class="spc-bunny-notice is-warn"><?php esc_html_e( 'Configure API key and Pull Zone in Settings first.', 'spc-bunny' ); ?></div>
                    <?php endif; ?>
                    <div class="spc-bunny-actions">
                        <button id="js-purge" class="button button-primary" <?php disabled( ! $api->is_configured() ); ?>><?php esc_html_e( 'Purge Entire CDN Cache', 'spc-bunny' ); ?></button>
                        <span id="js-purge-result" class="spc-bunny-inline" aria-live="polite"></span>
                    </div>
                    <?php if ( ! empty( $log[0] ) ) : $last = $log[0]; ?>
                        <p class="spc-bunny-last-purge"><?php esc_html_e( 'Last purge:', 'spc-bunny' ); ?> <strong><?php echo esc_html( $last['time'] ); ?></strong> &mdash; <span class="<?php echo $last['success'] ? 'spc-ok' : 'spc-err'; ?>"><?php echo esc_html( $last['message'] ); ?></span> (<?php echo esc_html( $last['context'] ); ?>)</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ── PURGE LOG ── */ ?>
            <div id="tab-log" class="spc-bunny-panel" hidden>
                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Purge Log', 'spc-bunny' ); ?> <span class="spc-bunny-count"><?php echo count( $log ); ?></span></h2>
                    <?php if ( empty( $log ) ) : ?>
                        <p class="spc-bunny-empty"><?php esc_html_e( 'No purge events recorded yet.', 'spc-bunny' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e( 'Time', 'spc-bunny' ); ?></th><th><?php esc_html_e( 'Context', 'spc-bunny' ); ?></th><th><?php esc_html_e( 'Status', 'spc-bunny' ); ?></th><th><?php esc_html_e( 'Message', 'spc-bunny' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $log as $entry ) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html( $entry['time'] ); ?></code></td>
                                        <td><?php echo esc_html( $entry['context'] ); ?></td>
                                        <td><span class="spc-bunny-pill <?php echo $entry['success'] ? 'is-ok' : 'is-err'; ?>"><?php echo $entry['success'] ? 'OK' : 'Error'; ?></span></td>
                                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ── DNS STATS ── */ ?>
            <div id="tab-dns" class="spc-bunny-panel" hidden>
                <?php $dns_zone_id = $opts['dns_zone_id'] ?? ''; ?>
                <div class="spc-bunny-card">
                    <h2><?php esc_html_e( 'Bunny DNS Statistics', 'spc-bunny' ); ?></h2>
                    <p><?php esc_html_e( 'Query statistics for your Bunny DNS zone. Uses the same account API key as the CDN. Only visible if you use Bunny DNS.', 'spc-bunny' ); ?></p>
                    <div class="spc-bunny-field">
                        <label><?php esc_html_e( 'DNS Zone', 'spc-bunny' ); ?></label>
                        <div class="spc-bunny-row">
                            <select id="spc_dns_zone_select" class="regular-text">
                                <option value=""><?php esc_html_e( '— Load zones first —', 'spc-bunny' ); ?></option>
                                <?php if ( $dns_zone_id ) : ?>
                                    <option value="<?php echo esc_attr( $dns_zone_id ); ?>" selected><?php echo esc_html( $dns_zone_id ); ?></option>
                                <?php endif; ?>
                            </select>
                            <button type="button" id="js-load-dns-zones" class="button"><?php esc_html_e( 'Load My Zones', 'spc-bunny' ); ?></button>
                        </div>
                    </div>
                    <div class="spc-bunny-stats-controls" style="margin-top:12px">
                        <select id="js-dns-days">
                            <option value="1"><?php esc_html_e( 'Last 24 hours', 'spc-bunny' ); ?></option>
                            <option value="7" selected><?php esc_html_e( 'Last 7 days', 'spc-bunny' ); ?></option>
                            <option value="30"><?php esc_html_e( 'Last 30 days', 'spc-bunny' ); ?></option>
                        </select>
                        <button type="button" id="js-load-dns-stats" class="button"><?php esc_html_e( 'Load Stats', 'spc-bunny' ); ?></button>
                        <span id="js-dns-result" class="spc-bunny-inline" aria-live="polite"></span>
                    </div>
                </div>
                <div id="js-dns-stats-grid" class="spc-bunny-stats-grid" hidden>
                    <div class="spc-bunny-stat-card" id="stat-dns-queries">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Total DNS Queries', 'spc-bunny' ); ?></div>
                    </div>
                    <div class="spc-bunny-stat-card" id="stat-dns-cached">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Cached Queries', 'spc-bunny' ); ?></div>
                    </div>
                    <div class="spc-bunny-stat-card" id="stat-dns-latency">
                        <div class="spc-bunny-stat-card__value">&#8212;</div>
                        <div class="spc-bunny-stat-card__label"><?php esc_html_e( 'Avg Response Time', 'spc-bunny' ); ?></div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    private function cb( string $key, array $opts, string $label, string $desc ): void {
        ?>
        <div class="spc-bunny-check-row">
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $opts[ $key ] ) ); ?> />
                <span><?php echo esc_html( $label ); ?></span>
            </label>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        </div>
        <?php
    }
}
