<?php
/**
 * Bootstrap per l'analisi statica PHPStan: definisce le costanti del plugin
 * affinché l'analizzatore possa risolvere tutti i riferimenti senza WordPress.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MVD_WAI_CTRL_VERSION',      '1.0.0' );
define( 'MVD_WAI_CTRL_DIR',          dirname( __DIR__ ) . '/' );
define( 'MVD_WAI_CTRL_URL',          'http://localhost/wp-content/plugins/mvd-wp-all-import-controller/' );
define( 'MVD_WAI_CTRL_CRON_HOOK',    'mvd_wai_ctrl_run' );
define( 'MVD_WAI_CTRL_STATE_OPTION', 'mvd_wai_ctrl_state' );
define( 'MVD_WAI_CTRL_LOCK_KEY',     'mvd_wai_ctrl_running_lock' );
define( 'MVD_WAI_CTRL_CAPABILITY',   'manage_options' );
define( 'MVD_WAI_CTRL_IDS',          [ 13, 2, 1, 14 ] );

// Stub di PMXI_Import_Record: consente a PHPStan di analizzare class-admin-page.php
// e class-runner.php senza richiedere WP All Import Pro nell'ambiente di analisi.
if ( ! class_exists( 'PMXI_Import_Record' ) ) {
	require_once __DIR__ . '/stubs/pmxi-stubs.php';
}
if ( ! class_exists( 'PMXI_History_Record' ) ) {
	require_once __DIR__ . '/stubs/pmxi-history-record.php';
}
