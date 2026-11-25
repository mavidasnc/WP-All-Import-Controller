<?php
/**
 * Watchdog della catena: rileva i blocchi morti e li riprende automaticamente.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rileva una catena interrotta (lock di esecuzione libero e nessuna attività recente)
 * e la riprende da sola, fino a un numero massimo di tentativi sulla stessa posizione,
 * dimezzando i record per richiesta dell'import come fa WP All Import nei lanci manuali.
 *
 * Gira sia dentro il polling del pannello (ajaxStatus) sia da WP-Cron ogni minuto,
 * così la catena si ripara anche senza la pagina admin aperta.
 */
class MvdWaiCtrlWatchdog {

	/**
	 * Hook dell'evento WP-Cron ricorrente del watchdog.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'mvd_wai_ctrl_watchdog';

	/**
	 * Nome dell'intervallo WP-Cron di un minuto registrato dal plugin.
	 *
	 * @var string
	 */
	public const CRON_SCHEDULE = 'mvd_wai_ctrl_minute';

	/**
	 * Registra l'intervallo WP-Cron e il callback del watchdog.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'addCronSchedule' ], 10, 1 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cronTick' ], 10, 0 );
	}

	/**
	 * Aggiunge l'intervallo di un minuto alle schedule di WP-Cron.
	 *
	 * @param mixed $schedules Schedule registrate (array atteso, ma arriva da un filtro).
	 * @return array<string, mixed> Schedule con l'intervallo del plugin.
	 */
	public static function addCronSchedule( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : [];
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Ogni minuto (watchdog import sequenziale)', 'mvd-wai-ctrl' ),
		];
		return $schedules;
	}

	/**
	 * Pianifica l'evento ricorrente del watchdog se non è già pianificato.
	 *
	 * Va chiamato all'avvio e a ogni ripresa della catena; l'evento si cancella da solo
	 * quando la catena non è più in esecuzione.
	 *
	 * @return void
	 */
	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Callback WP-Cron: esegue il controllo o, a catena ferma, rimuove l'evento.
	 *
	 * @return void
	 */
	public static function cronTick(): void {
		if ( ! MvdWaiCtrlState::isRunning() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		self::check();
	}

	/**
	 * Controlla lo stato della catena e, se è morta, la riprende o la segna come interrotta.
	 *
	 * @return array{exec_alive: bool, elapsed: int, stalled_sec: int, action: string} Esito del controllo.
	 *               action: 'none', 'auto_resumed', 'crashed', 'busy' (un'altra richiesta sta già agendo).
	 */
	public static function check(): array {
		$state      = MvdWaiCtrlState::get();
		$exec_alive = ! MvdWaiCtrlRunner::isExecLockFree();
		$last_seen  = MvdWaiCtrlState::lastActivity( $state );
		$elapsed    = $last_seen > 0 ? time() - $last_seen : 0;
		$result     = [
			'exec_alive'  => $exec_alive,
			'elapsed'     => $elapsed,
			// Blocco vivo ma senza record salvati da molto: solo informativo, nessuna azione.
			'stalled_sec' => ( 'running' === $state['status'] && $exec_alive && $elapsed > MVD_WAI_CTRL_SLOW_THRESHOLD ) ? $elapsed : 0,
			'action'      => 'none',
		];

		if ( ! self::isDead( $state, $exec_alive, $last_seen, $elapsed ) ) {
			return $result;
		}

		// Polling del pannello e WP-Cron possono arrivare insieme: agisce solo chi prende il lock.
		if ( ! self::tryControlLock() ) {
			$result['action'] = 'busy';
			return $result;
		}

		// Rilettura dopo il lock: un'altra richiesta può aver già ripreso la catena.
		MvdWaiCtrlState::flushCache();
		$state      = MvdWaiCtrlState::get();
		$exec_alive = ! MvdWaiCtrlRunner::isExecLockFree();
		$last_seen  = MvdWaiCtrlState::lastActivity( $state );
		$elapsed    = $last_seen > 0 ? time() - $last_seen : 0;
		if ( ! self::isDead( $state, $exec_alive, $last_seen, $elapsed ) ) {
			return $result;
		}

		$run_id   = (int) $state['run_id'];
		$position = (int) $state['current_index'] . ':' . (int) $state['current_chunk_done'];
		$attempt  = MvdWaiCtrlState::nextAutoResumeAttempt( $position );
		/**
		 * Filtra il numero massimo di riprese automatiche consecutive sulla stessa posizione.
		 *
		 * @param int $max Valore di MVD_WAI_CTRL_AUTO_RESUME_MAX (0 = ripresa automatica disattivata).
		 */
		$max      = max( 0, (int) apply_filters( 'mvd_wai_ctrl_auto_resume_max', MVD_WAI_CTRL_AUTO_RESUME_MAX ) );

		if ( $attempt <= $max ) {
			$import_id = (int) ( MVD_WAI_CTRL_IDS[ (int) $state['current_index'] ] ?? 0 );
			/**
			 * Filtra se dimezzare i record per richiesta dell'import a ogni ripresa automatica.
			 *
			 * @param bool $halve Valore di MVD_WAI_CTRL_AUTO_HALVE_RECORDS.
			 * @param int  $import_id ID dell'import corrente.
			 */
			$halve     = (bool) apply_filters( 'mvd_wai_ctrl_auto_halve_records', MVD_WAI_CTRL_AUTO_HALVE_RECORDS, $import_id );
			$records   = ( $halve && $import_id > 0 ) ? MvdWaiCtrlRunner::halveRecordsPerRequest( $import_id ) : null;

			$message = sprintf(
				/* translators: 1: tentativo, 2: massimo tentativi, 3: secondi senza attività */
				__( 'Ripresa automatica %1$d di %2$d: blocco interrotto, nessuna attività da %3$d secondi.', 'mvd-wai-ctrl' ),
				$attempt,
				$max,
				$elapsed
			);
			if ( null !== $records && $records['after'] !== $records['before'] ) {
				$message .= ' ' . sprintf(
					/* translators: 1: record per richiesta prima, 2: record per richiesta dopo */
					__( 'Record per richiesta ridotti da %1$d a %2$d.', 'mvd-wai-ctrl' ),
					$records['before'],
					$records['after']
				);
			}

			MvdWaiCtrlLogger::writeFile( 'WARNING', $message, [ 'run_id' => $run_id, 'position' => $position ] );
			self::performResume( $message );
			$result['action'] = 'auto_resumed';
			return $result;
		}

		$reason = sprintf(
			/* translators: 1: secondi senza attività, 2: numero di riprese automatiche già tentate */
			__( 'Catena interrotta: nessun blocco in esecuzione e nessuna attività da %1$d secondi (riprese automatiche esaurite: %2$d).', 'mvd-wai-ctrl' ),
			$elapsed,
			$max
		);
		MvdWaiCtrlLogger::markRunCrashed( $run_id, $reason );
		MvdWaiCtrlLogger::writeFile( 'ERROR', $reason, [ 'run_id' => $run_id, 'position' => $position ] );
		MvdWaiCtrlState::markCrashed( $reason );
		$result['action'] = 'crashed';
		return $result;
	}

	/**
	 * Riprende la catena dal punto corrente: azzera i flag PMXI residui, registra la ripresa
	 * nel log e lancia un nuovo hop con un nuovo chain_id.
	 *
	 * Il chiamante deve aver verificato che il lock di esecuzione sia libero e deve detenere
	 * il lock di controllo.
	 *
	 * @param string $message Messaggio della ripresa (log del run e ultimo messaggio dello stato).
	 * @return void
	 */
	public static function performResume( string $message ): void {
		$state      = MvdWaiCtrlState::get();
		$run_id     = (int) $state['run_id'];
		$step_index = (int) $state['current_index'];
		$import_id  = isset( MVD_WAI_CTRL_IDS[ $step_index ] ) ? (int) MVD_WAI_CTRL_IDS[ $step_index ] : null;

		// Lock di esecuzione libero: processing=1 è un residuo del processo morto.
		// queue_chunk_number e i counter restano intatti, così PMXI riprende da dove si era fermato.
		if ( null !== $import_id ) {
			$stop_result = MvdWaiCtrlRunner::forceStopPmxiCron( $import_id );
			if ( $stop_result['ok'] ) {
				$before = $stop_result['before'];
				MvdWaiCtrlLogger::appendStep(
					$run_id,
					[
						'step_index' => $step_index,
						'import_id'  => $import_id,
						'outcome'    => 'info',
						'message'    => sprintf(
							/* translators: 1: ID import, 2: triggered prima del reset, 3: processing prima del reset, 4: executing prima del reset */
							__( 'Reset flag PMXI runtime prima del resume (import_id=%1$d, before: triggered=%2$d, processing=%3$d, executing=%4$d).', 'mvd-wai-ctrl' ),
							$import_id,
							(int) $before['triggered'],
							(int) $before['processing'],
							(int) $before['executing']
						),
					]
				);
				MvdWaiCtrlLogger::writeFile(
					'INFO',
					sprintf( 'forceStopPmxiCron import_id=%d', $import_id ),
					[
						'run_id' => $run_id,
						'before' => $before,
					]
				);
			}
		}

		MvdWaiCtrlLogger::appendStep(
			$run_id,
			[
				'step_index' => $step_index,
				'outcome'    => 'start',
				'message'    => $message,
			]
		);

		MvdWaiCtrlState::markResumeRequested( $message );
		self::ensureScheduled();
		MvdWaiCtrlRunner::scheduleSelf();
	}

	/**
	 * Tenta di acquisire il lock MySQL delle azioni di controllo senza attesa.
	 *
	 * Il lock è legato alla connessione della richiesta e viene rilasciato da MySQL a fine richiesta.
	 *
	 * @return bool True se acquisito.
	 */
	public static function tryControlLock(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lock MySQL, nessuna API WP equivalente.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', MvdWaiCtrlRunner::execLockName() . '_ctl' ) );
		return '1' === (string) $acquired;
	}

	/**
	 * Indica se la catena risulta morta: in esecuzione per lo stato, ma senza processi vivi
	 * e senza attività da oltre MVD_WAI_CTRL_WATCHDOG_THRESHOLD secondi.
	 *
	 * @param array<string, mixed> $state      Stato corrente.
	 * @param bool                 $exec_alive True se un processo detiene il lock di esecuzione.
	 * @param int                  $last_seen  Timestamp dell'ultima attività (0 = mai).
	 * @param int                  $elapsed    Secondi trascorsi dall'ultima attività.
	 * @return bool                            True se la catena va ripresa o segnata come interrotta.
	 */
	private static function isDead( array $state, bool $exec_alive, int $last_seen, int $elapsed ): bool {
		return 'running' === $state['status']
			&& ! $exec_alive
			&& $last_seen > 0
			&& $elapsed > MVD_WAI_CTRL_WATCHDOG_THRESHOLD;
	}
}
