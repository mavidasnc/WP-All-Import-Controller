<?php
/**
 * Stub minimale della classe wpdb di WordPress per i test unitari.
 * Fornisce la struttura necessaria affinché Mockery possa creare mock tipizzati.
 */

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
