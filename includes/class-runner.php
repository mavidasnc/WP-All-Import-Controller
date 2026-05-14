<?php
/**
 * Esegue la catena di importazioni sequenziali.
 *
 * Viene invocato dall'hook cron one-time MVD_WAI_CTRL_CRON_HOOK.
 * La sequenza è sincrona: il Passo N inizia solo dopo il completamento del Passo N-1.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe che gestisce l'esecuzione sequenziale degli import.
 */
class MvdWaiCtrlRunner {

	/**
	 * Durata massima del lock anti-doppio-avvio (secondi).
	 *
	 * @var int
	 */
	private const LOCK_TTL = 600;

	/**
	 * Avvia la catena di importazioni. Punto di ingresso dell'hook cron.
	 *
	 * @return void
	 */
	public static function runChain(): void {
		// Lock anti-doppio-avvio: se il transient esiste, un'altra esecuzione è già in corso.
		if ( get_transient( MVD_WAI_CTRL_LOCK_KEY ) ) {
			return;
		}
		set_transient( MVD_WAI_CTRL_LOCK_KEY, 1, self::LOCK_TTL );

		// Verifica coerenza dello stato: deve essere 'running'.
		if ( ! MvdWaiCtrlState::isRunning() ) {
			delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			return;
		}

		// Verifica che WP All Import Pro sia disponibile.
		// Normalmente garantito dal loopback (admin-ajax, is_admin() = true).
		// Se il runner è stato invocato via cron di fallback, PMXI potrebbe non essere caricato.
		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			MvdWaiCtrlState::finishRun( 'error', __( 'WP All Import Pro non è attivo o la classe PMXI_Import_Record non è disponibile.', 'mvd-wai-ctrl' ) );
			delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			return;
		}

		$run_id  = (int) MvdWaiCtrlState::get()['run_id'];
		$ids     = MVD_WAI_CTRL_IDS;
		$outcome = 'success';

		foreach ( $ids as $step_index => $import_id ) {
			$step_label  = sprintf(
				/* translators: 1: numero passo, 2: totale passi, 3: ID import */
				__( 'Passo %1$d di %2$d (Import ID: %3$d)', 'mvd-wai-ctrl' ),
				$step_index + 1,
				count( $ids ),
				$import_id
			);

			MvdWaiCtrlState::updateStep( $step_index, $step_label, __( 'Avvio importazione...', 'mvd-wai-ctrl' ) );

			$log_messages = [];
			$logger       = static function ( string $msg ) use ( &$log_messages ): void {
				$log_messages[] = $msg;
				// Mantiene in memoria solo gli ultimi 200 messaggi per evitare eccesso di RAM.
				if ( count( $log_messages ) > 200 ) {
					array_shift( $log_messages );
				}
			};

			$step_start = time();
			$step_error = null;

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

				$import->execute( $logger, false, true );

				// Aggiorna l'ultimo messaggio di stato con il termine del passo.
				MvdWaiCtrlState::updateStep(
					$step_index,
					$step_label,
					sprintf(
						/* translators: 1: creati, 2: aggiornati, 3: saltati */
						__( 'Completato — Creati: %1$d | Aggiornati: %2$d | Saltati: %3$d', 'mvd-wai-ctrl' ),
						(int) ( $import->created ?? 0 ),
						(int) ( $import->updated ?? 0 ),
						(int) ( $import->skipped ?? 0 )
					)
				);

				MvdWaiCtrlLogger::appendStep(
					$run_id,
					[
						'step_index'  => $step_index,
						'import_id'   => $import_id,
						'outcome'     => 'success',
						'created'     => (int) ( $import->created ?? 0 ),
						'updated'     => (int) ( $import->updated ?? 0 ),
						'skipped'     => (int) ( $import->skipped ?? 0 ),
						'duration_sec' => time() - $step_start,
						'message'     => implode( "\n", array_slice( $log_messages, -50 ) ),
					]
				);

			} catch ( \Throwable $e ) {
				$step_error = $e->getMessage();
			}

			if ( null !== $step_error ) {
				MvdWaiCtrlLogger::appendStep(
					$run_id,
					[
						'step_index'  => $step_index,
						'import_id'   => $import_id,
						'outcome'     => 'error',
						'duration_sec' => time() - $step_start,
						'message'     => $step_error . "\n" . implode( "\n", array_slice( $log_messages, -50 ) ),
					]
				);
				MvdWaiCtrlLogger::closeRun( $run_id, 'error' );
				MvdWaiCtrlState::finishRun( 'error', $step_error );
				$outcome = 'error';
				break;
			}
		}

		if ( 'success' === $outcome ) {
			MvdWaiCtrlLogger::closeRun( $run_id, 'success' );
			MvdWaiCtrlState::finishRun( 'completed', __( 'Tutte le importazioni completate con successo.', 'mvd-wai-ctrl' ) );
		}

		delete_transient( MVD_WAI_CTRL_LOCK_KEY );
	}
}
