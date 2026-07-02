<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class SPC_Bunny_Hooks {

    private SPC_Bunny_Purge $purge;

    public function __construct() {
        $this->purge = new SPC_Bunny_Purge();
        $this->register();
    }

    private function register(): void {
        $opts = get_option( 'spc_bunny_settings', [] );

        if ( ! empty( $opts['trigger_post_save'] ) ) {
            add_action( 'save_post',              [ $this, 'on_post_save' ],          20, 2 );
            add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 20, 3 );
            add_action( 'deleted_post',           [ $this, 'on_post_delete' ],        20    );

            // REST API saves — Bricks Builder, Gutenberg, all REST-based editors
            add_action( 'rest_after_insert_post', [ $this, 'on_rest_save' ], 20, 1 );
            add_action( 'rest_after_insert_page', [ $this, 'on_rest_save' ], 20, 1 );
            add_action( 'init', [ $this, 'register_cpt_rest_hooks' ], 99 );
        }

        if ( ! empty( $opts['trigger_plugin_update'] ) ) {
            add_action( 'upgrader_process_complete', [ $this, 'on_upgrade' ],  20, 2 );
            add_action( 'switch_theme',              [ $this, 'on_purge_all' ]       );
            add_action( 'activated_plugin',          [ $this, 'on_purge_all' ]       );
            add_action( 'deactivated_plugin',        [ $this, 'on_purge_all' ]       );
        }

        // SPC hooks are registered at file scope in spc-bunny-connector.php
        // before plugins_loaded to guarantee correct load order.

        if ( ! empty( $opts['enable_admin_bar'] ) ) {
            add_action( 'admin_bar_menu', [ $this, 'admin_bar_button' ], 100 );
        }

        add_action( 'wp_ajax_spc_bunny_manual_purge',    [ $this, 'ajax_manual_purge'    ] );
        add_action( 'wp_ajax_spc_bunny_admin_bar_purge', [ $this, 'ajax_admin_bar_purge' ] );
    }

    public function register_cpt_rest_hooks(): void {
        $built_in = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item' ];
        foreach ( get_post_types( [ 'public' => true, 'show_in_rest' => true ] ) as $post_type ) {
            if ( ! in_array( $post_type, $built_in, true ) ) {
                add_action( 'rest_after_insert_' . $post_type, [ $this, 'on_rest_save' ], 20, 1 );
            }
        }
    }

    // ── WP / REST callbacks ───────────────────────────────────────────────────

    public function on_post_save( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        /** @var string[] $watched */
        $watched = (array) apply_filters( 'spc_bunny_watched_post_types', [ 'post', 'page' ] );
        if ( in_array( $post->post_type, $watched, true ) ) {
            $this->purge->purge_post( $post_id );
        }
    }

    public function on_rest_save( WP_Post $post ): void {
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        $this->purge->purge_post( $post->ID );
    }

    public function on_post_status_change( string $new_status, string $old_status, WP_Post $post ): void {
        if ( $old_status === 'publish' && $new_status !== 'publish' ) {
            $this->purge->purge_post( $post->ID );
        }
    }

    public function on_post_delete( int $post_id ): void {
        $post = get_post( $post_id );
        if ( $post instanceof WP_Post && $post->post_status === 'publish' ) {
            $this->purge->purge_post( $post_id );
        }
    }

    public function on_upgrade( WP_Upgrader $upgrader, array $hook_extra ): void {
        if ( isset( $hook_extra['type'] ) && in_array( $hook_extra['type'], [ 'plugin', 'theme', 'core' ], true ) ) {
            $this->purge->purge_all();
        }
    }

    public function on_purge_all(): void {
        $this->purge->purge_all();
    }

    // ── Admin bar ─────────────────────────────────────────────────────────────

    public function admin_bar_button( WP_Admin_Bar $bar ): void {
        if ( ! is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Check configuration without instantiating a full API object
        $opts = get_option( 'spc_bunny_settings', [] );
        if ( empty( $opts['api_key'] ) || empty( $opts['pull_zone_id'] ) ) {
            return;
        }
        $bar->add_node( [
            'id'    => 'spc-bunny-purge',
            'title' => '&#9889; Purge Bunny',
            'href'  => '#',
            'meta'  => [ 'title' => 'Purge Bunny CDN Cache' ],
        ] );
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_manual_purge(): void {
        check_ajax_referer( 'spc_bunny_manual_purge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'spc-bunny' ) ] );
            return;
        }
        $this->purge->purge_all();
        $log  = get_option( 'spc_bunny_purge_log', [] );
        $last = $log[0] ?? null;
        if ( is_array( $last ) && $last['success'] ) {
            wp_send_json_success( [ 'message' => __( 'Cache purged.', 'spc-bunny' ) ] );
        } else {
            wp_send_json_error( [ 'message' => is_array( $last ) ? $last['message'] : __( 'Unknown error.', 'spc-bunny' ) ] );
        }
    }

    public function ajax_admin_bar_purge(): void {
        check_ajax_referer( 'spc_bunny_admin_bar_purge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'spc-bunny' ) ] );
            return;
        }
        $this->purge->purge_all();
        wp_send_json_success();
    }
}
