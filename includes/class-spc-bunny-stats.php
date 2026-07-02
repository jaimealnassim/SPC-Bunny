<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class SPC_Bunny_Stats {

    private const CACHE_TTL = 300;

    public function get( int $days = 7 ): array|WP_Error {
        $key    = 'spc_bunny_stats_' . $days;
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $api = new SPC_Bunny_API();
        $raw = $api->get_stats( $days );

        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        // CacheHitRate is already a percentage (e.g. 2.95 = 2.95%), not a 0-1 fraction
        $hit_rate = round( (float) ( $raw['CacheHitRate'] ?? 0 ), 1 );
        $bw_total = (int) ( $raw['TotalBandwidthUsed'] ?? 0 );

        // Sum BandwidthCachedChart for accurate cached BW.
        // TotalOriginTraffic is unreliable — can exceed TotalBandwidthUsed.
        $cached_chart = $raw['BandwidthCachedChart'] ?? [];
        $bw_cached    = is_array( $cached_chart ) ? (int) array_sum( $cached_chart ) : 0;
        $bw_uncached  = max( 0, $bw_total - $bw_cached );

        $requests = (int) ( $raw['TotalRequestsServed']       ?? 0 );
        $avg_ms   = (int) ( $raw['AverageOriginResponseTime'] ?? 0 );

        $stats = [
            'hit_rate'      => $hit_rate,
            'bandwidth_fmt' => $this->fmt( $bw_total ),
            'cached_fmt'    => $this->fmt( $bw_cached ),
            'origin_fmt'    => $this->fmt( $bw_uncached ),
            'requests_fmt'  => number_format( $requests ),
            'avg_origin_ms' => $avg_ms,
        ];

        set_transient( $key, $stats, self::CACHE_TTL );
        return $stats;
    }

    public function get_cache_status(): array {
        $key    = 'spc_bunny_health';
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $response = wp_remote_head( home_url( '/' ), [
            'timeout'    => 8,
            'user-agent' => 'SPC-Bunny-Health/1.0',
            'sslverify'  => true,
        ] );

        if ( is_wp_error( $response ) ) {
            $result = [ 'status' => 'unknown', 'label' => 'Unknown', 'server' => '' ];
        } else {
            $cdn    = strtoupper( (string) wp_remote_retrieve_header( $response, 'cdn-cache' ) );
            $server = (string) wp_remote_retrieve_header( $response, 'server' );
            $age    = (int) wp_remote_retrieve_header( $response, 'age' );

            if ( $cdn === 'HIT' ) {
                $result = [ 'status' => 'hit',     'label' => "HIT (age: {$age}s)", 'server' => $server ];
            } elseif ( $cdn === 'MISS' ) {
                $result = [ 'status' => 'miss',    'label' => 'MISS',               'server' => $server ];
            } elseif ( $cdn === 'BYPASS' ) {
                $result = [ 'status' => 'bypass',  'label' => 'BYPASS',             'server' => $server ];
            } elseif ( str_contains( $server, 'BunnyCDN' ) ) {
                $result = [ 'status' => 'partial', 'label' => 'Via Bunny (no cache header)', 'server' => $server ];
            } else {
                $result = [ 'status' => 'none',    'label' => 'Not via Bunny CDN',  'server' => $server ];
            }
        }

        set_transient( $key, $result, 60 );
        return $result;
    }

    public function flush(): void {
        foreach ( [ 1, 7, 30 ] as $d ) {
            delete_transient( 'spc_bunny_stats_' . $d );
        }
        delete_transient( 'spc_bunny_health' );
    }

    private function fmt( int $bytes ): string {
        if ( $bytes >= 1_073_741_824 ) {
            return round( $bytes / 1_073_741_824, 2 ) . ' GB';
        }
        if ( $bytes >= 1_048_576 ) {
            return round( $bytes / 1_048_576, 1 ) . ' MB';
        }
        if ( $bytes >= 1_024 ) {
            return round( $bytes / 1_024, 1 ) . ' KB';
        }
        return $bytes . ' B';
    }
}
