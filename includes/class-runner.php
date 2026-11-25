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
	 * Intervallo minimo (secondi) tra due campionamenti della sonda PROCESSLIST.
	 *
	 * @var int
	 */
	private const PROCESSLIST_PROBE_INTERVAL = 30;

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
			// Traccia diagnostica: un secondo hop è arrivato mentre il lock era occupato.
			MvdWaiCtrlLogger::writeFile( 'INFO', 'runStep SKIP: lock occupato', [ 'pid' => getmypid() ] );
			return;
		}
		set_transient( MVD_WAI_CTRL_LOCK_KEY, 1, self::LOCK_TTL );

		// Shutdown handler: intercetta fatal error / OOM prima che il processo muoia.
		// Viene registrato subito dopo l'acquisizione del lock per catturare qualsiasi
		// crash che avvenga da questo punto in poi, inclusi quelli dentro execute().
		register_shutdown_function(
			static function (): void {
				$err = error_get_last();
				$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
				if ( null === $err || ! ( $err['type'] & $fatal_types ) ) {
					return;
				}
				$state = MvdWaiCtrlState::get();
				// Interviene solo se il run è ancora marcato 'running' (evita doppia scrittura
				// nel caso in cui il try/catch abbia già chiuso il run prima del fatal).
				if ( 'running' !== $state['status'] ) {
					return;
				}
				$run_id  = (int) $state['run_id'];
				$message = sprintf(
					/* translators: 1: tipo errore PHP, 2: messaggio, 3: file, 4: riga */
					'Fatal PHP %1$s: %2$s in %3$s:%4$d',
					$err['type'],
					$err['message'],
					$err['file'],
					$err['line']
				);
				MvdWaiCtrlLogger::markRunCrashed( $run_id, $message );
				MvdWaiCtrlLogger::writeFile( 'FATAL', $message, [ 'run_id' => $run_id ] );
				MvdWaiCtrlState::markCrashed( $message );
				delete_transient( MVD_WAI_CTRL_LOCK_KEY );
			}
		);

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
		// È il primo chunk se non abbiamo ancora totale chunk (0 = non ancora iniziato).
		// In caso di resume, $state['resuming'] è true: saltiamo il reset dei counter PMXI
		// così PMXI riprende dal queue_chunk_number salvato nel suo record DB.
		$is_first_chunk = 0 === (int) $state['current_step_total_chunks'];
		$is_resuming    = ! empty( $state['resuming'] );
		$schedule_next  = false;
		$chain_done     = false;
		// Numero di history da conservare per import (opzione nativa WPAI, default 5).
		$log_storage    = class_exists( 'PMXI_Plugin' ) ? (int) PMXI_Plugin::getInstance()->getOption( 'log_storage' ) : 0;

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

			// Al primo chunk di un import nuovo: resetta i counter PMXI.
			// In caso di resume ($is_resuming=true), i counter PMXI restano intatti:
			// queue_chunk_number indica dove PMXI si era fermato e execute() riprenderà
			// da quel punto automaticamente (PMXI record.php:332-336).
			if ( $is_first_chunk && ! $is_resuming ) {
				// PMXI riusa lo stesso record per ogni esecuzione: senza reset dei
				// counter, una seconda chiamata a execute() esce senza processare nulla
				// perché processing=1 stuck o imported>=count soddisfano subito la
				// condizione di "completato" (record.php:117/295/377).
				// Mirror del pattern usato da PMXI CLI (classes/cli.php:62-71).
				$import->set(
					[
						'triggered'          => 1,
						'imported'           => 0,
						'canceled'           => 0,
						'created'            => 0,
						'updated'            => 0,
						'skipped'            => 0,
						'queue_chunk_number' => 0,
						'processing'         => 0,
					]
				)->update();

				// Cleanup history eccedenti log_storage per questo import (pattern src/Scheduling/Import.php:37-49).
				if ( $log_storage > 0 && class_exists( 'PMXI_History_List' ) ) {
					$history_cleanup = new PMXI_History_List();
					$history_cleanup->setColumns( 'id' )->getBy( [ 'import_id' => $import->id ], 'id ASC' );
					if ( $history_cleanup->count() >= $log_storage ) {
						$logs_to_remove = $history_cleanup->count() - $log_storage;
						$i              = 0;
						foreach ( $history_cleanup as $old_file ) {
							$old_rec = new PMXI_History_Record();
							$old_rec->getBy( 'id', $old_file['id'] );
							if ( ! $old_rec->isEmpty() ) {
								$old_rec->delete();
							}
							if ( $i++ >= $logs_to_remove ) {
								break;
							}
						}
					}
				}

				// Crea il record history nativo di WP All Import per questo step.
				// Type 'processing': allineato a src/Scheduling/Import.php:58 (esecuzione da loopback/cron).
				if ( class_exists( 'PMXI_History_Record' ) ) {
					$history_log = new PMXI_History_Record();
					$history_log->set(
						[
							'import_id' => $import->id,
							'date'      => date( 'Y-m-d H:i:s' ),
							'type'      => 'processing',
							'summary'   => __( 'mvd controller processing', 'mvd-wai-ctrl' ),
						]
					)->save();
					MvdWaiCtrlState::setStepHistoryLogId( (int) $history_log->id );
				}

				$step_started_at = MvdWaiCtrlState::markStepStart();
				MvdWaiCtrlState::updateStep(
					$current_idx,
					$step_label,
					__( 'Avvio importazione...', 'mvd-wai-ctrl' )
				);
			} elseif ( $is_first_chunk ) { // Qui $is_resuming è necessariamente true.
				// Resume: azzera il flag dopo la prima esecuzione, ma mantieni i counter PMXI.
				// Aggiorna comunque lo step_label e riprendi il timestamp precedente.
				// Se il crash è avvenuto prima della creazione dell'history record (history_log_id = 0),
				// ne creiamo uno nuovo per garantire che il log del resume sia tracciato.
				if ( 0 === MvdWaiCtrlState::getStepHistoryLogId() && class_exists( 'PMXI_History_Record' ) ) {
					$history_log = new PMXI_History_Record();
					$history_log->set(
						[
							'import_id' => $import->id,
							'date'      => date( 'Y-m-d H:i:s' ),
							'type'      => 'processing',
							'summary'   => __( 'mvd controller processing (resume)', 'mvd-wai-ctrl' ),
						]
					)->save();
					MvdWaiCtrlState::setStepHistoryLogId( (int) $history_log->id );
				}

				$step_started_at = MvdWaiCtrlState::markStepStart();
				MvdWaiCtrlState::updateStep(
					$current_idx,
					$step_label,
					__( 'Ripresa importazione...', 'mvd-wai-ctrl' )
				);
				MvdWaiCtrlState::clearResuming();
			} elseif ( $is_resuming ) {
				// Resume a metà passo (chunk già contati): il reset dei counter non si applica comunque,
				// ma il flag va azzerato subito, altrimenti si trascina all'import successivo e
				// quell'import salterebbe il reset dei counter PMXI chiudendosi senza elaborare nulla.
				MvdWaiCtrlState::clearResuming();
			}

			// Traccia diagnostica di inizio hop: PID e posizione PMXI permettono di verificare
			// che due hop non lavorino mai in contemporanea.
			$q_chunk_before = (int) $import->queue_chunk_number;
			MvdWaiCtrlLogger::writeFile(
				'INFO',
				'runStep START',
				[
					'pid'         => getmypid(),
					'run_id'      => $run_id,
					'import_id'   => $import_id,
					'q_chunk'     => $q_chunk_before,
					'processing'  => (int) ( $import->processing ?? 0 ),
					'first_chunk' => $is_first_chunk,
					'resuming'    => $is_resuming,
				]
			);
			self::attachProcesslistProbe( $run_id, $import_id );

			// Prepara il path del file HTML di log nativo WPAI per questo step.
			// Tutti i chunk dello stesso import appendono allo stesso file (fopen 'a+').
			$history_log_id = MvdWaiCtrlState::getStepHistoryLogId();
			$log_file_path  = '';
			if ( $log_storage > 0 && $history_log_id > 0 && function_exists( 'wp_all_import_secure_file' ) ) {
				$wp_uploads    = wp_upload_dir();
				$log_file_path = wp_all_import_secure_file(
					$wp_uploads['basedir'] . DIRECTORY_SEPARATOR . PMXI_Plugin::LOGS_DIRECTORY,
					$history_log_id
				) . DIRECTORY_SEPARATOR . $history_log_id . '.html';
			}

			// Esegue l'import bufferizzando l'output per il file HTML di log WPAI
			// (pattern src/Scheduling/Import.php:71-88). Il finally garantisce che
			// ob_get_clean() venga chiamato anche se execute() lancia un'eccezione.
			$log_html    = '';
			$exec_result = null;
			ob_start();
			try {
				$exec_result = $import->execute( $logger, true, $history_log_id > 0 ? $history_log_id : false );
			} finally {
				$log_html = (string) ob_get_clean();
				self::detachProcesslistProbe();
			}

			if ( '' !== $log_file_path && '' !== $log_html ) {
				$fh = @fopen( $log_file_path, 'a+' );
				if ( is_resource( $fh ) ) {
					@fwrite( $fh, $log_html );
					@fclose( $fh );
				}
			}

			// PMXI aggiorna queue_chunk_number sull'oggetto via set()->update():
			//   > 0 → time-limit raggiunto, ci sono ancora chunk da processare
			//   = 0 → import completato (o feed vuoto, che consideriamo completato)
			$q_chunk = (int) $import->queue_chunk_number;
			$count   = (int) $import->count;

			// execute() restituisce un array status/message quando PMXI si ferma senza elaborare
			// (feed vuoto, "No matching elements", upload fallito, rifiuto licenza).
			$exec_warning = self::extractExecuteWarning( $exec_result );

			MvdWaiCtrlLogger::writeFile(
				null === $exec_warning ? 'INFO' : 'WARNING',
				'runStep END',
				array_filter(
					[
						'pid'          => getmypid(),
						'run_id'       => $run_id,
						'import_id'    => $import_id,
						'q_chunk'      => $q_chunk_before . '->' . $q_chunk,
						'count'        => $count,
						'duration_sec' => time() - $step_start,
						'peak_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
						'wpai_status'  => $exec_warning,
					],
					static fn( $v ): bool => null !== $v
				)
			);

			if ( null !== $exec_warning && $q_chunk > 0 ) {
				// PMXI ha rifiutato l'esecuzione lasciando chunk pendenti: ri-schedulare
				// produrrebbe un ciclo infinito di hop a vuoto, quindi la catena si ferma.
				throw new \RuntimeException( $exec_warning );
			}

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
				// Se PMXI ha restituito uno status diverso da 200 lo step è un avviso,
				// ma la catena prosegue con l'import successivo.
				$duration_sec = $step_started_at
					? self::secondsSinceLocal( $step_started_at )
					: time() - $step_start;
				$step_message = implode( "\n", array_slice( $log_messages, -50 ) );
				if ( null !== $exec_warning ) {
					$step_message = sprintf(
						/* translators: %s: messaggio restituito da WP All Import */
						__( 'WP All Import non ha elaborato record: %s', 'mvd-wai-ctrl' ),
						$exec_warning
					) . ( '' !== $step_message ? "\n" . $step_message : '' );
				}
				MvdWaiCtrlLogger::appendStep(
					$run_id,
					[
						'step_index'   => $current_idx,
						'import_id'    => $import_id,
						'import_name'  => $import_name,
						'outcome'      => null === $exec_warning ? 'success' : 'warning',
						'created'      => (int) $import->created,
						'updated'      => (int) $import->updated,
						'skipped'      => (int) $import->skipped,
						'duration_sec' => $duration_sec,
						'started_at'   => $step_started_at ?: current_time( 'mysql' ),
						'message'      => $step_message,
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
			self::detachProcesslistProbe();
			$err_duration = $step_started_at
				? self::secondsSinceLocal( $step_started_at )
				: time() - $step_start;
			$err_message  = $e->getMessage() . "\n" . implode( "\n", array_slice( $log_messages, -50 ) );
			MvdWaiCtrlLogger::appendStep(
				$run_id,
				[
					'step_index'   => $current_idx,
					'import_id'    => $import_id,
					'import_name'  => $import_name,
					'outcome'      => 'error',
					'duration_sec' => $err_duration,
					'started_at'   => $step_started_at ?: current_time( 'mysql' ),
					'message'      => $err_message,
				]
			);
			MvdWaiCtrlLogger::closeRun( $run_id, 'error' );
			MvdWaiCtrlLogger::writeFile(
				'ERROR',
				$e->getMessage(),
				[
					'run_id'     => $run_id,
					'step_index' => $current_idx,
					'import_id'  => $import_id,
					'exception'  => get_class( $e ),
				]
			);
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
	 * Resetta i flag runtime PMXI per un import prima di riprendere la chain.
	 *
	 * Azzera triggered, processing, executing nella tabella {prefix}pmxi_imports
	 * senza toccare queue_chunk_number e gli altri counter, così PMXI può riprendere
	 * dal chunk salvato senza essere bloccato da un cron ancora marcato come attivo.
	 *
	 * @param int $import_id ID dell'import PMXI su cui operare.
	 * @return array{ok: bool, before?: array<string,mixed>} Stato dei flag prima del reset.
	 */
	public static function forceStopPmxiCron( int $import_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pmxi_imports';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT triggered, processing, executing FROM {$table} WHERE id = %d",
				$import_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			return [ 'ok' => false ];
		}
		$wpdb->update(
			$table,
			[
				'triggered'  => 0,
				'processing' => 0,
				'executing'  => 0,
			],
			[ 'id' => $import_id ],
			[ '%d', '%d', '%d' ],
			[ '%d' ]
		);
		return [ 'ok' => true, 'before' => (array) $row ];
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
			MvdWaiCtrlLogger::writeFile(
				'WARNING',
				'scheduleSelf: loopback fallito, fallback su wp-cron',
				[
					'pid'   => getmypid(),
					'error' => $result->get_error_message(),
				]
			);
			delete_transient( MVD_WAI_CTRL_LOCK_KEY . '_secret' );
			wp_schedule_single_event( time() - 1, MVD_WAI_CTRL_CRON_HOOK );
			spawn_cron();
		}
	}

	/**
	 * Estrae il messaggio di avviso dal valore restituito da PMXI_Import_Record::execute().
	 *
	 * PMXI restituisce l'istanza stessa quando elabora normalmente, oppure un array
	 * ['status' => int, 'message' => string|array] quando si ferma (feed vuoto,
	 * nessun elemento per l'XPath, upload fallito, rifiuto licenza).
	 *
	 * @param mixed $exec_result Valore restituito da execute().
	 * @return string|null       Messaggio "status N: testo" se lo status non è 200, altrimenti null.
	 */
	public static function extractExecuteWarning( $exec_result ): ?string {
		if ( ! is_array( $exec_result ) || ! isset( $exec_result['status'] ) ) {
			return null;
		}
		$status = (int) $exec_result['status'];
		if ( 200 === $status ) {
			return null;
		}
		$message = $exec_result['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = implode( ' | ', array_map( 'strval', $message ) );
		}
		return sprintf( 'status %d: %s', $status, wp_strip_all_tags( (string) $message ) );
	}

	/**
	 * Calcola i secondi trascorsi da un datetime MySQL espresso nel fuso orario del sito.
	 *
	 * I timestamp di step sono salvati con current_time('mysql') (ora locale del sito):
	 * confrontarli con time() via strtotime() introduceva uno scarto pari all'offset UTC.
	 *
	 * @param string $local_mysql Datetime 'Y-m-d H:i:s' nel fuso del sito.
	 * @return int                Secondi trascorsi (mai negativi).
	 */
	public static function secondsSinceLocal( string $local_mysql ): int {
		try {
			$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$start = new \DateTimeImmutable( $local_mysql, $tz );
		} catch ( \Exception $e ) {
			return 0;
		}
		return max( 0, time() - $start->getTimestamp() );
	}

	/**
	 * Callback attiva della sonda PROCESSLIST (null se non agganciata).
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $processlist_probe = null;

	/**
	 * Aggancia a pmxi_saved_post una sonda diagnostica che, al massimo ogni
	 * PROCESSLIST_PROBE_INTERVAL secondi, scrive nel file log le altre query attive
	 * sullo stesso utente e sullo stesso database (l'utente MySQL è condiviso con altri siti). Serve a identificare chi scrive in parallelo all'import
	 * quando compaiono deadlock (l'utente DB non ha il privilegio PROCESS, ma vede
	 * comunque le proprie connessioni).
	 *
	 * @param int $run_id    ID del run corrente.
	 * @param int $import_id ID dell'import in esecuzione.
	 * @return void
	 */
	private static function attachProcesslistProbe( int $run_id, int $import_id ): void {
		$last_probe = time();

		self::$processlist_probe = static function () use ( $run_id, $import_id, &$last_probe ): void {
			if ( ( time() - $last_probe ) < self::PROCESSLIST_PROBE_INTERVAL ) {
				return;
			}
			$last_probe = time();

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- diagnostica, nessuna API WP equivalente.
			$rows = $wpdb->get_results(
				"SELECT ID, COMMAND, TIME, STATE, LEFT(INFO, 200) AS INFO FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND COMMAND NOT IN ('Sleep', 'Daemon') AND INFO IS NOT NULL",
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				return;
			}
			MvdWaiCtrlLogger::writeFile(
				'INFO',
				'PROCESSLIST query concorrenti',
				[
					'pid'       => getmypid(),
					'run_id'    => $run_id,
					'import_id' => $import_id,
					'rows'      => $rows,
				]
			);
		};

		add_action( 'pmxi_saved_post', self::$processlist_probe, 99, 0 );
	}

	/**
	 * Sgancia la sonda PROCESSLIST da pmxi_saved_post, se presente.
	 *
	 * @return void
	 */
	private static function detachProcesslistProbe(): void {
		if ( null === self::$processlist_probe ) {
			return;
		}
		remove_action( 'pmxi_saved_post', self::$processlist_probe, 99 );
		self::$processlist_probe = null;
	}
}
