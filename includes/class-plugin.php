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
		add_action( 'wp_ajax_mvd_wai_ctrl_reset',  [ __CLASS__, 'ajaxReset' ] );

		// Hook cron one-time (fallback nel caso il loopback non sia disponibile).
		add_action( MVD_WAI_CTRL_CRON_HOOK, [ 'MvdWaiCtrlRunner', 'runStep' ] );

		// Endpoint loopback per esecuzione in contesto admin (is_admin() = true garantito).
		add_action( 'wp_ajax_nopriv_mvd_wai_ctrl_run_chain', [ __CLASS__, 'ajaxRunChain' ] );

		// Salvataggio impostazioni (token GitHub).
		add_action( 'admin_post_mvd_wai_ctrl_save_settings', [ __CLASS__, 'saveSettings' ] );
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
				'nonceReset'   => wp_create_nonce( 'mvd_wai_ctrl_reset' ),
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

		// Salva lo stato 'running' prima di avviare il runner.
		MvdWaiCtrlState::startRun( $run_id );

		// Schedula il primo step via loopback admin-ajax (garantisce is_admin()=true,
		// necessario perché WP All Import Pro carichi PMXI_Import_Record).
		// Fallback automatico su wp_schedule_single_event + spawn_cron se il loopback
		// non è disponibile (gestito internamente da scheduleSelf).
		MvdWaiCtrlRunner::scheduleSelf();

		wp_send_json_success(
			[
				'run_id'  => $run_id,
				'message' => __( 'Importazione sequenziale avviata.', 'mvd-wai-ctrl' ),
			]
		);
	}

	/**
	 * Handler del loopback interno che esegue la catena di importazioni.
	 *
	 * Registrato su wp_ajax_nopriv per essere chiamato dal server stesso via wp_remote_post.
	 * La richiesta è autenticata con un token monouso anziché con sessione utente.
	 *
	 * @return void
	 */
	public static function ajaxRunChain(): void {
		$secret = sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stored = get_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret' );

		if ( ! $stored || ! hash_equals( $stored, $secret ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		delete_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret' );

		MvdWaiCtrlRunner::runStep();
		wp_die();
	}

	/**
	 * Handler AJAX per sbloccare manualmente uno stato "running" bloccato.
	 *
	 * @return void
	 */
	public static function ajaxReset(): void {
		check_ajax_referer( 'mvd_wai_ctrl_reset' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permesso negato.', 'mvd-wai-ctrl' ) ], 403 );
		}

		delete_transient( MVD_WAI_CTRL_LOCK_KEY );
		delete_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret' );
		MvdWaiCtrlState::reset();

		wp_send_json_success( [ 'message' => __( 'Stato resettato.', 'mvd-wai-ctrl' ) ] );
	}

	/**
	 * Salva le impostazioni del plugin (token GitHub per gli aggiornamenti).
	 *
	 * @return void
	 */
	public static function saveSettings(): void {
		check_admin_referer( 'mvd_wai_ctrl_save_settings' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'mvd-wai-ctrl' ) );
		}

		$token = sanitize_text_field( wp_unslash( $_POST['mvd_wai_ctrl_gh_token'] ?? '' ) );

		if ( '' === $token ) {
			delete_option( MVD_WAI_CTRL_TOKEN_OPTION );
		} else {
			update_option( MVD_WAI_CTRL_TOKEN_OPTION, $token, false );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'mvd-wai-ctrl', 'settings-updated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
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
