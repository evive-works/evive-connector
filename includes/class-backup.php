<?php

class Evive_Backup {

    public function register_routes() {
        $auth = function () { return current_user_can( 'edit_posts' ); };

        register_rest_route( 'evive/v1', '/backup/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => $auth,
        ) );
        register_rest_route( 'evive/v1', '/backup/list', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_backups' ),
            'permission_callback' => $auth,
        ) );
        register_rest_route( 'evive/v1', '/backup/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_backup' ),
            'permission_callback' => $auth,
        ) );
        register_rest_route( 'evive/v1', '/backup/(?P<timestamp>[0-9]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_backup' ),
            'permission_callback' => $auth,
            'args'                => array(
                'timestamp' => array(
                    'validate_callback' => function ( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
        register_rest_route( 'evive/v1', '/backup/fix-litespeed', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'fix_litespeed' ),
            'permission_callback' => $auth,
        ) );
        register_rest_route( 'evive/v1', '/backup/fix-wp-config', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'fix_wp_config' ),
            'permission_callback' => $auth,
        ) );
        register_rest_route( 'evive/v1', '/backup/create-direct', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_backup_direct' ),
            'permission_callback' => $auth,
        ) );
    }

    private function is_updraftplus_active() {
        return class_exists( 'UpdraftPlus' );
    }

    private function detect_storage() {
        if ( ! $this->is_updraftplus_active() ) return 'Local';
        $service = get_option( 'updraftplus_service', '' );
        if ( is_array( $service ) ) $service = implode( ',', $service );
        if ( empty( $service ) || $service === 'none' ) return 'Local';
        $map = array(
            'googledrive' => 'Google Drive',
            'dropbox'     => 'Dropbox',
            's3'          => 'Amazon S3',
            'ftp'         => 'FTP',
            'email'       => 'E-mail',
            'onedrive'    => 'OneDrive',
            'azure'       => 'Azure',
        );
        foreach ( $map as $key => $label ) {
            if ( strpos( $service, $key ) !== false ) return $label;
        }
        return ucfirst( $service );
    }

    private function format_entry( $timestamp, $entry ) {
        $diff = time() - $timestamp;
        if ( $diff < 3600 )       $human = 'less than an hour ago';
        elseif ( $diff < 86400 )  $human = 'today';
        elseif ( $diff < 172800 ) $human = 'yesterday';
        else {
            $days = (int) floor( $diff / 86400 );
            if ( $days < 7 )       $human = $days . ' days ago';
            elseif ( $days < 14 )  $human = '1 week ago';
            else                   $human = (int) floor( $days / 7 ) . ' weeks ago';
        }

        $all_components = array( 'db', 'uploads', 'plugins', 'themes', 'others' );
        $components     = array_values( array_intersect( array_keys( $entry ), $all_components ) );

        // Estimate size from local backup files (may be 0 if stored remotely)
        $size_bytes  = 0;
        $updraft_dir = WP_CONTENT_DIR . '/updraft/';
        foreach ( $components as $comp ) {
            if ( ! empty( $entry[ $comp ] ) ) {
                foreach ( (array) $entry[ $comp ] as $file ) {
                    $path = $updraft_dir . $file;
                    if ( file_exists( $path ) ) $size_bytes += (int) filesize( $path );
                }
            }
        }

        return array(
            'timestamp'  => (int) $timestamp,
            'date'       => wp_date( 'Y-m-d H:i', $timestamp ),
            'date_human' => $human,
            'size_mb'    => $size_bytes > 0 ? (int) round( $size_bytes / ( 1024 * 1024 ) ) : null,
            'components' => $components,
            'storage'    => $this->detect_storage(),
            'status'     => 'success',
            'restorable' => true,
        );
    }

    public function get_status( WP_REST_Request $request ) {
        if ( ! $this->is_updraftplus_active() ) {
            return rest_ensure_response( array(
                'updraftplus_active' => false,
                'last_backup'        => null,
                'warning'            => 'UpdraftPlus is not installed. Install it for backup support.',
            ) );
        }

        $version = get_option( 'updraftplus_version', null );
        $history = class_exists( 'UpdraftPlus_Backup_History' )
            ? UpdraftPlus_Backup_History::get_history()
            : array();

        $last_backup = null;
        if ( ! empty( $history ) ) {
            krsort( $history );
            $ts          = (int) key( $history );
            $entry       = current( $history );
            $last_backup = $this->format_entry( $ts, $entry );
        }

        $storage = $this->detect_storage();
        $warning = $storage === 'Local'
            ? 'Your backups are only stored locally on your server. Connect Google Drive or Dropbox for extra safety.'
            : null;

        $in_progress_since = get_transient( self::LOCK_KEY );

        return rest_ensure_response( array(
            'updraftplus_active'   => true,
            'updraftplus_version'  => $version,
            'last_backup'          => $last_backup,
            'backup_count'         => count( $history ),
            'storage_location'     => $storage,
            'local_backup_dir'     => '/wp-content/updraft/',
            'warning'              => $warning,
            // backup_started_at lets a page load / reload mid-backup resume polling from
            // the real start time instead of "now" -- so a backup that actually finished a
            // moment before the check isn't missed by a triggerTime that's too late.
            'backup_in_progress'   => (bool) $in_progress_since,
            'backup_started_at'    => $in_progress_since ? (int) $in_progress_since : null,
        ) );
    }

    public function list_backups( WP_REST_Request $request ) {
        if ( ! $this->is_updraftplus_active() ) {
            return rest_ensure_response( array( 'backups' => array(), 'total' => 0 ) );
        }

        $history = class_exists( 'UpdraftPlus_Backup_History' )
            ? UpdraftPlus_Backup_History::get_history()
            : array();

        krsort( $history );
        $backups = array();
        foreach ( $history as $ts => $entry ) {
            $backups[] = $this->format_entry( (int) $ts, $entry );
        }

        return rest_ensure_response( array(
            'backups' => $backups,
            'total'   => count( $backups ),
        ) );
    }

    public function create_backup( WP_REST_Request $request ) {
        if ( ! $this->is_updraftplus_active() ) {
            return new WP_Error( 'no_updraftplus', 'UpdraftPlus is niet geïnstalleerd.', array( 'status' => 400 ) );
        }

        global $updraftplus;
        if ( ! isset( $updraftplus ) ) {
            $plugin_file = WP_PLUGIN_DIR . '/updraftplus/updraftplus.php';
            if ( file_exists( $plugin_file ) ) include_once $plugin_file;
        }

        if ( ! isset( $updraftplus ) ) {
            return new WP_Error( 'updraftplus_unavailable', 'UpdraftPlus kon niet worden geladen.', array( 'status' => 500 ) );
        }

        $options = array(
            'nocloud'    => 0,
            'db'         => 1,
            'files'      => 1,
            'encrypt_db' => 0,
            'label'      => 'evive - ' . date( 'Y-m-d H:i' ),
        );

        // Schedule backup via the documented UpdraftPlus hook.
        // do_action() returns immediately -- the actual backup runs via WP cron.
        // The evive frontend polls /backup/list every 10 s to detect completion.
        do_action( 'updraftplus_backupnow_do_backup', $options );

        // Non-blocking cron kick: one request with the doing_wp_cron parameter
        // (the canonical format used by WP's own spawn_cron() -- less cache-prone
        // on LiteSpeed than a bare wp-cron.php request).
        if ( ! defined( 'DOING_CRON' ) ) {
            wp_remote_get( site_url( '/?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ), array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => false,
            ) );
        }

        return rest_ensure_response( array(
            'success'            => true,
            'message'            => 'Backup started. This takes 1-5 minutes.',
            'job_id'             => substr( md5( (string) time() . (string) rand() ), 0, 12 ),
            'check_status_after' => 60,
        ) );
    }

    public function delete_backup( WP_REST_Request $request ) {
        if ( ! $this->is_updraftplus_active() ) {
            return new WP_Error( 'no_updraftplus', 'UpdraftPlus is niet geïnstalleerd.', array( 'status' => 400 ) );
        }

        if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
            return new WP_Error( 'no_history', 'UpdraftPlus backup history not available.', array( 'status' => 500 ) );
        }

        $timestamp = (int) $request->get_param( 'timestamp' );
        $history   = UpdraftPlus_Backup_History::get_history();

        if ( ! isset( $history[ $timestamp ] ) ) {
            return new WP_Error( 'not_found', 'Backup not found.', array( 'status' => 404 ) );
        }

        // Use UpdraftPlus API if available, otherwise remove directly from history option
        if ( method_exists( 'UpdraftPlus_Backup_History', 'delete_local' ) ) {
            UpdraftPlus_Backup_History::delete_local( $timestamp );
        } else {
            unset( $history[ $timestamp ] );
            update_option( 'updraft_backup_history', $history );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    // -- Fix 1: LiteSpeed .htaccess patch --
    // Adds RewriteRule .* - [E=noabort:1] to .htaccess so LiteSpeed does not
    // terminate long-running PHP processes during backups.
    public function fix_litespeed( WP_REST_Request $request ) {
        $is_litespeed = false;
        if ( ! empty( $_SERVER['SERVER_SOFTWARE'] ) && stripos( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false ) {
            $is_litespeed = true;
        }
        if ( function_exists( 'litespeed_finish_request' ) ) {
            $is_litespeed = true;
        }

        if ( ! $is_litespeed ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => 'Not needed (not LiteSpeed)' ) );
        }

        $htaccess = ABSPATH . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => '.htaccess not found' ) );
        }
        if ( ! is_writable( $htaccess ) ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => '.htaccess not writable' ) );
        }

        $content = file_get_contents( $htaccess );

        if ( strpos( $content, 'noabort' ) !== false ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => 'Already configured' ) );
        }

        $rule = 'RewriteRule .* - [E=noabort:1]';

        // Insert after the first RewriteEngine On line (case-insensitive search)
        $search_pos = stripos( $content, 'RewriteEngine On' );
        if ( $search_pos !== false ) {
            $eol_pos = strpos( $content, "\n", $search_pos );
            if ( $eol_pos === false ) {
                $eol_pos = strlen( $content );
            }
            $new_content = substr( $content, 0, $eol_pos ) . "\n" . $rule . substr( $content, $eol_pos );
        } else {
            $new_content = '<IfModule mod_rewrite.c>' . "\n" . 'RewriteEngine On' . "\n" . $rule . "\n" . '</IfModule>' . "\n\n" . $content;
        }

        if ( file_put_contents( $htaccess, $new_content ) === false ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => 'Failed to write .htaccess' ) );
        }

        return rest_ensure_response( array( 'fixed' => true, 'message' => 'LiteSpeed optimisation applied for reliable backups.' ) );
    }

    // -- Fix 2: ALTERNATE_WP_CRON in wp-config.php --
    // Fallback for when the .htaccess fix is not sufficient. ALTERNATE_WP_CRON
    // makes WordPress use a redirect-based cron instead of a loopback request,
    // which is more reliable on restricted hosts.
    public function fix_wp_config( WP_REST_Request $request ) {
        if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => 'Already configured' ) );
        }

        $wp_config = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $wp_config ) ) {
            $wp_config = dirname( ABSPATH ) . '/wp-config.php';
        }
        if ( ! file_exists( $wp_config ) || ! is_readable( $wp_config ) ) {
            return rest_ensure_response( array( 'fixed' => false, 'error' => 'wp-config.php not found' ) );
        }
        if ( ! is_writable( $wp_config ) ) {
            return rest_ensure_response( array( 'fixed' => false, 'error' => 'wp-config.php not writable' ) );
        }

        $content = file_get_contents( $wp_config );

        if ( strpos( $content, 'ALTERNATE_WP_CRON' ) !== false ) {
            return rest_ensure_response( array( 'fixed' => false, 'message' => 'Already configured' ) );
        }

        $define = "define('ALTERNATE_WP_CRON', true);\n";

        // Try to insert before the canonical stop-editing marker
        $markers = array( "/* That's all, stop editing!", "/** That's all, stop editing!" );
        foreach ( $markers as $marker ) {
            $pos = strpos( $content, $marker );
            if ( $pos !== false ) {
                $new_content = substr_replace( $content, $define . "\n", $pos, 0 );
                if ( file_put_contents( $wp_config, $new_content ) !== false ) {
                    return rest_ensure_response( array( 'fixed' => true, 'message' => 'ALTERNATE_WP_CRON added to wp-config.php' ) );
                }
                return rest_ensure_response( array( 'fixed' => false, 'error' => 'Failed to write wp-config.php' ) );
            }
        }

        // Fallback: append to end of file
        $new_content = $content . "\n" . $define;
        if ( file_put_contents( $wp_config, $new_content ) === false ) {
            return rest_ensure_response( array( 'fixed' => false, 'error' => 'Failed to write wp-config.php' ) );
        }

        return rest_ensure_response( array( 'fixed' => true, 'message' => 'ALTERNATE_WP_CRON added to wp-config.php' ) );
    }

    // Trigger UpdraftPlus backup synchronously within the current PHP process,
    // replicating what the admin-ajax backupnow handler does when max_execution_time=0.
    // Unlike create_backup (which spawns wp-cron), this keeps the backup inside the
    // live HTTP request — LiteSpeed will not kill it as a background process.
    // In-progress lock, keyed to this site (single-site plugin, one lock is enough).
    // TTL is 360s -- 60s of safety margin over the 300s set_time_limit() below, so a
    // process that dies without reaching the finally block below (PHP fatal, OOM kill)
    // can't leave the lock stuck forever; a genuinely-running backup never lives long
    // enough to hit the TTL on its own.
    const LOCK_KEY = 'evive_backup_in_progress';
    const LOCK_TTL = 360;

    public static function is_backup_in_progress() {
        return (bool) get_transient( self::LOCK_KEY );
    }

    public function create_backup_direct( WP_REST_Request $request ) {
        if ( ! $this->is_updraftplus_active() ) {
            return new WP_Error( 'no_updraftplus', 'UpdraftPlus is not installed.', array( 'status' => 400 ) );
        }

        global $updraftplus;
        if ( ! isset( $updraftplus ) ) {
            return new WP_Error( 'updraftplus_unavailable', 'UpdraftPlus not loaded.', array( 'status' => 500 ) );
        }

        // Reject a second concurrent backup instead of silently starting another one --
        // this is the actual fix for repeated "Backup Now" clicks creating multiple real
        // backups (board card, evive project). No prior guard existed here at all.
        if ( self::is_backup_in_progress() ) {
            return new WP_Error(
                'backup_in_progress',
                'A backup is already in progress. Please wait for it to finish before starting another.',
                array( 'status' => 409 )
            );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        try {
            // Allow PHP to run for up to 5 minutes; keep running even if client disconnects.
            @set_time_limit( 300 );
            @ignore_user_abort( true );

            // Snapshot history count so we can detect whether backup completed.
            $history_before = class_exists( 'UpdraftPlus_Backup_History' )
                ? UpdraftPlus_Backup_History::get_history()
                : array();
            $count_before = count( $history_before );

            $label = 'evive - ' . date( 'Y-m-d H:i' );

            if ( method_exists( $updraftplus, 'boot_backup' ) ) {
                // boot_backup() runs the full backup in-process (same path as admin AJAX).
                // With set_time_limit(0/300), UpdraftPlus skips its inter-chunk cron scheduling
                // and runs each file/db chunk sequentially in the same PHP execution.
                // No third argument: UpdraftPlus backs up all entities
                // (plugins, themes, uploads, others, database).
                // Passing 'db'=>1/'files'=>1 in $extra_options limits scope to those
                // entity keys only, which caused DB-only 2 MB backups.
                $updraftplus->boot_backup( true, true );
            } else {
                // Fallback for UpdraftPlus < 1.22: set up the job nonce, then directly
                // fire the backup_resume action in this process instead of via wp-cron.
                $nonce = $updraftplus->backup_time_nonce();
                $opts  = array(
                    'nocloud'    => 0,
                    'encrypt_db' => 0,
                    'label'      => $label,
                );
                if ( method_exists( $updraftplus, 'jobdata_set_default' ) ) {
                    $updraftplus->jobdata_set_default( $nonce, $opts );
                }
                // Fire the first backup step inline — subsequent steps reschedule themselves
                // but will also run inline because set_time_limit(300) keeps the process alive.
                do_action( 'updraftplus_backup_resume', 0, $nonce );
            }

            $nonce = isset( $updraftplus->nonce ) ? (string) $updraftplus->nonce : '';

            $history_after = class_exists( 'UpdraftPlus_Backup_History' )
                ? UpdraftPlus_Backup_History::get_history()
                : array();
            $completed = count( $history_after ) > $count_before;

            // 'success' used to be hardcoded true regardless of $completed, so a backup that didn't
            // actually finish (still running, or genuinely failed inside UpdraftPlus without a PHP
            // fatal) was indistinguishable from a real success to any caller that only checks
            // 'success' -- the evive frontend already knew to check 'completed' separately, but that
            // was a workaround, not a fix to the contract itself. 'success' now mirrors 'completed'.
            return rest_ensure_response( array(
                'success'   => $completed,
                'job_id'    => $nonce,
                'message'   => $completed
                    ? 'Backup completed successfully.'
                    : 'Backup triggered. It may still be finalising.',
                'completed' => $completed,
            ) );
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }
}