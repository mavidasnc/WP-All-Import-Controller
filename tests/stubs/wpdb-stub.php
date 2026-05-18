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
			return $query;
		}

		public function get_results( ?string $query = null, string $output = 'OBJECT' ): array|object|null {
			return [];
		}
	}
	// phpcs:enable
}
