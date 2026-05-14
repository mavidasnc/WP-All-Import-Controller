<?php
/**
 * Stub configurabile di PMXI_Import_Record per i test unitari.
 *
 * I test configurano il comportamento tramite PMXI_Import_Record::configureStub()
 * prima di invocare MvdWaiCtrlRunner::runChain(). Ogni chiamata a getById()
 * consuma una configurazione dall'array in ordine sequenziale.
 */

if ( ! class_exists( 'PMXI_Import_Record' ) ) {

	class PMXI_Import_Record { // phpcs:ignore

		/** @var array<int, array<string, mixed>> */
		private static array $stub_queue = [];

		private static int $call_index = 0;

		public string $name    = '';
		public int    $created = 0;
		public int    $updated = 0;
		public int    $skipped = 0;

		/** @var array<string, mixed> */
		public array $errors = [];

		private bool $is_empty = true;

		/** @var \Throwable|null */
		private ?\Throwable $execute_throws = null;

		/**
		 * Configura la sequenza di comportamenti per i test.
		 *
		 * @param array<int, array<string, mixed>> $configs Ogni elemento configura un'istanza:
		 *   - 'empty'          (bool)       Se true, isEmpty() ritorna true.
		 *   - 'created'        (int)        Valore della proprietà $created.
		 *   - 'updated'        (int)        Valore della proprietà $updated.
		 *   - 'skipped'        (int)        Valore della proprietà $skipped.
		 *   - 'execute_throws' (\Throwable) Se presente, execute() lancia questa eccezione.
		 */
		public static function configureStub( array $configs ): void {
			self::$stub_queue  = $configs;
			self::$call_index  = 0;
		}

		/** Azzera la coda e il contatore (utile nel tearDown). */
		public static function resetStub(): void {
			self::$stub_queue = [];
			self::$call_index = 0;
		}

		public function getById( int $id ): static {
			$config = self::$stub_queue[ self::$call_index ] ?? [];
			self::$call_index++;

			$this->is_empty       = (bool) ( $config['empty']          ?? true );
			$this->created        = (int)  ( $config['created']        ?? 0 );
			$this->updated        = (int)  ( $config['updated']        ?? 0 );
			$this->skipped        = (int)  ( $config['skipped']        ?? 0 );
			$this->execute_throws = $config['execute_throws']           ?? null;

			return $this;
		}

		public function isEmpty(): bool {
			return $this->is_empty;
		}

		public function execute( callable $logger, bool $rerun = false, bool $trigger_hooks = true ): void {
			if ( null !== $this->execute_throws ) {
				throw $this->execute_throws;
			}
		}
	}
}
