<?php
/**
 * Stub di PMXI_History_Record e PMXI_History_List per PHPStan e PHPUnit.
 *
 * Replica la firma minima delle classi reali usata da MvdWaiCtrlRunner:
 * set(), save(), getBy(), isEmpty(), delete() per PMXI_History_Record;
 * setColumns(), getBy(), count() per PMXI_History_List.
 */

if ( ! class_exists( 'PMXI_History_Record' ) ) {

	class PMXI_History_Record { // phpcs:ignore

		public int    $id   = 0;
		public string $type = '';

		/**
		 * Imposta i dati del record prima del salvataggio.
		 *
		 * @param array<string, mixed> $data Campi da impostare (import_id, date, type, summary, ecc.).
		 * @return static
		 */
		public function set( array $data ): static {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
			return $this;
		}

		/**
		 * Persiste il record nel database.
		 *
		 * @return static
		 */
		public function save(): static {
			return $this;
		}

		/**
		 * Carica il record per chiave/valore.
		 *
		 * @param string|int        $column Colonna (es. 'id') o valore diretto se $value omesso.
		 * @param int|string|null   $value  Valore da cercare.
		 * @return static
		 */
		public function getBy( $column, $value = null ): static {
			return $this;
		}

		/**
		 * Carica il record per ID primario.
		 *
		 * @param int $id ID del record.
		 * @return static
		 */
		public function getById( int $id ): static {
			return $this;
		}

		/**
		 * Indica se il record non è stato trovato nel database.
		 *
		 * @return bool
		 */
		public function isEmpty(): bool {
			return 0 === $this->id;
		}

		/**
		 * Elimina il record e il file HTML di log associato.
		 *
		 * @param bool $db Se true, elimina anche dal DB.
		 * @return bool
		 */
		public function delete( bool $db = true ): bool {
			return true;
		}
	}
}

if ( ! class_exists( 'PMXI_History_List' ) ) {

	/**
	 * @implements \IteratorAggregate<int, array<string, mixed>>
	 */
	class PMXI_History_List implements \IteratorAggregate { // phpcs:ignore

		/** @var array<int, array<string, mixed>> */
		private array $records = [];

		/**
		 * Seleziona le colonne da restituire.
		 *
		 * @param string ...$columns Nomi delle colonne.
		 * @return static
		 */
		public function setColumns( string ...$columns ): static {
			return $this;
		}

		/**
		 * Filtra i record per condizione.
		 *
		 * @param array<string, mixed>|array<int, mixed> $conditions Condizioni WHERE.
		 * @param string                                  $order_by   Clausola ORDER BY.
		 * @return static
		 */
		public function getBy( $conditions = [], string $order_by = '' ): static {
			return $this;
		}

		/**
		 * Numero di record trovati.
		 *
		 * @return int
		 */
		public function count(): int {
			return count( $this->records );
		}

		/**
		 * @return \ArrayIterator<int, array<string, mixed>>
		 */
		public function getIterator(): \ArrayIterator {
			return new \ArrayIterator( $this->records );
		}
	}
}

if ( ! class_exists( 'PMXI_Plugin' ) ) {

	class PMXI_Plugin { // phpcs:ignore

		/** Cartella relativa (dentro uploads) dove WPAI salva i file HTML di log. */
		const LOGS_DIRECTORY = 'wpallimport/logs'; // phpcs:ignore

		private static self $instance;

		/**
		 * Singleton: restituisce l'istanza del plugin.
		 *
		 * @return static
		 */
		public static function getInstance(): static {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new static();
			}
			return self::$instance;
		}

		/**
		 * Restituisce un'opzione di configurazione del plugin.
		 *
		 * @param string $name Nome dell'opzione.
		 * @return mixed
		 */
		public function getOption( string $name ) {
			$defaults = [
				'log_storage' => 5,
				'secure'      => false,
			];
			return $defaults[ $name ] ?? null;
		}
	}
}
