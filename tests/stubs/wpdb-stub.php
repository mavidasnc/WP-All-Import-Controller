<?php
/**
 * Stub minimale della classe wpdb di WordPress per i test unitari.
 * Fornisce la struttura necessaria affinché Mockery possa creare mock tipizzati.
 */

// Stub per le funzioni WP usate da MvdWaiCtrlLogger::writeFile() nei test unitari.
// Brain Monkey le ridefinirà a runtime nei test che ne hanno bisogno.
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return [
			'basedir' => sys_get_temp_dir(),
			'baseurl' => 'http://localhost/wp-content/uploads',
			'error'   => false,
		];
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0755, true );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false {
		return json_encode( $data );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	// phpcs:disable
	class wpdb {
		public string $prefix    = 'wp_';
		public int    $insert_id = 0;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		/** @param array<string,mixed> $data */
		public function insert( string $table, array $data, ?array $format = null ): int|false {
			return 1;
		}

		/**
		 * @param array<string,mixed> $data
		 * @param array<string,mixed> $where
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|false {
			return 1;
		}

		public function prepare( string $query, mixed ...$args ): string {
			// Sostituzione semplificata dei segnaposto, sufficiente per le asserzioni dei test.
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			if ( empty( $args ) ) {
				return $query;
			}
			return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
		}

		public function get_results( ?string $query = null, string $output = 'OBJECT' ): array|object|null {
			return [];
		}

		public function get_row( ?string $query = null, string $output = 'OBJECT', int $y = 0 ): array|object|null {
			return null;
		}

		/** Esito simulato di GET_LOCK(): '1' acquisito, '0' occupato, null errore MySQL. */
		public ?string $sim_get_lock = '1';

		/** Esito simulato di IS_FREE_LOCK(): '1' libero, '0' occupato. */
		public ?string $sim_is_free_lock = '1';

		/** Esito simulato di IS_USED_LOCK() = CONNECTION_ID(): '1' se questa connessione possiede il lock. */
		public ?string $sim_owns_lock = '1';

		/** Query di lock ricevute (per asserzioni). @var array<int,string> */
		public array $lock_queries = [];

		/**
		 * Emula le funzioni di lock MySQL usate dal plugin; per le altre query restituisce null.
		 */
		public function get_var( ?string $query = null, int $x = 0, int $y = 0 ): ?string {
			$query = (string) $query;
			if ( str_contains( $query, 'GET_LOCK(' ) ) {
				$this->lock_queries[] = $query;
				return $this->sim_get_lock;
			}
			if ( str_contains( $query, 'IS_FREE_LOCK(' ) ) {
				return $this->sim_is_free_lock;
			}
			if ( str_contains( $query, 'IS_USED_LOCK(' ) ) {
				return $this->sim_owns_lock;
			}
			return null;
		}

		public function query( string $query ): int|bool {
			if ( str_contains( $query, 'RELEASE_LOCK(' ) ) {
				$this->lock_queries[] = $query;
			}
			return 1;
		}
	}
	// phpcs:enable
}
