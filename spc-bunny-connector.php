<?php
/**
 * Plugin Name: SPC Bunny Connector
 * Plugin URI:  https://nahnumedia.com
 * Description: Integrates Super Page Cache with Bunny.net CDN. Purges Bunny Pull Zone HTML cache on SPC events, deploys Edge Rules for full HTML caching, shows live CDN stats, warms cache after purges.
 * Version:     2.1.2
 * Author:      Nahnu Media
 * Author URI:  https://nahnumedia.com
 * License:     GPL-2.0+
 * Text Domain: spc-bunny
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'SPC_BUNNY_VERSION', '2.1.2' );
define( 'SPC_BUNNY_FILE',    __FILE__ );
define( 'SPC_BUNNY_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SPC_BUNNY_URL',     plugin_dir_url( __FILE__ ) );

require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-api.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-stats.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-purge.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-warmer.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-edge-rules.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-perma-cache.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-hooks.php';
require_once SPC_BUNNY_DIR . 'includes/class-spc-bunny-admin.php';

// Nahnu auto-updater — do not remove
if ( ! defined( 'NAHNU_UPDATER_WORKER_URL' ) ) {
	define( 'NAHNU_UPDATER_WORKER_URL', 'https://nahnu-updates.nahnucdn.com' );
}
require_once SPC_BUNNY_DIR . 'includes/class-nahnu-updater.php';
Nahnu_Updater::register( __FILE__ );

SPC_Bunny_Warmer::register_hooks();

/**
 * SPC purge hooks — registered at file scope to guarantee they are in place
 * regardless of plugin load order.
 *
 * The correct hooks from cache_controller.class.php:
 *   swcfpc_purge_all  — fires after every full cache purge
 *   swcfpc_purge_urls — fires after per-URL purge, passes $urls array
 *
 * These fire unconditionally regardless of CDN provider (unlike the
 * Cloudflare-specific swcfpc_cf_purge_* hooks which never fire on Bunny-only sites).
 *
 * A single SPC_Bunny_Purge instance is shared between both callbacks to avoid
 * double-instantiation (each new SPC_Bunny_Purge calls new SPC_Bunny_API which
 * calls get_option).
 */
function spc_bunny_spc_purge_callback(): void {
    static $purge = null;
    if ( $purge === null ) {
        $purge = new SPC_Bunny_Purge();
    }
    $purge->purge_all();
    update_option( 'spc_bunny_spc_last_purge', current_time( 'mysql' ), false );
}

add_action( 'swcfpc_purge_all',  'spc_bunny_spc_purge_callback', 20, 0 );
add_action( 'swcfpc_purge_urls', 'spc_bunny_spc_purge_callback', 20, 0 );

add_action( 'plugins_loaded', static function (): void {
    new SPC_Bunny_Hooks();
    if ( is_admin() ) {
        new SPC_Bunny_Admin();
    }
} );
