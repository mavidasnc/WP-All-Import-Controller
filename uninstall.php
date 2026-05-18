<?php
/**
 * Routine di disinstallazione del plugin.
 *
 * Elimina la tabella di log e le option dal database quando il plugin viene cancellato
 * tramite il pannello admin di WordPress (non alla semplice disattivazione).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Rimuove la tabella di log.
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mvd_wai_ctrl_log' );

// Rimuove le option di stato.
delete_option( 'mvd_wai_ctrl_state' );
delete_option( 'mvd_wai_ctrl_db_version' );
delete_transient( 'mvd_wai_ctrl_running_lock' );

// Rimuove la cartella dei log.
$upload_dir = wp_upload_dir();
if ( empty( $upload_dir['error'] ) ) {
	$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'mvd-wai-ctrl-logs';
	if ( is_dir( $log_dir ) ) {
		$files = glob( $log_dir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $log_dir );
	}
}
