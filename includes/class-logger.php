<?php
/**
 * Gestisce la tabella di log delle esecuzioni sequenziali.
 *
 * Ogni esecuzione (run) è identificata da un run_id.
 * Per ogni run vengono salvate N righe: una per il run stesso e una per ciascun passo.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe per la gestione del log persistente.
 */
class MvdWaiCtrlLogger {

	/**
	 * Nome della tabella (senza prefisso).
	 *
	 * @var string
	 */
	private static string $table_base = 'mvd_wai_ctrl_log';

	/**
	 * Restituisce il nome completo della tabella con prefisso wpdb.
	 *
	 * @return string
	 */
	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . self::$table_base;
	}

	/**
	 * Crea la tabella di log tramite dbDelta. Invocata all'attivazione del plugin.
	 *
	 * @return void
	 */
	public static function createTable(): void {
		global $wpdb;
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			run_id         BIGINT UNSIGNED  NOT NULL,
			is_run_header  TINYINT(1)       NOT NULL DEFAULT 0,
			step_index     TINYINT                   DEFAULT NULL,
			import_id      BIGINT UNSIGNED           DEFAULT NULL,
			import_name    VARCHAR(255)              DEFAULT NULL,
			outcome        VARCHAR(10)      NOT NULL DEFAULT 'start',
			created        INT UNSIGNED     NOT NULL DEFAULT 0,
			updated        INT UNSIGNED     NOT NULL DEFAULT 0,
			skipped        INT UNSIGNED     NOT NULL DEFAULT 0,
			duration_sec   INT UNSIGNED     NOT NULL DEFAULT 0,
			message        LONGTEXT                  DEFAULT NULL,
			started_at     DATETIME                  DEFAULT NULL,
			created_at     DATETIME         NOT NULL,
			PRIMARY KEY    (id),
			KEY idx_run_id         (run_id),
			KEY idx_run_header     (is_run_header, id),
			KEY idx_created_at     (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta non modifica colonne esistenti: rende step_index nullable sulle installazioni già attive.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN step_index TINYINT DEFAULT NULL" );

		// Migrazione idempotente: marca le righe legacy con step_index = -1 come run header
		// e azzera il sentinel ora che la colonna è nullable.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET is_run_header = 1, step_index = NULL WHERE step_index = -1 AND is_run_header = 0" );

		// Allinea l'option di versione DB (utile quando createTable è chiamata dall'activation hook).
		if ( defined( 'MVD_WAI_CTRL_DB_VERSION' ) ) {
			update_option( 'mvd_wai_ctrl_db_version', MVD_WAI_CTRL_DB_VERSION, false );
		}
	}

	/**
	 * Crea una nuova riga di avvio run e restituisce il run_id assegnato.
	 *
	 * @return int ID della riga appena inserita (usato come run_id).
	 */
	public static function createRun(): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::tableName(),
			[
				'run_id'        => 0,
				'is_run_header' => 1,
				'outcome'       => 'start',
				'started_at'    => $now,
				'created_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		$id = (int) $wpdb->insert_id;
		// Il run_id coincide con l'id della riga di apertura.
		$wpdb->update(
			self::tableName(),
			[ 'run_id' => $id ],
			[ 'id'     => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		return $id;
	}

	/**
	 * Aggiunge una riga di log per un singolo passo dell'esecuzione.
	 *
	 * @param int                  $run_id     ID del run corrente.
	 * @param array<string, mixed> $data       Dati del passo: step_index, import_id, outcome, created, updated, skipped, duration_sec, message.
	 * @return void
	 */
	public static function appendStep( int $run_id, array $data ): void {
		global $wpdb;
		$wpdb->insert(
			self::tableName(),
			[
				'run_id'       => $run_id,
				'is_run_header' => 0,
				'step_index'   => (int) ( $data['step_index']   ?? 0 ),
				'import_id'    => isset( $data['import_id'] ) ? (int) $data['import_id'] : null,
				'import_name'  => isset( $data['import_name'] ) ? substr( (string) $data['import_name'], 0, 255 ) : null,
				'outcome'      => sanitize_key( $data['outcome'] ?? 'error' ),
				'created'      => (int) ( $data['created']      ?? 0 ),
				'updated'      => (int) ( $data['updated']      ?? 0 ),
				'skipped'      => (int) ( $data['skipped']      ?? 0 ),
				'duration_sec' => (int) ( $data['duration_sec'] ?? 0 ),
				'message'      => isset( $data['message'] ) ? substr( (string) $data['message'], 0, 65535 ) : null,
				'started_at'   => isset( $data['started_at'] ) ? (string) $data['started_at'] : null,
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Chiude un run aggiornando la riga di apertura con l'esito finale.
	 *
	 * @param int    $run_id  ID del run.
	 * @param string $outcome Esito: 'success', 'error', 'aborted'.
	 * @return void
	 */
	public static function closeRun( int $run_id, string $outcome ): void {
		global $wpdb;
		$wpdb->update(
			self::tableName(),
			[ 'outcome'       => sanitize_key( $outcome ) ],
			[ 'id'            => $run_id, 'is_run_header' => 1 ],
			[ '%s' ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Restituisce l'elenco degli ultimi N run con i relativi step.
	 *
	 * @param int $limit Numero massimo di run da restituire.
	 * @return array<int, array<string, mixed>> Array di run, ciascuno con 'run' e 'steps'.
	 */
	public static function getRecentRuns( int $limit = 20 ): array {
		global $wpdb;
		$table = self::tableName();
		$limit = max( 1, min( $limit, 100 ) );

		// Recupera gli ultimi N run_id (righe header del run, is_run_header = 1).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$run_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, run_id, outcome, started_at, created_at FROM {$table} WHERE is_run_header = 1 ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $run_rows ) ) {
			return [];
		}

		$run_ids      = array_column( $run_rows, 'run_id' );
		$placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%d' ) );

		// Recupera tutti gli step dei run trovati (righe non-header).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$step_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT run_id, step_index, import_id, import_name, outcome, created, updated, skipped, duration_sec, message, started_at, created_at FROM {$table} WHERE run_id IN ({$placeholders}) AND is_run_header = 0 ORDER BY step_index ASC, id ASC",
				...$run_ids
			),
			ARRAY_A
		);

		// Indicizza gli step per run_id.
		$steps_by_run = [];
		foreach ( $step_rows as $step ) {
			$steps_by_run[ (int) $step['run_id'] ][] = $step;
		}

		$result = [];
		foreach ( $run_rows as $run ) {
			$result[] = [
				'run'   => $run,
				'steps' => $steps_by_run[ (int) $run['run_id'] ] ?? [],
			];
		}
		return $result;
	}
}
