<?php
/**
 * Plugin Name:       MVD WP All Import Controller
 * Plugin URI:        https://github.com/mavidasnc/WP-All-Import-Controller
 * Description:       Esegue 4 importazioni WP All Import Pro in sequenza con un solo click, stop al primo errore e log persistente.
 * Version:           1.3.3
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Mavida
 * Author URI:        https://mavida.it
 * License:           GPL-2.0-or-later
 * Text Domain:       mvd-wai-ctrl
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// ── Autoload Composer ─────────────────────────────────────────────────────────

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// ── Costanti ──────────────────────────────────────────────────────────────────

define( 'MVD_WAI_CTRL_VERSION',       '1.3.3' );
define( 'MVD_WAI_CTRL_DB_VERSION',    '1.3.0' ); // aggiornare solo quando cambia lo schema DB
define( 'MVD_WAI_CTRL_DIR',           plugin_dir_path( __FILE__ ) );
define( 'MVD_WAI_CTRL_URL',           plugin_dir_url( __FILE__ ) );
define( 'MVD_WAI_CTRL_CRON_HOOK',     'mvd_wai_ctrl_run' );
define( 'MVD_WAI_CTRL_STATE_OPTION',  'mvd_wai_ctrl_state' );
define( 'MVD_WAI_CTRL_LOCK_KEY',      'mvd_wai_ctrl_running_lock' );
define( 'MVD_WAI_CTRL_CAPABILITY',    'manage_options' );

/**
 * ID delle importazioni WP All Import Pro da eseguire in ordine.
 *
 * Sostituire i valori con gli ID reali visibili in All Import → Manage Imports.
 */
define( 'MVD_WAI_CTRL_IDS', [ 13, 2, 1, 14 ] );

// ── Autoload classi ────────────────────────────────────────────────────────────

/**
 * Autoload semplice delle classi del plugin dalla cartella includes/.
 *
 * @param string $class_name Nome della classe da caricare.
 * @return void
 */
function mvd_wai_ctrl_autoload( string $class_name ): void {
	$prefix = 'MvdWaiCtrl';
	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}
	// Converte MvdWaiCtrlMyClass → class-my-class.php
	$short    = substr( $class_name, strlen( $prefix ) );
	$filename = 'class' . strtolower( preg_replace( '/([A-Z])/', '-$1', $short ) ) . '.php';
	$path     = MVD_WAI_CTRL_DIR . 'includes/' . $filename;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( 'mvd_wai_ctrl_autoload' );

// ── Activation / Deactivation ─────────────────────────────────────────────────

register_activation_hook( __FILE__, [ 'MvdWaiCtrlLogger', 'createTable' ] );

register_deactivation_hook(
	__FILE__,
	function (): void {
		wp_clear_scheduled_hook( MVD_WAI_CTRL_CRON_HOOK );
	}
);

// ── Migrazione da versioni precedenti ─────────────────────────────────────────

add_action(
	'plugins_loaded',
	function (): void {
		// Rimuove l'option del token GitHub introdotta in 1.0.1 e rimossa in 1.2.0 (repo ora pubblico).
		if ( get_option( 'mvd_wai_ctrl_gh_token', false ) !== false ) {
			delete_option( 'mvd_wai_ctrl_gh_token' );
		}

		// Aggiorna lo schema DB se la versione salvata è diversa da quella corrente.
		// Copre gli aggiornamenti automatici del plugin, che non passano per register_activation_hook.
		if ( get_option( 'mvd_wai_ctrl_db_version' ) !== MVD_WAI_CTRL_DB_VERSION ) {
			MvdWaiCtrlLogger::createTable();
			update_option( 'mvd_wai_ctrl_db_version', MVD_WAI_CTRL_DB_VERSION, false );
		}
	},
	1
);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

add_action(
	'plugins_loaded',
	function (): void {
		load_plugin_textdomain( 'mvd-wai-ctrl', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		MvdWaiCtrlPlugin::init();
	}
);

// ── Updater GitHub ─────────────────────────────────────────────────────────────

add_action(
	'init',
	function (): void {
		if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/mavidasnc/WP-All-Import-Controller/',
			__FILE__,
			'mvd-wp-all-import-controller'
		);

		// Scarica lo zip allegato alla GitHub Release (non lo zip auto-generato dal tag).
		$vcs_api = $checker->getVcsApi();
		if ( $vcs_api instanceof \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi ) {
			$vcs_api->enableReleaseAssets();
		}
	}
);
