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

// Rimuove le option di stato e le impostazioni.
delete_option( 'mvd_wai_ctrl_state' );
delete_option( 'mvd_wai_ctrl_gh_token' );
delete_transient( 'mvd_wai_ctrl_running_lock' );
