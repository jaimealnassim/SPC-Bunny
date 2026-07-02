/* SPC Bunny Connector — Admin JS v1.5.9 */
jQuery( function ( $ ) {
    var cfg = window.spcBunny || {};

    // ── Tabs ─────────────────────────────────────────────────────────────────
    $( '.spc-bunny-tab' ).on( 'click', function ( e ) {
        e.preventDefault();
        var target = $( this ).attr( 'href' );
        $( '.spc-bunny-tab' ).removeClass( 'is-active' );
        $( this ).addClass( 'is-active' );
        $( '.spc-bunny-panel' ).attr( 'hidden', true );
        $( target ).removeAttr( 'hidden' );
        if ( target === '#tab-stats' ) { loadStats(); startSyncPoll(); } else { stopSyncPoll(); }
    } );

    function setResult( $el, msg, ok ) {
        $el.text( msg ).removeClass( 'is-ok is-err' ).addClass( ok ? 'is-ok' : 'is-err' );
        if ( ok ) { setTimeout( function () { $el.text( '' ).removeClass( 'is-ok is-err' ); }, 6000 ); }
    }

    function post( action, nonce, extra ) {
        return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: nonce }, extra || {} ) );
    }

    // ── Admin bar purge button ────────────────────────────────────────────────
    // Script only runs on our settings page so it cannot interfere with other admin pages
    $( document ).on( 'click', '#wp-admin-bar-spc-bunny-purge > .ab-item', function ( e ) {
        e.preventDefault();
        var $btn = $( this );
        if ( $btn.data( 'busy' ) ) { return; }
        $btn.data( 'busy', true ).text( 'Purging\u2026' );
        post( 'spc_bunny_admin_bar_purge', cfg.nonceBarPurge )
            .then( function ( r ) {
                $btn.text( r.success ? '\u2713 Purged!' : '\u2717 Failed' );
                setTimeout( function () { $btn.text( '\u26A1 Purge Bunny' ).removeData( 'busy' ); }, 3000 );
                if ( r.success ) { setTimeout( loadStats, 1500 ); }
            } )
            .catch( function () { $btn.text( '\u2717 Error' ).removeData( 'busy' ); } );
    } );

    // ── Stats ─────────────────────────────────────────────────────────────────
    function updateSyncCard( data ) {
        var bunnyTime = data.bunny_last || 'Never';
        var spcTime   = data.spc_last   || 'Never (or SPC trigger not enabled)';
        $( '#spc-sync-bunny-time' ).text( bunnyTime );
        $( '#spc-sync-spc-time'   ).text( spcTime );

        var $status = $( '#spc-sync-status' );
        if ( data.bunny_last && data.spc_last ) {
            $status.removeClass( 'is-synced is-drifted' )
                .addClass( data.in_sync ? 'is-synced' : 'is-drifted' )
                .html( data.in_sync
                    ? '\u2713 Both caches cleared together &mdash; in sync.'
                    : '\u26a0 Caches cleared at different times &mdash; they may be out of sync.' )
                .show();
        }
    }

    function loadStats() {
        var days  = $( '#js-stats-days' ).val() || 7;
        var $grid = $( '#js-stats-grid' );
        var $err  = $( '#js-stats-error' );
        $grid.addClass( 'is-loading' );
        $err.attr( 'hidden', true );

        post( 'spc_bunny_fetch_stats', cfg.nonceStats, { days: days } )
            .then( function ( res ) {
                $grid.removeClass( 'is-loading' );
                if ( ! res.success ) {
                    $err.text( cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ) ).removeAttr( 'hidden' );
                    return;
                }
                var s = res.data.stats;
                var h = res.data.health;

                // Hit rate
                $( '#stat-hit-rate .spc-bunny-stat-card__value' ).text( s.hit_rate + '%' );
                $( '#stat-hit-rate .spc-bunny-stat-card__fill' ).css( 'width', s.hit_rate + '%' )
                    .toggleClass( 'is-good', s.hit_rate >= 70 )
                    .toggleClass( 'is-warn', s.hit_rate >= 40 && s.hit_rate < 70 )
                    .toggleClass( 'is-bad',  s.hit_rate < 40 );

                // Bandwidth — total in main value, cached/uncached in sub-row
                $( '#stat-bandwidth .spc-bunny-stat-card__value' ).text( s.bandwidth_fmt );
                $( '#stat-bandwidth-cached' ).text( s.cached_fmt );
                $( '#stat-bandwidth-origin' ).text( s.origin_fmt );

                // Requests
                $( '#stat-requests .spc-bunny-stat-card__value' ).text( s.requests_fmt );
                $( '#stat-requests .spc-bunny-stat-card__sub'   ).text( days + '-day period' );

                // Origin response
                $( '#stat-origin-time .spc-bunny-stat-card__value' ).text( s.avg_origin_ms + 'ms' );
                $( '#stat-origin-time .spc-bunny-stat-card__sub'   ).text( 'avg time to origin' );

                // Health badge
                $( '.spc-bunny-health__badge' ).text( h.label ).attr( 'class', 'spc-bunny-health__badge spc-bunny-health--' + h.status );

                // Sync status
                updateSyncCard( res.data );
            } )
            .catch( function () {
                $grid.removeClass( 'is-loading' );
                $err.text( cfg.i18n.error + 'Request failed.' ).removeAttr( 'hidden' );
            } );
    }

    // Auto-poll sync status every 8 seconds while on the stats tab
    var syncTimer = null;

    function pollSync() {
        $.post( cfg.ajaxUrl, { action: 'spc_bunny_sync_status', nonce: cfg.nonceSync } )
            .then( function ( res ) {
                if ( res.success ) { updateSyncCard( res.data ); }
            } );
    }

    function startSyncPoll() {
        if ( syncTimer ) { return; }
        pollSync(); // immediate first call
        syncTimer = setInterval( pollSync, 8000 );
    }

    function stopSyncPoll() {
        if ( syncTimer ) { clearInterval( syncTimer ); syncTimer = null; }
    }

    $( '#js-refresh-stats' ).on( 'click', loadStats );
    $( '#js-stats-days' ).on( 'change', loadStats );
    if ( $( '#tab-stats:not([hidden])' ).length ) { loadStats(); startSyncPoll(); }

    // ── Cache warmer ──────────────────────────────────────────────────────────
    $( '#js-warm-now' ).on( 'click', function () {
        var $btn = $( this ), $res = $( '#js-warm-result' );
        $btn.prop( 'disabled', true ).text( 'Starting...' );
        post( 'spc_bunny_warm_now', cfg.nonceWarm )
            .then( function ( res ) { setResult( $res, res.success ? res.data.message : cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ), res.success ); } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Warm Cache Now' ); } );
    } );

    // ── Test connection ───────────────────────────────────────────────────────
    $( '#js-test' ).on( 'click', function () {
        var $btn = $( this ), $res = $( '#js-test-result' ), key = $( '#spc_api_key' ).val();
        if ( ! key ) { setResult( $res, 'Enter an API key first.', false ); return; }
        $btn.prop( 'disabled', true ).text( cfg.i18n.testing );
        post( 'spc_bunny_test_connection', cfg.nonceTest, { api_key: key } )
            .then( function ( res ) { setResult( $res, res.success ? cfg.i18n.testOk + ' \u2014 ' + res.data.message : cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ), res.success ); } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Test Connection' ); } );
    } );

    // ── Load zones ────────────────────────────────────────────────────────────
    $( '#js-load-zones' ).on( 'click', function () {
        var $btn = $( this ), $sel = $( '#spc_pull_zone' ), key = $( '#spc_api_key' ).val();
        if ( ! key ) { alert( 'Enter and save your API key first.' ); return; }
        $btn.prop( 'disabled', true ).text( cfg.i18n.loading );
        post( 'spc_bunny_fetch_zones', cfg.nonceZones, { api_key: key } )
            .then( function ( res ) {
                if ( ! res.success ) { alert( cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ) ); return; }
                var current = $sel.val();
                $sel.empty().append( $( '<option>' ).val( '' ).text( cfg.i18n.selectZone ) );
                $.each( res.data, function ( i, z ) {
                    var host = z.hostnames && z.hostnames.length ? z.hostnames[0] : '';
                    $sel.append( $( '<option>' ).val( z.id ).text( z.name + ( host ? ' (' + host + ')' : '' ) ) );
                } );
                if ( current ) { $sel.val( current ); }
            } )
            .catch( function () { alert( cfg.i18n.error + 'Request failed.' ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Load Zones' ); } );
    } );

    // ── Manual purge ──────────────────────────────────────────────────────────
    $( '#js-purge' ).on( 'click', function () {
        if ( ! confirm( 'Purge the entire Bunny CDN cache now?' ) ) { return; }
        var $btn = $( this ), $res = $( '#js-purge-result' );
        $btn.prop( 'disabled', true ).text( cfg.i18n.purging );
        post( 'spc_bunny_manual_purge', cfg.noncePurge )
            .then( function ( res ) {
                setResult( $res, res.success ? cfg.i18n.purgeOk : cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ), res.success );
                if ( res.success ) { setTimeout( loadStats, 1500 ); }
            } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Purge Entire CDN Cache' ); } );
    } );

    // ── Perma-Cache ───────────────────────────────────────────────────────────────
    function permaParams() {
        return {
            zone_name:     $( '#spc_perma_zone_name' ).val()     || '',
            zone_password: $( '#spc_perma_zone_password' ).val() || '',
            region:        $( '#spc_perma_region' ).val()        || 'de',
            keep:          $( '#spc_perma_keep' ).val()          || '1',
            nonce:         cfg.noncePurge,
        };
    }

    $( '#js-test-perma' ).on( 'click', function () {
        var $btn = $( this ), $res = $( '#js-perma-result' );
        $btn.prop( 'disabled', true ).text( 'Testing...' );
        $.post( cfg.ajaxUrl, $.extend( { action: 'spc_bunny_test_perma_cache' }, permaParams() ) )
            .then( function ( r ) { setResult( $res, r.success ? r.data.message : cfg.i18n.error + ( r.data && r.data.message ? r.data.message : '' ), r.success ); } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Test Connection' ); } );
    } );

    $( '#js-cleanup-perma' ).on( 'click', function () {
        if ( ! confirm( 'This will permanently delete old Perma-Cache directories from your storage zone. Continue?' ) ) { return; }
        var $btn = $( this ), $res = $( '#js-perma-result' );
        $btn.prop( 'disabled', true ).text( 'Cleaning up...' );
        $.post( cfg.ajaxUrl, $.extend( { action: 'spc_bunny_cleanup_perma' }, permaParams() ) )
            .then( function ( r ) { setResult( $res, r.success ? r.data.message : cfg.i18n.error + ( r.data && r.data.message ? r.data.message : '' ), r.success ); } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Run Cleanup Now' ); } );
    } );

    // ── Cache mode ────────────────────────────────────────────────────────────
    $( 'input[name="js-force-cache"]' ).on( 'change', function () {
        $( '.spc-bunny-mode-option' ).removeClass( 'is-selected' );
        $( this ).closest( '.spc-bunny-mode-option' ).addClass( 'is-selected' );
    } );

    // ── Deploy ────────────────────────────────────────────────────────────────
    $( '#js-deploy' ).on( 'click', function () {
        var $btn = $( this ), $res = $( '#js-deploy-result' );
        var ttl        = $( '#js-ttl' ).val();
        var forceCache = $( 'input[name="js-force-cache"]:checked' ).val() || '0';
            var customPaths = $( '#spc_custom_bypass' ).val() || '';
        $btn.prop( 'disabled', true ).text( cfg.i18n.deploying );
        $( '#js-deploy-table' ).attr( 'hidden', true );
        post( 'spc_bunny_deploy_rules', cfg.nonceDeploy, { ttl: ttl, force_cache: forceCache, custom_bypass_paths: customPaths, enabled_rules: $( 'input[name="spc_bunny_rule_enabled[]"]:checked' ).map( function() { return $( this ).val(); } ).get() } )
            .then( function ( res ) {
                var data = res.data || {}, results = data.results || {}, rows = '';
                $.each( results, function ( k, r ) {
                    var pill = r.success ? '<span class="spc-bunny-pill is-ok">OK</span>' : '<span class="spc-bunny-pill is-err">Error</span>';
                    rows += '<tr><td>' + $( '<div>' ).text( r.label ).html() + '</td><td>' + pill + '</td><td>' + $( '<div>' ).text( r.message ).html() + '</td></tr>';
                } );
                if ( rows ) { $( '#js-deploy-rows' ).html( rows ); $( '#js-deploy-table' ).removeAttr( 'hidden' ); }
                setResult( $res, res.success ? cfg.i18n.deployOk : cfg.i18n.error + ( data.message || '' ), res.success );
                if ( res.success ) { setTimeout( function () { location.reload(); }, 1800 ); }
            } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ); } );
    } );

    // ── Remove ────────────────────────────────────────────────────────────────
    $( '#js-remove' ).on( 'click', function () {
        if ( ! confirm( cfg.i18n.confirmRemove ) ) { return; }
        var $btn = $( this ), $res = $( '#js-deploy-result' );
        $btn.prop( 'disabled', true ).text( cfg.i18n.removing );
        post( 'spc_bunny_remove_rules', cfg.nonceRemove )
            .then( function ( res ) {
                setResult( $res, res.success ? cfg.i18n.removeOk : cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ), res.success );
                if ( res.success ) { setTimeout( function () { location.reload(); }, 1500 ); }
            } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ); } );
    } );

    // ── DNS Stats ──────────────────────────────────────────────────────────────
    function loadDnsZones( $sel ) {
        return $.post( cfg.ajaxUrl, { action: 'spc_bunny_fetch_dns_zones', nonce: cfg.nonceSync } )
            .then( function ( res ) {
                if ( ! res.success ) { return; }
                $sel.empty().append( $( '<option>' ).val( '' ).text( '— Select a zone —' ) );
                $.each( res.data, function ( i, z ) {
                    $sel.append( $( '<option>' ).val( z.id ).text( z.domain + ' (' + z.id + ')' ) );
                } );
                return res;
            } );
    }

    $( '#js-load-dns-zones' ).on( 'click', function () {
        loadDnsZones( $( '#spc_dns_zone_select' ) );
    } );

    $( '#js-load-dns-stats' ).on( 'click', function () {
        var zoneId = $( '#spc_dns_zone_select' ).val();
        var days   = $( '#js-dns-days' ).val() || 7;
        var $res   = $( '#js-dns-result' );
        if ( ! zoneId ) { setResult( $res, 'Select a DNS zone first.', false ); return; }
        $res.text( 'Loading\u2026' ).removeClass( 'is-ok is-err' );
        $.post( cfg.ajaxUrl, { action: 'spc_bunny_fetch_dns_stats', nonce: cfg.nonceSync, zone_id: zoneId, days: days } )
            .then( function ( res ) {
                if ( ! res.success ) { setResult( $res, cfg.i18n.error + ( res.data && res.data.message ? res.data.message : '' ), false ); return; }
                $res.text( '' );
                var d = res.data; var raw = d.raw || {};
                $( '#js-dns-stats-grid' ).removeAttr( 'hidden' );
                $( '#stat-dns-queries .spc-bunny-stat-card__value' ).text( d.total_queries );
                var cached  = raw.CachedQueriesServed || raw.TotalCachedQueriesServed || 0;
                var latency = raw.AverageQueryTime    || raw.AverageResponseTime       || 0;
                $( '#stat-dns-cached .spc-bunny-stat-card__value'  ).text( cached  ? Number( cached ).toLocaleString() : '\u2014' );
                $( '#stat-dns-latency .spc-bunny-stat-card__value' ).text( latency ? latency + 'ms'                    : '\u2014' );
            } )
            .catch( function () { setResult( $res, cfg.i18n.error + 'Request failed.', false ); } );
    } );

    // DNS zone picker in Settings tab
    $( '#js-load-dns-zones-settings' ).on( 'click', function () {
        var $btn = $( this ), $res = $( '#js-dns-zone-result' );
        $btn.prop( 'disabled', true ).text( 'Loading\u2026' );
        loadDnsZones( $( '#js-dns-zone-picker' ) )
            .then( function () { $( '#js-dns-zone-list' ).show(); } )
            .catch( function () { setResult( $res, 'Failed to load zones.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Find My Zone ID' ); } );
    } );

    $( '#js-dns-zone-use' ).on( 'click', function () {
        var val = $( '#js-dns-zone-picker' ).val();
        if ( val ) {
            $( '#spc_dns_zone_id' ).val( val );
            $( '#js-dns-zone-list' ).hide();
            setResult( $( '#js-dns-zone-result' ), 'Zone ID set \u2014 save settings to keep it.', true );
        }
    } );

} );
