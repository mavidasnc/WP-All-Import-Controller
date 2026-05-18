<?php
/**
 * Stub configurabile di PMXI_Import_Record per i test unitari.
 *
 * I test configurano il comportamento tramite PMXI_Import_Record::configureStub()
 * prima di invocare MvdWaiCtrlRunner::runStep(). Ogni chiamata a getById()
 * consuma una configurazione dall'array in ordine sequenziale.
 */

if ( ! class_exists( 'PMXI_Import_Record' ) ) {

	class PMXI_Import_Record { // phpcs:ignore

		/** @var array<int, array<string, mixed>> */
		private static array $stub_queue = [];

		private static int $call_index = 0;

		public int    $id               = 0;
		public string $name              = '';
		public string $friendly_name    = '';
		public int    $created          = 0;
		public int    $updated          = 0;
		public int    $skipped          = 0;
		public int    $imported         = 0;
		public int    $queue_chunk_number = 0;
		public int    $count            = 0;
		public int    $processing       = 0;

		/** @var array<string, mixed> */
		public array $errors = [];

		/** Accumula staticamente tutte le chiamate a set() per asserzioni nei test. */
		private static array $static_set_calls = [];

		private bool $is_empty = true;

		/** @var \Throwable|null */
		private ?\Throwable $execute_throws = null;

		/**
		 * Risultati che execute() deve applicare sulle proprietà dell'istanza.
		 * Separati dalle proprietà iniziali per resistere alle chiamate set() del runner
		 * che azzerano i counter prima di execute().
		 *
		 * @var array<string, mixed>
		 */
		private array $execute_results = [];

		/**
		 * Configura la sequenza di comportamenti per i test.
		 *
		 * @param array<int, array<string, mixed>> $configs Ogni elemento configura un'istanza:
		 *   - 'empty'               (bool)       Se true, isEmpty() ritorna true.
		 *   - 'name'               (string)     Nome file dell'import.
		 *   - 'friendly_name'      (string)     Nome amichevole dell'import (UI di WP All Import).
		 *   - 'created'             (int)        Valore di $created post-execute().
		 *   - 'updated'             (int)        Valore di $updated post-execute().
		 *   - 'skipped'             (int)        Valore di $skipped post-execute().
		 *   - 'imported'            (int)        Valore di $imported post-execute().
		 *   - 'queue_chunk_number'  (int)        0 = import completato; >0 = ancora chunk da processare (post-execute()).
		 *   - 'count'               (int)        Totale record dell'import (post-execute()).
		 *   - 'execute_throws'      (\Throwable) Se presente, execute() lancia questa eccezione.
		 */
		public static function configureStub( array $configs ): void {
			self::$stub_queue = $configs;
			self::$call_index = 0;
		}

		/** Azzera la coda, il contatore e le chiamate a set() (utile nel tearDown). */
		public static function resetStub(): void {
			self::$stub_queue        = [];
			self::$call_index        = 0;
			self::$static_set_calls  = [];
		}

		/** Restituisce tutte le chiamate a set() accumulate dall'ultimo reset. */
		public static function getSetCalls(): array {
			return self::$static_set_calls;
		}

		public function set( array $data ): static {
			self::$static_set_calls[] = $data;
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
			return $this;
		}

		public function update(): static {
			return $this;
		}

		public function getById( int $id ): static {
			$config = self::$stub_queue[ self::$call_index ] ?? [];
			self::$call_index++;

			$this->is_empty       = (bool)   ( $config['empty']         ?? true );
			$this->name           = (string) ( $config['name']          ?? '' );
			$this->friendly_name  = (string) ( $config['friendly_name'] ?? '' );
			$this->execute_throws = $config['execute_throws']            ?? null;

			// Salva i risultati post-execute separatamente: execute() li applicherà
			// dopo che il runner ha chiamato set() per azzerare i counter.
			$this->execute_results = [
				'created'            => (int) ( $config['created']           ?? 0 ),
				'updated'            => (int) ( $config['updated']           ?? 0 ),
				'skipped'            => (int) ( $config['skipped']           ?? 0 ),
				'imported'           => (int) ( $config['imported']          ?? 0 ),
				'queue_chunk_number' => (int) ( $config['queue_chunk_number'] ?? 0 ),
				'count'              => (int) ( $config['count']             ?? 0 ),
			];

			return $this;
		}

		public function isEmpty(): bool {
			return $this->is_empty;
		}

		public function execute( callable $logger = null, bool $rerun = false, bool $trigger_hooks = false ): void {
			if ( null !== $this->execute_throws ) {
				throw $this->execute_throws;
			}
			// Applica i risultati configurati simulando il comportamento di PMXI.
			foreach ( $this->execute_results as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
