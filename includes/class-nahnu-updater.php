<?php
/**
 * Nahnu_Updater — Bundled update class for public / standalone plugins.
 *
 * Use this when you want a plugin to self-update WITHOUT the Nahnu Updater
 * plugin installed on the client site (e.g. public or free plugins).
 *
 * USAGE — add these lines to your main plugin file:
 * ─────────────────────────────────────────────────
 *   define( 'NAHNU_UPDATER_WORKER_URL', 'https://your-worker.workers.dev' );
 *   require_once __DIR__ . '/includes/class-nahnu-updater.php';
 *   Nahnu_Updater::register( __FILE__ );        // public plugin (no key)
 *   Nahnu_Updater::register( __FILE__, $key );  // private plugin (with key)
 *
 * The plugin folder name is used as the slug automatically.
 * It must match the slug registered in Nahnu Update Master.
 *
 * NOTE: If the client site has the Nahnu Updater plugin installed, that plugin
 * handles updates automatically via bulk check — you do NOT need this class.
 * This class is only needed for standalone / public plugin distribution.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'NAHNU_UPDATER_CLASS_VERSION' )
	|| version_compare( NAHNU_UPDATER_CLASS_VERSION, '1.2.0', '<' ) ) {

	define( 'NAHNU_UPDATER_CLASS_VERSION', '1.2.0' );

	class Nahnu_Updater {

		private string  $slug;
		private string  $version;
		private string  $file;
		private string  $worker_url;
		private ?string $api_key;
		private string  $cache_key;

		public int $cache_ttl       = 43200;
		public int $error_cache_ttl = 3600;

		// ─── Static factory ───────────────────────────────────────────────

		/**
		 * Register a plugin for self-managed updates.
		 * Uses the plugin folder name as the slug.
		 *
		 * @param string      $plugin_file  Your main plugin file path (__FILE__)
		 * @param string|null $api_key      For private plugins. Null for public.
		 */
		public static function register( string $plugin_file, ?string $api_key = null ): void {
			$worker_url = defined( 'NAHNU_UPDATER_WORKER_URL' ) ? NAHNU_UPDATER_WORKER_URL : '';
			if ( ! $worker_url ) return;

			// Derive slug from plugin folder name — must match registered slug
			$relative = plugin_basename( $plugin_file );
			$parts    = explode( '/', $relative );
			$slug     = count( $parts ) > 1
				? sanitize_key( $parts[0] )
				: sanitize_key( pathinfo( $parts[0], PATHINFO_FILENAME ) );

			if ( ! $slug ) return;

			// Read version from plugin header
			$data    = get_file_data( $plugin_file, [ 'version' => 'Version' ] );
			$version = $data['version'] ?? '0.0.0';

			$key = $api_key ?? ( defined( 'NAHNU_UPDATER_API_KEY' ) ? NAHNU_UPDATER_API_KEY : null );

			new self( [
				'slug'       => $slug,
				'version'    => $version,
				'file'       => $plugin_file,
				'worker_url' => $worker_url,
				'api_key'    => $key ?: null,
			] );
		}

		// ─── Constructor ─────────────────────────────────────────────────

		public function __construct( array $args ) {
			$this->slug       = sanitize_key( $args['slug'] ?? '' );
			$this->version    = $args['version']    ?? '0.0.0';
			$this->file       = $args['file']       ?? '';
			$this->api_key    = $args['api_key']    ?? null;
			$this->worker_url = rtrim( $args['worker_url'] ?? '', '/' );
			$this->cache_key  = 'nahnu_upd_' . md5( $this->slug );

			if ( ! $this->slug || ! $this->file || ! $this->worker_url ) return;

			add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
			add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
			add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ], 10, 2 );
		}

		// ─── Hooks ───────────────────────────────────────────────────────

		public function check_for_update( $transient ) {
			if ( empty( $transient->checked ) ) return $transient;

			$plugin_file = plugin_basename( $this->file );
			$data        = $this->fetch();

			$sv = $data['version'] ?? '';
			if ( $data && $sv && version_compare( $sv, $this->version, '>' ) ) {
				$transient->response[ $plugin_file ] = (object) [
					'slug'         => $this->slug,
					'plugin'       => $plugin_file,
					'new_version'  => $data['version'],
					'url'          => '',
					'package'      => $data['download_url'] ?? '',
					'tested'       => $data['tested']       ?? '',
					'requires'     => $data['requires']     ?? '',
					'requires_php' => $data['requires_php'] ?? '',
				];
			} else {
				$transient->no_update[ $plugin_file ] = (object) [
					'slug'        => $this->slug,
					'plugin'      => $plugin_file,
					'new_version' => $this->version,
					'url'         => '',
					'package'     => '',
				];
			}

			return $transient;
		}

		public function plugin_info( $result, $action, $args ) {
			if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== $this->slug ) {
				return $result;
			}
			$data = $this->fetch();
			if ( ! $data ) return $result;

			return (object) [
				'name'          => $data['name']        ?? $this->slug,
				'slug'          => $this->slug,
				'version'       => $data['version']     ?? $this->version,
				'tested'        => $data['tested']       ?? '',
				'requires'      => $data['requires']     ?? '',
				'requires_php'  => $data['requires_php'] ?? '',
				'last_updated'  => $data['last_updated'] ?? '',
				'sections'      => [ 'changelog' => nl2br( esc_html( $data['changelog'] ?? '' ) ) ],
				'download_link' => $data['download_url'] ?? '',
			];
		}

		public function clear_cache( $upgrader, array $options ): void {
			if (
				( $options['action'] ?? '' ) === 'update' &&
				( $options['type']   ?? '' ) === 'plugin' &&
				in_array( plugin_basename( $this->file ), (array) ( $options['plugins'] ?? [] ), true )
			) {
				delete_transient( $this->cache_key );
			}
		}

		// ─── Fetch ───────────────────────────────────────────────────────

		private function fetch(): ?array {
			$cached = get_transient( $this->cache_key );
			if ( $cached !== false ) return empty( $cached ) ? null : $cached;

			$url  = $this->worker_url . '/api/check/' . rawurlencode( $this->slug );
			$args = [
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => [
					'X-WP-Site' => home_url(),
					...( $this->api_key ? [ 'X-Api-Key' => $this->api_key ] : [] ),
				],
			];

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				set_transient( $this->cache_key, [], $this->error_cache_ttl );
				return null;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 || ! is_array( $body ) || ! empty( $body['no_update'] ) ) {
				set_transient( $this->cache_key, [], $this->cache_ttl );
				return null;
			}

			set_transient( $this->cache_key, $body, $this->cache_ttl );
			return $body;
		}
	}
}
