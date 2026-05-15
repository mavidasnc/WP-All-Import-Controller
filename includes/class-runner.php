<?php
/**
 * Esegue un singolo step della catena di importazioni sequenziali.
 *
 * Ogni invocazione processa un solo chunk di un solo import.
 * Al termine del chunk, ri-schedula sé stesso via loopback admin-ajax
 * (o wp_schedule_single_event come fallback) fino a che:
 *  - l'import corrente non è completato → si avanza al prossimo import
 *  - tutti e 4 gli import non sono completati → la chain termina
 *
 * Questo approccio "1 loopback = 1 chunk" risolve due problemi:
 *  1. Import grandi che eccedono cron_processing_time_limit di PMXI (≈59 s)
 *     vengono completati in più hit anziché essere troncati silenziosamente.
 *  2. Il singleton WPAI_WPML (WPML All Import add-on) si ricrea ad ogni
 *     richiesta HTTP, evitando il bug del language_code sticky in catena.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe che gestisce l'esecuzione step-by-step degli import.
 */
class MvdWaiCtrlRunner {

	/**
	 * TTL del lock anti-doppio-avvio (secondi).
	 *
	 * Copre un singolo step (un chunk PMXI ≈ 59 s) con margine di sicurezza.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 120;

	/**
	 * Restituisce il nome leggibile di un'importazione WP All Import Pro.
	 *
	 * Usa friendly_name (nome dato dall'utente nell'UI) come primario,
	 * con fallback su name (filename) e infine "Import ID %d".
	 *
	 * @param int $import_id ID dell'importazione.
	 * @return string        Nome visualizzabile.
	 */
	public static function getImportDisplayName( int $import_id ): string {
		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			/* translators: %d: ID dell'import */
			return sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $import_id );
		}
		$rec = new PMXI_Import_Record();
		$rec->getById( $import_id );
		if ( $rec->isEmpty() ) {
			/* translators: %d: ID dell'import non trovato */
			return sprintf( __( '[Import ID %d — non trovato]', 'mvd-wai-ctrl' ), $import_id );
		}
		$friendly = isset( $rec->friendly_name ) ? trim( (string) $rec->friendly_name ) : '';
		if ( '' !== $friendly ) {
			return $friendly;
		}
		return $rec->name ?: sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $import_id );
	}

	/**
	 * Processa un singolo step della catena. Punto di ingresso del loopback e del cron fallback.
	 *
	 * @return void
	 */
	public static function runStep(): void {
		if ( get_transient( MVD_WAI_CTRL_LOCK_KEY ) ) {
			return;
		}
		set_transient( MVD_WAI_CTRL_LOCK_KEY, 1, self::LOCK_TTL );

		// Forza PMXI a caricare le sue classi admin-only anche nel contesto wp-cron.
		// Normalmente PMXI_Plugin::isAdminDashboardOrCronImport() richiede is_admin()=true
		// oppure la presenza di $_GET['import_key'] / $_GET['action'].
		// In cron entrambe le condizioni sono false, quindi forzare il filtro è necessario.
		// Aggiungiamo subito dopo il lock, prima di qualsiasi check, perché è necessario
		// anche se poi la funzione esce anticipatamente.
		add_filter( 'pmxi_is_admin_dashboard_or_cron_import', '__return_true' );

		if ( ! MvdWaiCtrlState::isRunning() ) {
			delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			return;
		}

		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			MvdWaiCtrlState::finishRun( 'error', __( 'WP All Import Pro non è attivo o la classe PMXI_Import_Record non è disponibile.', 'mvd-wai-ctrl' ) );
			delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			return;
		}

		$state            = MvdWaiCtrlState::get();
		$run_id           = (int) $state['run_id'];
		$current_idx      = (int) $state['current_index'];
		$ids              = MVD_WAI_CTRL_IDS;
		$import_id        = $ids[ $current_idx ] ?? null;
		$step_started_at  = (string) ( $state['current_step_started_at'] ?? '' );

		// Salvaguardia: indice fuori range (stato corrotto).
		if ( null === $import_id ) {
			MvdWaiCtrlLogger::closeRun( $run_id, 'success' );
			MvdWaiCtrlState::finishRun( 'completed', __( 'Tutte le importazioni completate.', 'mvd-wai-ctrl' ) );
			delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			return;
		}

		// Inizializzazione con fallback: sarà sovrascritta col nome reale dopo getById().
		$import_name = sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $import_id );
		$step_label  = '';

		$log_messages  = [];
		$logger        = static function ( string $msg ) use ( &$log_messages ): void {
			$log_messages[] = $msg;
			if ( count( $log_messages ) > 200 ) {
				array_shift( $log_messages );
			}
		};

		$step_start     = time();
		$is_first_chunk = 0 === (int) $state['current_step_total_chunks'];
		$schedule_next = false;
		$chain_done    = false;

		try {
			$import = new PMXI_Import_Record();
			$import->getById( $import_id );

			if ( $import->isEmpty() ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %d: ID dell'import non trovato */
						__( 'Import ID %d non trovato nel database di WP All Import.', 'mvd-wai-ctrl' ),
						$import_id
					)
				);
			}

			// Determina il nome leggibile dell'import dall'istanza già caricata.
			$friendly     = isset( $import->friendly_name ) ? trim( (string) $import->friendly_name ) : '';
			$import_name  = '' !== $friendly
				? $friendly
				: ( $import->name ?: sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $import_id ) );
			$step_label   = sprintf(
				/* translators: 1: numero passo, 2: totale passi, 3: nome import, 4: ID import */
				__( 'Passo %1$d di %2$d: %3$s (ID: %4$d)', 'mvd-wai-ctrl' ),
				$current_idx + 1,
				count( $ids ),
				$import_name,
				$import_id
			);

			// Al primo chunk: registra il timestamp di inizio e aggiorna il label.
			if ( $is_first_chunk ) {
				$step_started_at = MvdWaiCtrlState::markStepStart();
				MvdWaiCtrlState::updateStep(
					$current_idx,
					$step_label,
					__( 'Avvio importazione...', 'mvd-wai-ctrl' )
				);
			}

			$import->execute( $logger, false, false );

			// PMXI aggiorna queue_chunk_number sull'oggetto via set()->update():
			//   > 0 → time-limit raggiunto, ci sono ancora chunk da processare
			//   = 0 → import completato (o feed vuoto, che consideriamo completato)
			$q_chunk = (int) $import->queue_chunk_number;
			$count   = (int) $import->count;

			if ( $q_chunk > 0 ) {
				// Import non finito: aggiorna progresso e ri-schedula lo stesso step.
				MvdWaiCtrlState::updateChunk( $q_chunk, $count );
				MvdWaiCtrlState::updateStep(
					$current_idx,
					$step_label,
					sprintf(
						/* translators: 1: creati, 2: aggiornati, 3: saltati */
						__( 'Creati: %1$d | Aggiornati: %2$d | Saltati: %3$d', 'mvd-wai-ctrl' ),
						(int) $import->created,
						(int) $import->updated,
						(int) $import->skipped
					)
				);
				$schedule_next = true;
			} else {
				// Import completato: registra lo step e avanza.
				$duration_sec = $step_started_at
					? max( 0, time() - (int) strtotime( $step_started_at ) )
					: time() - $step_start;
				MvdWaiCtrlLogger::appendStep(
					$run_id,
					[
						'step_index'   => $current_idx,
						'import_id'    => $import_id,
						'import_name'  => $import_name,
						'outcome'      => 'success',
						'created'      => (int) $import->created,
						'updated'      => (int) $import->updated,
						'skipped'      => (int) $import->skipped,
						'duration_sec' => $duration_sec,
						'started_at'   => $step_started_at ?: current_time( 'mysql' ),
						'message'      => implode( "\n", array_slice( $log_messages, -50 ) ),
					]
				);

				MvdWaiCtrlState::updateStep(
					$current_idx,
					$step_label,
					sprintf(
						/* translators: 1: creati, 2: aggiornati, 3: saltati */
						__( 'Completato — Creati: %1$d | Aggiornati: %2$d | Saltati: %3$d', 'mvd-wai-ctrl' ),
						(int) $import->created,
						(int) $import->updated,
						(int) $import->skipped
					)
				);

				if ( MvdWaiCtrlState::advanceToNextImport() ) {
					$schedule_next = true;
				} else {
					$chain_done = true;
				}
			}
		} catch ( \Throwable $e ) {
			$err_duration = $step_started_at
				? max( 0, time() - (int) strtotime( $step_started_at ) )
				: time() - $step_start;
			MvdWaiCtrlLogger::appendStep(
				$run_id,
				[
					'step_index'   => $current_idx,
					'import_id'    => $import_id,
					'import_name'  => $import_name,
					'outcome'      => 'error',
					'duration_sec' => $err_duration,
					'started_at'   => $step_started_at ?: current_time( 'mysql' ),
					'message'      => $e->getMessage() . "\n" . implode( "\n", array_slice( $log_messages, -50 ) ),
				]
			);
			MvdWaiCtrlLogger::closeRun( $run_id, 'error' );
			MvdWaiCtrlState::finishRun( 'error', $e->getMessage() );
		}

		// Lock rilasciato sempre, dopo il try/catch, una sola volta.
		delete_transient( MVD_WAI_CTRL_LOCK_KEY );

		// Azioni post-step fuori dal try/catch: errori qui non corrompono lo stato dell'import.
		if ( $schedule_next ) {
			self::scheduleSelf();
		} elseif ( $chain_done ) {
			MvdWaiCtrlLogger::closeRun( $run_id, 'success' );
			MvdWaiCtrlState::finishRun( 'completed', __( 'Tutte le importazioni completate con successo.', 'mvd-wai-ctrl' ) );
		}
	}

	/**
	 * Schedula il prossimo step via loopback admin-ajax (path primario) con
	 * fallback su wp_schedule_single_event + spawn_cron.
	 *
	 * Genera un token monouso per autenticare il loopback e lo persiste come
	 * transient (TTL 60 s). Il token viene verificato e cancellato da ajaxRunChain().
	 *
	 * @return void
	 */
	public static function scheduleSelf(): void {
		$secret = wp_generate_password( 32, false );
		set_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret', $secret, MINUTE_IN_SECONDS );

		$result = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'body'      => [
					'action' => 'mvd_wai_ctrl_run_chain',
					'secret' => $secret,
				],
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'timeout'   => 0.01,
			]
		);

		if ( is_wp_error( $result ) ) {
			delete_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret' );
			wp_schedule_single_event( time() - 1, MVD_WAI_CTRL_CRON_HOOK );
			spawn_cron();
		}
	}
}
