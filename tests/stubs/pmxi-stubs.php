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

		private bool $is_empty = true;

		/** @var \Throwable|null */
		private ?\Throwable $execute_throws = null;

		/**
		 * Configura la sequenza di comportamenti per i test.
		 *
		 * @param array<int, array<string, mixed>> $configs Ogni elemento configura un'istanza:
		 *   - 'empty'               (bool)       Se true, isEmpty() ritorna true.
		 *   - 'name'               (string)     Nome file dell'import.
		 *   - 'friendly_name'      (string)     Nome amichevole dell'import (UI di WP All Import).
		 *   - 'created'             (int)        Valore della proprietà $created.
		 *   - 'updated'             (int)        Valore della proprietà $updated.
		 *   - 'skipped'             (int)        Valore della proprietà $skipped.
		 *   - 'imported'            (int)        Valore di $imported post-execute().
		 *   - 'queue_chunk_number'  (int)        0 = import completato; >0 = ancora chunk da processare.
		 *   - 'count'               (int)        Totale record dell'import.
		 *   - 'execute_throws'      (\Throwable) Se presente, execute() lancia questa eccezione.
		 */
		public static function configureStub( array $configs ): void {
			self::$stub_queue = $configs;
			self::$call_index = 0;
		}

		/** Azzera la coda e il contatore (utile nel tearDown). */
		public static function resetStub(): void {
			self::$stub_queue = [];
			self::$call_index = 0;
		}

		public function getById( int $id ): static {
			$config = self::$stub_queue[ self::$call_index ] ?? [];
			self::$call_index++;

			$this->is_empty            = (bool)   ( $config['empty']              ?? true );
			$this->name                = (string) ( $config['name']              ?? '' );
			$this->friendly_name       = (string) ( $config['friendly_name']     ?? '' );
			$this->created             = (int)    ( $config['created']           ?? 0 );
			$this->updated             = (int)    ( $config['updated']           ?? 0 );
			$this->skipped             = (int)    ( $config['skipped']           ?? 0 );
			$this->imported            = (int)    ( $config['imported']          ?? 0 );
			$this->queue_chunk_number  = (int)    ( $config['queue_chunk_number'] ?? 0 );
			$this->count               = (int)    ( $config['count']             ?? 0 );
			$this->processing          = (int)    ( $config['processing']        ?? 0 );
			$this->execute_throws      = $config['execute_throws']               ?? null;

			return $this;
		}

		public function isEmpty(): bool {
			return $this->is_empty;
		}

		public function execute( callable $logger = null, bool $rerun = false, bool $trigger_hooks = false ): void {
			if ( null !== $this->execute_throws ) {
				throw $this->execute_throws;
			}
		}
	}
}
