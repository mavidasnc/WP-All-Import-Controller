<?php
/**
 * Classe principale del plugin: registra hook, menu admin e handler AJAX.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton che inizializza tutte le funzionalità del plugin.
 */
class MvdWaiCtrlPlugin {

	/**
	 * Inizializza il plugin registrando tutti gli hook WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Menu admin.
		add_action( 'admin_menu', [ __CLASS__, 'registerMenu' ] );

		// Asset admin.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueueAssets' ] );

		// Handler AJAX (solo utenti loggati).
		add_action( 'wp_ajax_mvd_wai_ctrl_start',  [ __CLASS__, 'ajaxStart' ] );
		add_action( 'wp_ajax_mvd_wai_ctrl_status', [ __CLASS__, 'ajaxStatus' ] );

		// Hook cron one-time.
		add_action( MVD_WAI_CTRL_CRON_HOOK, [ 'MvdWaiCtrlRunner', 'runChain' ] );
	}

	/**
	 * Registra la voce di menu top-level in admin.
	 *
	 * @return void
	 */
	public static function registerMenu(): void {
		add_menu_page(
			__( 'Importazioni Sequenziali', 'mvd-wai-ctrl' ),
			__( 'Import Sequenziale', 'mvd-wai-ctrl' ),
			MVD_WAI_CTRL_CAPABILITY,
			'mvd-wai-ctrl',
			[ 'MvdWaiCtrlAdminPage', 'render' ],
			'dashicons-controls-play',
			56
		);
	}

	/**
	 * Carica CSS e JS del pannello admin solo nella pagina del plugin.
	 *
	 * @param string $hook Identificatore della pagina admin corrente.
	 * @return void
	 */
	public static function enqueueAssets( string $hook ): void {
		if ( 'toplevel_page_mvd-wai-ctrl' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mvd-wai-ctrl-admin',
			MVD_WAI_CTRL_URL . 'assets/admin.css',
			[],
			MVD_WAI_CTRL_VERSION
		);

		wp_enqueue_script(
			'mvd-wai-ctrl-admin',
			MVD_WAI_CTRL_URL . 'assets/admin.js',
			[],
			MVD_WAI_CTRL_VERSION,
			true
		);

		wp_localize_script(
			'mvd-wai-ctrl-admin',
			'mvdWaiCtrl',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonceStart'   => wp_create_nonce( 'mvd_wai_ctrl_start' ),
				'nonceStatus'  => wp_create_nonce( 'mvd_wai_ctrl_status' ),
				'pollInterval' => 3000,
				'i18n'         => [
					'starting'   => __( 'Avvio in corso...', 'mvd-wai-ctrl' ),
					'running'    => __( 'Esecuzione in corso...', 'mvd-wai-ctrl' ),
					'completed'  => __( 'Completato con successo!', 'mvd-wai-ctrl' ),
					'error'      => __( 'Errore durante l\'esecuzione.', 'mvd-wai-ctrl' ),
					'confirmRun' => __( 'Avviare le 4 importazioni sequenziali? L\'operazione non può essere interrotta.', 'mvd-wai-ctrl' ),
				],
			]
		);
	}

	/**
	 * Handler AJAX per avviare la catena di importazioni.
	 *
	 * @return void
	 */
	public static function ajaxStart(): void {
		check_ajax_referer( 'mvd_wai_ctrl_start' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permesso negato.', 'mvd-wai-ctrl' ) ], 403 );
		}

		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'WP All Import Pro non è attivo. Attivarlo prima di procedere.', 'mvd-wai-ctrl' ) ],
				400
			);
		}

		if ( MvdWaiCtrlState::isRunning() ) {
			wp_send_json_error(
				[ 'message' => __( 'Un\'importazione è già in corso. Attendere il completamento.', 'mvd-wai-ctrl' ) ],
				409
			);
		}

		// Lock precauzionale: evita race condition tra check e schedule.
		if ( get_transient( MVD_WAI_CTRL_LOCK_KEY ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Sistema occupato. Riprovare tra qualche secondo.', 'mvd-wai-ctrl' ) ],
				409
			);
		}

		// Crea la riga di apertura nel log e ottieni il run_id.
		$run_id = MvdWaiCtrlLogger::createRun();

		// Salva lo stato 'running' prima di schedulare il cron.
		MvdWaiCtrlState::startRun( $run_id );

		// Schedula l'esecuzione asincrona e forza l'avvio immediato del cron.
		wp_schedule_single_event( time() - 1, MVD_WAI_CTRL_CRON_HOOK );
		spawn_cron();

		wp_send_json_success(
			[
				'run_id'  => $run_id,
				'message' => __( 'Importazione sequenziale avviata.', 'mvd-wai-ctrl' ),
			]
		);
	}

	/**
	 * Handler AJAX per leggere lo stato corrente dell'esecuzione.
	 *
	 * @return void
	 */
	public static function ajaxStatus(): void {
		check_ajax_referer( 'mvd_wai_ctrl_status' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permesso negato.', 'mvd-wai-ctrl' ) ], 403 );
		}

		$state = MvdWaiCtrlState::get();
		$runs  = MvdWaiCtrlLogger::getRecentRuns( 20 );

		wp_send_json_success(
			[
				'state' => $state,
				'runs'  => $runs,
			]
		);
	}
}
