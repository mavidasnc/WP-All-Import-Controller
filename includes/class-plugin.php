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
		add_action( 'wp_ajax_mvd_wai_ctrl_resume', [ __CLASS__, 'ajaxResume' ] );

		// Hook cron one-time (fallback nel caso il loopback non sia disponibile).
		add_action( MVD_WAI_CTRL_CRON_HOOK, [ 'MvdWaiCtrlRunner', 'runStep' ] );

		// Endpoint loopback per esecuzione in contesto admin (is_admin() = true garantito).
		add_action( 'wp_ajax_nopriv_mvd_wai_ctrl_run_chain', [ __CLASS__, 'ajaxRunChain' ] );

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
				'nonceResume'  => wp_create_nonce( 'mvd_wai_ctrl_resume' ),
				'pollInterval' => 3000,
				'i18n'         => [
					'starting'        => __( 'Avvio in corso...', 'mvd-wai-ctrl' ),
					'running'         => __( 'Esecuzione in corso...', 'mvd-wai-ctrl' ),
					'completed'       => __( 'Completato con successo!', 'mvd-wai-ctrl' ),
					'error'           => __( 'Errore durante l\'esecuzione.', 'mvd-wai-ctrl' ),
					'confirmRun'      => __( 'Avviare le 4 importazioni sequenziali? L\'operazione non può essere interrotta.', 'mvd-wai-ctrl' ),
					'networkError'    => __( 'Impossibile contattare il server. Verificare la connessione.', 'mvd-wai-ctrl' ),
					'resuming'        => __( 'Ripresa in corso...', 'mvd-wai-ctrl' ),
					'crashedTitle'    => __( 'Importazione interrotta', 'mvd-wai-ctrl' ),
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
	 * Handler AJAX per leggere lo stato corrente dell'esecuzione.
	 *
	 * Esegue anche il watchdog: se lo stato è 'running' ma non c'è heartbeat recente
	 * e il lock transient è assente, marca il run come crashed.
	 *
	 * @return void
	 */
	public static function ajaxStatus(): void {
		check_ajax_referer( 'mvd_wai_ctrl_status' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permesso negato.', 'mvd-wai-ctrl' ) ], 403 );
		}

		$state = MvdWaiCtrlState::get();

		// Watchdog: rileva cron morto senza heartbeat.
		if (
			'running' === $state['status']
			&& ! empty( $state['updated_at'] )
			&& ( time() - (int) strtotime( $state['updated_at'] ) ) > MVD_WAI_CTRL_WATCHDOG_THRESHOLD
			&& ! get_transient( MVD_WAI_CTRL_LOCK_KEY )
		) {
			$elapsed = time() - (int) strtotime( $state['updated_at'] );
			$reason  = sprintf(
				/* translators: %d: secondi dall'ultimo heartbeat */
				__( 'Cron interrotto: nessun heartbeat da %d secondi.', 'mvd-wai-ctrl' ),
				$elapsed
			);
			$run_id = (int) $state['run_id'];
			MvdWaiCtrlLogger::markRunCrashed( $run_id, $reason );
			MvdWaiCtrlLogger::writeFile( 'ERROR', $reason, [ 'run_id' => $run_id ] );
			MvdWaiCtrlState::markCrashed( $reason );
			$state = MvdWaiCtrlState::get();
		}

		$can_resume = (
			'error' === $state['status']
			&& (int) $state['current_index'] < count( MVD_WAI_CTRL_IDS )
		);

		$runs = MvdWaiCtrlLogger::getRecentRuns( 20 );

		wp_send_json_success(
			[
				'state'      => $state,
				'runs'       => $runs,
				'can_resume' => $can_resume,
			]
		);
	}

	/**
	 * Handler AJAX per riprendere un run interrotto dal punto di interruzione.
	 *
	 * Riprende da current_index senza resettare i counter PMXI, sfruttando
	 * la resumability nativa di PMXI_Import_Record (queue_chunk_number persistente).
	 *
	 * @return void
	 */
	public static function ajaxResume(): void {
		check_ajax_referer( 'mvd_wai_ctrl_resume' );

		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permesso negato.', 'mvd-wai-ctrl' ) ], 403 );
		}

		$state = MvdWaiCtrlState::get();

		if ( 'error' !== $state['status'] ) {
			wp_send_json_error(
				[ 'message' => __( 'Nessun run interrotto da riprendere.', 'mvd-wai-ctrl' ) ],
				400
			);
		}

		if ( (int) $state['current_index'] >= count( MVD_WAI_CTRL_IDS ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Tutti i passi sono già stati completati.', 'mvd-wai-ctrl' ) ],
				400
			);
		}

		$run_id      = (int) $state['run_id'];
		$step_index  = (int) $state['current_index'];

		// Aggiunge una riga nel log per tracciare la ripresa.
		MvdWaiCtrlLogger::appendStep(
			$run_id,
			[
				'step_index' => $step_index,
				'outcome'    => 'start',
				'message'    => sprintf(
					/* translators: %d: numero passo 1-based */
					__( 'Ripresa dal passo %d.', 'mvd-wai-ctrl' ),
					$step_index + 1
				),
			]
		);

		MvdWaiCtrlState::markResumeRequested();
		MvdWaiCtrlRunner::scheduleSelf();

		wp_send_json_success(
			[
				'run_id'  => $run_id,
				'message' => __( 'Ripresa avviata.', 'mvd-wai-ctrl' ),
			]
		);
	}
}
