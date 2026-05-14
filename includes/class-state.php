<?php
/**
 * Gestisce lo stato corrente dell'esecuzione sequenziale.
 *
 * Wrapper sull'option WordPress MVD_WAI_CTRL_STATE_OPTION.
 * L'option ha autoload = 'no' per non gravare sull'object cache globale.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe di gestione dello stato di esecuzione.
 */
class MvdWaiCtrlState {

	/**
	 * Struttura di stato vuota (idle).
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultState(): array {
		return [
			'status'               => 'idle',
			'run_id'               => 0,
			'step_current'         => 0,
			'step_total'           => count( MVD_WAI_CTRL_IDS ),
			'step_label'           => '',
			'current_index'        => 0,
			'current_chunk'        => 0,
			'current_total_chunks' => 0,
			'started_at'           => '',
			'updated_at'           => '',
			'finished_at'          => null,
			'last_message'         => '',
		];
	}

	/**
	 * Legge lo stato corrente dal database.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$raw = get_option( MVD_WAI_CTRL_STATE_OPTION, null );
		if ( ! is_array( $raw ) ) {
			return self::defaultState();
		}
		return array_merge( self::defaultState(), $raw );
	}

	/**
	 * Salva lo stato nel database.
	 *
	 * @param array<string, mixed> $state Array di stato da persistere.
	 * @return void
	 */
	public static function save( array $state ): void {
		$state['updated_at'] = current_time( 'mysql' );
		update_option( MVD_WAI_CTRL_STATE_OPTION, $state, false );
	}

	/**
	 * Resetta lo stato a idle.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::save( self::defaultState() );
	}

	/**
	 * Verifica se è in corso un'esecuzione.
	 *
	 * @return bool
	 */
	public static function isRunning(): bool {
		return 'running' === self::get()['status'];
	}

	/**
	 * Inizializza lo stato per una nuova esecuzione.
	 *
	 * @param int $run_id ID della riga di log associata a questa esecuzione.
	 * @return void
	 */
	public static function startRun( int $run_id ): void {
		self::save(
			array_merge(
				self::defaultState(),
				[
					'status'               => 'running',
					'run_id'               => $run_id,
					'current_index'        => 0,
					'current_chunk'        => 0,
					'current_total_chunks' => 0,
					'started_at'           => current_time( 'mysql' ),
					'finished_at'          => null,
				]
			)
		);
	}

	/**
	 * Aggiorna lo stato con il passo corrente.
	 *
	 * @param int    $step_index Indice 0-based del passo corrente.
	 * @param string $label      Etichetta human-readable del passo.
	 * @param string $message    Ultimo messaggio di log dal passo.
	 * @return void
	 */
	public static function updateStep( int $step_index, string $label, string $message ): void {
		$state                  = self::get();
		$state['step_current']  = $step_index + 1;
		$state['step_label']    = $label;
		$state['last_message']  = $message;
		self::save( $state );
	}

	/**
	 * Aggiorna le informazioni sui chunk dell'import corrente (per il progresso granulare).
	 *
	 * @param int $chunk       Numero chunk corrente (queue_chunk_number da PMXI).
	 * @param int $total       Totale record dell'import (count da PMXI).
	 * @return void
	 */
	public static function updateChunk( int $chunk, int $total ): void {
		$state                          = self::get();
		$state['current_chunk']         = $chunk;
		$state['current_total_chunks']  = $total;
		self::save( $state );
	}

	/**
	 * Avanza al prossimo import nella sequenza.
	 *
	 * Incrementa current_index e resetta i dati di chunk per il nuovo import.
	 * Ritorna false se la chain è terminata (current_index supera l'ultimo).
	 *
	 * @return bool True se esiste un prossimo import, false se la chain è finita.
	 */
	public static function advanceToNextImport(): bool {
		$state      = self::get();
		$next_index = (int) $state['current_index'] + 1;

		if ( $next_index >= count( MVD_WAI_CTRL_IDS ) ) {
			return false;
		}

		$state['current_index']        = $next_index;
		$state['current_chunk']        = 0;
		$state['current_total_chunks'] = 0;
		self::save( $state );

		return true;
	}

	/**
	 * Imposta lo stato finale dell'esecuzione (completed o error).
	 *
	 * @param string $status     'completed' o 'error'.
	 * @param string $message    Messaggio finale opzionale.
	 * @return void
	 */
	public static function finishRun( string $status, string $message = '' ): void {
		$state                = self::get();
		$state['status']      = $status;
		$state['finished_at'] = current_time( 'mysql' );
		if ( $message ) {
			$state['last_message'] = $message;
		}
		self::save( $state );
	}
}
