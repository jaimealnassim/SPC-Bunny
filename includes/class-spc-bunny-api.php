<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class SPC_Bunny_API {

    private const API_BASE  = 'https://api.bunny.net';
    private const ZONE_BASE = 'https://api.bunny.net/pullzone';

    private string $api_key;
    private string $zone_id;
    private bool   $logging;

    public function __construct() {
        $opts          = get_option( 'spc_bunny_settings', [] );
        $this->api_key = (string) ( $opts['api_key']       ?? '' );
        $this->zone_id = (string) ( $opts['pull_zone_id']  ?? '' );
        $this->logging = ! empty( $opts['enable_logging'] );
    }

    public function is_configured(): bool {
        return $this->api_key !== '' && $this->zone_id !== '';
    }

    public function purge_all(): true|WP_Error {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', __( 'API key or Pull Zone ID not set.', 'spc-bunny' ) );
        }
        $result = $this->request( 'POST', self::ZONE_BASE . '/' . $this->zone_id . '/purgeCache' );
        if ( is_wp_error( $result ) ) {
            $this->log( 'purge_all failed: ' . $result->get_error_message() );
            return $result;
        }
        $this->log( 'Full purge OK.' );
        return true;
    }

    public function get_pull_zones(): array|WP_Error {
        if ( $this->api_key === '' ) {
            return new WP_Error( 'no_key', __( 'No API key saved.', 'spc-bunny' ) );
        }
        $result = $this->request( 'GET', self::API_BASE . '/pullzone?page=1&perPage=100' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result['Items'] ?? [];
    }

    public function update_pull_zone( array $settings ): true|WP_Error {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', __( 'API key or Pull Zone ID not set.', 'spc-bunny' ) );
        }
        $result = $this->request( 'POST', self::ZONE_BASE . '/' . $this->zone_id, $settings );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    public function upsert_edge_rule( array $rule, ?string $guid = null ): array|WP_Error {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', __( 'API key or Pull Zone ID not set.', 'spc-bunny' ) );
        }
        if ( $guid !== null ) {
            $rule['Guid'] = $guid;
        }
        return $this->request( 'POST', self::ZONE_BASE . '/' . $this->zone_id . '/edgerules/addOrUpdate', $rule );
    }

    public function delete_edge_rule( string $guid ): true|WP_Error {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', __( 'API key or Pull Zone ID not set.', 'spc-bunny' ) );
        }
        $result = $this->request( 'DELETE', self::ZONE_BASE . '/' . $this->zone_id . '/edgerules/' . $guid );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    public function get_stats( int $days = 7 ): array|WP_Error {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', __( 'API key or Pull Zone ID not set.', 'spc-bunny' ) );
        }
        $url = add_query_arg( [
            'pullZone' => $this->zone_id,
            'dateFrom' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( "-{$days} days" ) ),
            'dateTo'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'hourly'   => 'false',
        ], self::API_BASE . '/statistics' );
        return $this->request( 'GET', $url );
    }

    public function get_dns_zones(): array|WP_Error {
        if ( $this->api_key === '' ) {
            return new WP_Error( 'no_key', __( 'No API key set.', 'spc-bunny' ) );
        }
        $result = $this->request( 'GET', self::API_BASE . '/dnszone?page=1&perPage=100' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result['Items'] ?? [];
    }

    public function get_dns_stats( string $zone_id, int $days = 7 ): array|WP_Error {
        if ( $this->api_key === '' ) {
            return new WP_Error( 'no_key', __( 'No API key set.', 'spc-bunny' ) );
        }
        $url = add_query_arg( [
            'dateFrom' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( "-{$days} days" ) ),
            'dateTo'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'hourly'   => 'false',
        ], self::API_BASE . '/dnszone/' . $zone_id . '/statistics' );
        return $this->request( 'GET', $url );
    }

    private function request( string $method, string $url, array $body = [] ): array|WP_Error {
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'AccessKey'    => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];
        if ( in_array( $method, [ 'POST', 'PUT' ], true ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'api_error', "Bunny API HTTP {$code}: {$raw}" );
        }
        return ! empty( $raw ) ? ( json_decode( $raw, true ) ?? [] ) : [];
    }

    private function log( string $msg ): void {
        if ( $this->logging ) {
            error_log( '[SPC Bunny] ' . $msg );
        }
    }
}
