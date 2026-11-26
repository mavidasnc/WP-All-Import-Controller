<?php
/**
 * Stub minimo di WP_CLI per PHPStan e PHPUnit (WP-CLI non è disponibile nell'ambiente di analisi).
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stub della classe WP_CLI con i soli metodi usati dal plugin.
	 */
	class WP_CLI {

		/**
		 * Registra un comando.
		 *
		 * @param string $name     Nome del comando.
		 * @param mixed  $callable Callable del comando.
		 * @return bool
		 */
		public static function add_command( $name, $callable ) {
			return true;
		}

		/**
		 * Scrive una riga di output.
		 *
		 * @param string $message Messaggio.
		 * @return void
		 */
		public static function log( $message ) {}

		/**
		 * Scrive un avviso.
		 *
		 * @param string $message Messaggio.
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * Scrive un messaggio di successo.
		 *
		 * @param string $message Messaggio.
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * Scrive un errore e termina.
		 *
		 * @param string $message Messaggio.
		 * @return never
		 */
		public static function error( $message ) {
			throw new \RuntimeException( $message );
		}
	}
}
