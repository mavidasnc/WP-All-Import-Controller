<?php
/**
 * Plugin Name: MVD WAI Controller — strumenti di sviluppo (solo wp-env)
 * Description: Corregge i loopback HTTP dentro Docker, simula record lenti e processi uccisi, e aggiunge i comandi WP-CLI `wp mvd-dev` per preparare e osservare gli import di test.
 *
 * NON va mai installato in staging o produzione: è montato solo da .wp-env.json.
 *
 * @package MvdWaiCtrlDev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option con la configurazione del simulatore (array).
 *
 * Chiavi: record_delay (secondi di attesa per record), after_import_delay (secondi di attesa
 * su pmxi_after_xml_import), kill_after_records (uccide il processo dopo N record salvati).
 */
const MVD_WAI_DEV_SIM_OPTION = 'mvd_wai_ctrl_sim';

/**
 * Prefisso degli SKU generati dal setup: serve a riconoscere i prodotti di test.
 */
const MVD_WAI_DEV_SKU_PREFIX = 'MVDDEV';

/**
 * Riscrive le richieste HTTP dirette all'URL pubblico del sito (es. http://localhost:8888)
 * verso l'indirizzo interno del container.
 *
 * Dentro Docker la porta pubblica non è raggiungibile: senza questa correzione i loopback
 * del controller (admin-ajax) e spawn_cron() fallirebbero in silenzio e la catena si
 * fermerebbe al primo hop. L'header Host conserva l'URL pubblico per WordPress.
 *
 * @param false|array|WP_Error $pre  Risposta anticipata (false = nessuna).
 * @param array                $args Argomenti della richiesta HTTP.
 * @param string               $url  URL richiesto.
 * @return false|array|WP_Error      Risposta della richiesta riscritta, oppure $pre invariato.
 */
function mvd_wai_dev_fix_loopback( $pre, $args, $url ) {
	$home   = wp_parse_url( home_url() );
	$target = wp_parse_url( $url );
	if ( false !== $pre || empty( $home['port'] ) || empty( $target['host'] ) || empty( $target['port'] ) ) {
		return $pre;
	}
	if ( $target['host'] !== $home['host'] || (int) $target['port'] !== (int) $home['port'] ) {
		return $pre;
	}

	// Dal container web Apache ascolta su localhost:80; dal container cli si usa il nome del servizio.
	$internal_host = 'localhost';
	if ( 'cli' === PHP_SAPI ) {
		$internal_host = 'wordpress';
	}

	$new_url = 'http://' . $internal_host . ( $target['path'] ?? '/' ) . ( isset( $target['query'] ) ? '?' . $target['query'] : '' );

	$args['headers']         = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
	$args['headers']['Host'] = $home['host'] . ':' . $home['port'];

	return wp_remote_request( $new_url, $args );
}
add_filter( 'pre_http_request', 'mvd_wai_dev_fix_loopback', 10, 3 );

/**
 * Restituisce la configurazione corrente del simulatore.
 *
 * @return array<string, int> Configurazione (vuota = simulatore spento).
 */
function mvd_wai_dev_sim_config(): array {
	$config = get_option( MVD_WAI_DEV_SIM_OPTION, [] );
	return is_array( $config ) ? $config : [];
}

/**
 * Simulatore sui record salvati: rallenta ogni record e, se richiesto, uccide il processo.
 *
 * Il kill usa SIGKILL quando disponibile, come farebbe l'host: nessuno shutdown handler,
 * nessun errore nei log, connessione MySQL chiusa (quindi lock GET_LOCK rilasciato).
 * Il kill è monouso: la chiave viene rimossa prima di uccidere il processo.
 *
 * @return void
 */
function mvd_wai_dev_sim_on_saved_post(): void {
	static $saved_in_process = 0;
	++$saved_in_process;

	$config = mvd_wai_dev_sim_config();

	if ( ! empty( $config['kill_after_records'] ) && $saved_in_process >= (int) $config['kill_after_records'] ) {
		unset( $config['kill_after_records'] );
		update_option( MVD_WAI_DEV_SIM_OPTION, $config, false );
		error_log( sprintf( '[mvd-wai-dev] kill simulato del processo %d dopo %d record', getmypid(), $saved_in_process ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( function_exists( 'posix_kill' ) ) {
			posix_kill( getmypid(), 9 );
		}
		exit( 1 );
	}

	if ( ! empty( $config['record_delay'] ) ) {
		sleep( (int) $config['record_delay'] );
	}
}
add_action( 'pmxi_saved_post', 'mvd_wai_dev_sim_on_saved_post', 5, 0 );

/**
 * Simulatore di fine import: imita un hook pesante su pmxi_after_xml_import
 * (come la sostituzione varianti del tema di staging).
 *
 * @return void
 */
function mvd_wai_dev_sim_on_after_import(): void {
	$config = mvd_wai_dev_sim_config();
	if ( ! empty( $config['after_import_delay'] ) ) {
		sleep( (int) $config['after_import_delay'] );
	}
}
add_action( 'pmxi_after_xml_import', 'mvd_wai_dev_sim_on_after_import', 5, 0 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Comandi di sviluppo per il controller: setup degli import di test, simulatore, stato.
	 */
	class MvdWaiDevCommand {

		/**
		 * Crea i feed XML sintetici e i 4 import WP All Import con gli ID attesi dal controller.
		 *
		 * Gli import esistenti con gli stessi ID vengono sostituiti. Ogni import ha un proprio
		 * feed con SKU distinti (MVDDEV-{id}-{n}).
		 *
		 * ## OPTIONS
		 *
		 * [--records=<n>]
		 * : Prodotti per feed.
		 * ---
		 * default: 40
		 * ---
		 *
		 * [--per-request=<n>]
		 * : Record per richiesta (records_per_request di WP All Import).
		 * ---
		 * default: 4
		 * ---
		 *
		 * [--time-limit=<s>]
		 * : Secondi massimi per blocco (cron_processing_time_limit di WP All Import, 20 come su staging).
		 * ---
		 * default: 20
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp mvd-dev setup --records=40 --per-request=4 --time-limit=20
		 *
		 * @param array<int, string>    $args       Argomenti posizionali (non usati).
		 * @param array<string, string> $assoc_args Opzioni.
		 * @return void
		 */
		public function setup( array $args, array $assoc_args ): void {
			global $wpdb;

			if ( ! class_exists( 'PMXI_Plugin' ) || ! class_exists( 'PMXI_Import_Record' ) ) {
				WP_CLI::error( 'WP All Import Pro non è attivo: controllare .wp-env.override.json.' );
			}
			if ( ! class_exists( 'PMWI_Plugin' ) ) {
				WP_CLI::error( 'L\'add-on WooCommerce di WP All Import non è attivo.' );
			}
			if ( ! defined( 'MVD_WAI_CTRL_IDS' ) ) {
				WP_CLI::error( 'Il plugin MVD WP All Import Controller non è attivo.' );
			}

			$records     = max( 1, (int) ( $assoc_args['records'] ?? 40 ) );
			$per_request = max( 1, (int) ( $assoc_args['per-request'] ?? 4 ) );
			// I feed devono stare dentro uploads: wp_all_import_get_absolute_path() antepone la cartella
			// uploads a qualsiasi percorso che non la contenga già.
			$data_dir    = wp_upload_dir()['basedir'] . '/mvd-dev-feeds';
			wp_mkdir_p( $data_dir );

			$defaults = array_merge( PMXI_Plugin::get_default_import_options(), PMWI_Plugin::get_default_import_options() );
			$table    = $wpdb->prefix . 'pmxi_imports';

			foreach ( MVD_WAI_CTRL_IDS as $position => $import_id ) {
				$feed_path = $data_dir . '/feed-' . $import_id . '.xml';
				$this->writeFeed( $feed_path, (int) $import_id, $records );

				$options = array_merge(
					$defaults,
					[
						'type'                         => 'post',
						'custom_type'                  => 'product',
						'wizard_type'                  => 'new',
						'title'                        => '{title[1]}',
						'content'                      => '{description[1]}',
						'status'                       => 'publish',
						'unique_key'                   => '{sku[1]}',
						'duplicate_matching'           => 'auto',
						'records_per_request'          => $per_request,
						'create_new_records'           => 1,
						'update_all_data'              => 'yes',
						'is_delete_missing'            => 0,
						'is_multiple_product_type'     => 'yes',
						'multiple_product_type'        => 'simple',
						'single_product_sku'           => '{sku[1]}',
						'single_product_regular_price' => '{price[1]}',
						// Senza questo WPAI salta i record invariati dal run precedente: nessun
						// pmxi_saved_post, quindi niente simulatore. Su staging il feed cambia sempre.
						'is_selective_hashing'         => 0,
					]
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ambiente di sviluppo, ID imposti dal controller.
				$wpdb->delete( $table, [ 'id' => (int) $import_id ], [ '%d' ] );

				$record = new PMXI_Import_Record();
				$record->set(
					[
						'name'               => basename( $feed_path ),
						'friendly_name'      => sprintf( 'DEV %d - Import di test %d', $position + 1, $import_id ),
						'type'               => 'upload',
						'feed_type'          => 'xml',
						'path'               => $feed_path,
						'root_element'       => 'product',
						'xpath'              => '/product',
						'options'            => $options,
						'registered_on'      => gmdate( 'Y-m-d H:i:s' ),
						'triggered'          => 0,
						'processing'         => 0,
						'executing'          => 0,
						'queue_chunk_number' => 0,
						'count'              => 0,
					]
				)->insert();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- forza l'ID atteso da MVD_WAI_CTRL_IDS.
				$wpdb->update( $table, [ 'id' => (int) $import_id ], [ 'id' => (int) $record->id ], [ '%d' ], [ '%d' ] );

				WP_CLI::log( sprintf( 'Import %d creato: %d prodotti, %d per richiesta (%s)', $import_id, $records, $per_request, $feed_path ) );
			}

			$time_limit = max( 1, (int) ( $assoc_args['time-limit'] ?? 20 ) );
			PMXI_Plugin::getInstance()->updateOption( 'cron_processing_time_limit', $time_limit );

			delete_option( MVD_WAI_DEV_SIM_OPTION );
			WP_CLI::success( sprintf( 'Ambiente di test pronto (limite per blocco %d s). Simulatore spento.', $time_limit ) );
		}

		/**
		 * Configura il simulatore (record lenti, fine import lenta, kill del processo).
		 *
		 * ## OPTIONS
		 *
		 * [--record-delay=<s>]
		 * : Secondi di attesa per ogni record salvato.
		 *
		 * [--after-import-delay=<s>]
		 * : Secondi di attesa su pmxi_after_xml_import.
		 *
		 * [--kill-after-records=<n>]
		 * : Uccide (SIGKILL) il processo dopo N record salvati nello stesso hop. Monouso.
		 *
		 * [--clear]
		 * : Spegne il simulatore.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mvd-dev sim --record-delay=30
		 *     wp mvd-dev sim --kill-after-records=2
		 *     wp mvd-dev sim --clear
		 *
		 * @param array<int, string>    $args       Argomenti posizionali (non usati).
		 * @param array<string, string> $assoc_args Opzioni.
		 * @return void
		 */
		public function sim( array $args, array $assoc_args ): void {
			if ( isset( $assoc_args['clear'] ) ) {
				delete_option( MVD_WAI_DEV_SIM_OPTION );
				WP_CLI::success( 'Simulatore spento.' );
				return;
			}
			$config = mvd_wai_dev_sim_config();
			foreach ( [ 'record-delay', 'after-import-delay', 'kill-after-records' ] as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$config[ str_replace( '-', '_', $key ) ] = max( 0, (int) $assoc_args[ $key ] );
				}
			}
			update_option( MVD_WAI_DEV_SIM_OPTION, array_filter( $config ), false );
			WP_CLI::success( 'Simulatore: ' . wp_json_encode( array_filter( $config ) ) );
		}

		/**
		 * Avvia la catena come il pulsante "Avvia" (senza nonce: solo sviluppo).
		 *
		 * @return void
		 */
		public function start(): void {
			if ( MvdWaiCtrlState::isRunning() ) {
				WP_CLI::error( 'Un run è già in corso.' );
			}
			if ( ! MvdWaiCtrlRunner::isExecLockFree() ) {
				WP_CLI::error( 'Un blocco è ancora in esecuzione.' );
			}
			$run_id = MvdWaiCtrlLogger::createRun();
			MvdWaiCtrlState::startRun( $run_id );
			if ( class_exists( 'MvdWaiCtrlWatchdog' ) ) {
				MvdWaiCtrlWatchdog::ensureScheduled();
			}
			MvdWaiCtrlRunner::scheduleSelf();
			WP_CLI::success( sprintf( 'Run %d avviato.', $run_id ) );
		}

		/**
		 * Esegue il polling del pannello (ajaxStatus, watchdog incluso) come farebbe il browser.
		 *
		 * @return void
		 */
		public function poll(): void {
			$this->printPoll( $this->callAjax( [ 'MvdWaiCtrlPlugin', 'ajaxStatus' ], 'mvd_wai_ctrl_status' ) );
		}

		/**
		 * Esegue il polling del pannello in loop, caricando WordPress una sola volta.
		 *
		 * Su Docker per Windows ogni comando WP-CLI impiega decine di secondi solo per il bootstrap:
		 * questo comando permette di osservare il watchdog con la cadenza reale del pannello.
		 *
		 * ## OPTIONS
		 *
		 * [--interval=<s>]
		 * : Secondi tra due poll.
		 * ---
		 * default: 15
		 * ---
		 *
		 * [--duration=<s>]
		 * : Durata massima dell'osservazione.
		 * ---
		 * default: 900
		 * ---
		 *
		 * [--start]
		 * : Avvia la catena prima di iniziare il polling.
		 *
		 * [--auto-resume]
		 * : Preme "Riprendi" (una volta) appena il pannello lo rende disponibile.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mvd-dev watch --start --interval=15 --duration=600
		 *
		 * @param array<int, string>    $args       Argomenti posizionali (non usati).
		 * @param array<string, string> $assoc_args Opzioni.
		 * @return void
		 */
		public function watch( array $args, array $assoc_args ): void {
			$interval = max( 1, (int) ( $assoc_args['interval'] ?? 15 ) );
			$deadline = time() + max( $interval, (int) ( $assoc_args['duration'] ?? 900 ) );
			$resumed  = false;

			if ( isset( $assoc_args['start'] ) ) {
				$this->start();
			}

			while ( time() < $deadline ) {
				// Le option sono in cache nel processo: senza svuotarla si leggerebbe uno stato vecchio.
				wp_cache_flush();
				$data = $this->callAjax( [ 'MvdWaiCtrlPlugin', 'ajaxStatus' ], 'mvd_wai_ctrl_status' );
				$this->printPoll( $data );

				$status = $data['data']['state']['status'] ?? '';
				if ( isset( $assoc_args['auto-resume'] ) && ! $resumed && ! empty( $data['data']['can_resume'] ) ) {
					$resumed = true;
					wp_cache_flush();
					$this->resume();
					sleep( $interval );
					continue;
				}
				if ( in_array( $status, [ 'completed', 'idle' ], true ) || ( 'error' === $status && empty( $data['data']['exec_alive'] ) && ( $resumed || ! isset( $assoc_args['auto-resume'] ) ) ) ) {
					break;
				}
				sleep( $interval );
			}
		}

		/**
		 * Stampa una riga di sintesi di una risposta di ajaxStatus.
		 *
		 * @param array<string, mixed> $data Risposta JSON decodificata.
		 * @return void
		 */
		private function printPoll( array $data ): void {
			$state = $data['data']['state'] ?? [];
			WP_CLI::log(
				sprintf(
					'%s poll: status=%s exec_alive=%s stalled_sec=%d can_resume=%s passo=%d record=%d/%d %s',
					gmdate( 'H:i:s' ),
					$state['status'] ?? '?',
					wp_json_encode( $data['data']['exec_alive'] ?? null ),
					(int) ( $data['data']['stalled_sec'] ?? 0 ),
					wp_json_encode( $data['data']['can_resume'] ?? null ),
					(int) ( $state['current_index'] ?? 0 ) + 1,
					(int) ( $state['current_chunk_done'] ?? 0 ),
					(int) ( $state['current_step_total_chunks'] ?? 0 ),
					'error' === ( $state['status'] ?? '' ) ? '| ' . ( $state['crash_reason'] ?? $state['last_message'] ?? '' ) : ''
				)
			);
		}

		/**
		 * Chiama un handler AJAX reale del controller e stampa la risposta JSON.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Azione da eseguire.
		 * ---
		 * options:
		 *   - start
		 *   - resume
		 *   - reset
		 *   - status
		 * ---
		 *
		 * [--wait-resumable]
		 * : Prima di chiamare l'azione attende (polling ogni secondo, max 10 minuti) che il pannello
		 *   renda disponibile "Riprendi". Serve a simulare due click simultanei da due processi.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mvd-dev ajax resume
		 *
		 * @param array<int, string>    $args       Argomenti posizionali: azione.
		 * @param array<string, string> $assoc_args Opzioni.
		 * @return void
		 */
		public function ajax( array $args, array $assoc_args ): void {
			$map    = [
				'start'  => [ 'ajaxStart', 'mvd_wai_ctrl_start' ],
				'resume' => [ 'ajaxResume', 'mvd_wai_ctrl_resume' ],
				'reset'  => [ 'ajaxReset', 'mvd_wai_ctrl_reset' ],
				'status' => [ 'ajaxStatus', 'mvd_wai_ctrl_status' ],
			];
			$action = $args[0] ?? '';
			if ( ! isset( $map[ $action ] ) ) {
				WP_CLI::error( 'Azione non valida.' );
			}

			if ( isset( $assoc_args['wait-resumable'] ) ) {
				$deadline = time() + 600;
				do {
					wp_cache_flush();
					$status = $this->callAjax( [ 'MvdWaiCtrlPlugin', 'ajaxStatus' ], 'mvd_wai_ctrl_status' );
					if ( ! empty( $status['data']['can_resume'] ) ) {
						break;
					}
					sleep( 1 );
				} while ( time() < $deadline );
				wp_cache_flush();
			}

			$data = $this->callAjax( [ 'MvdWaiCtrlPlugin', $map[ $action ][0] ], $map[ $action ][1] );
			WP_CLI::log( sprintf( '%s [pid %d] %s: %s', gmdate( 'H:i:s' ), getmypid(), $action, wp_json_encode( $data ) ) );
		}

		/**
		 * Porta lo stato in errore senza toccare il blocco in esecuzione.
		 *
		 * Simula un falso crash del watchdog delle versioni precedenti (o lo stato visto da
		 * un'altra scheda), per verificare che Riprendi e Avvia vengano rifiutati a blocco vivo.
		 *
		 * @subcommand fake-crash
		 *
		 * @return void
		 */
		public function fake_crash(): void {
			$state                 = MvdWaiCtrlState::get();
			$state['status']       = 'error';
			$state['crash_reason'] = 'Falso crash simulato (mvd-dev)';
			MvdWaiCtrlState::save( $state );
			WP_CLI::success( 'Stato forzato a error. Lock di esecuzione: ' . ( MvdWaiCtrlRunner::isExecLockFree() ? 'libero' : 'OCCUPATO' ) );
		}

		/**
		 * Riporta lo stato a running (dopo fake-crash) mantenendo catena e posizione.
		 *
		 * @subcommand unfake-crash
		 *
		 * @return void
		 */
		public function unfake_crash(): void {
			$state                 = MvdWaiCtrlState::get();
			$state['status']       = 'running';
			$state['crash_reason'] = null;
			MvdWaiCtrlState::save( $state );
			WP_CLI::success( 'Stato riportato a running.' );
		}

		/**
		 * Preme "Riprendi" chiamando il vero handler ajaxResume.
		 *
		 * @return void
		 */
		public function resume(): void {
			$data = $this->callAjax( [ 'MvdWaiCtrlPlugin', 'ajaxResume' ], 'mvd_wai_ctrl_resume' );
			WP_CLI::log( gmdate( 'H:i:s' ) . ' resume: ' . wp_json_encode( $data ) );
		}

		/**
		 * Invoca un handler AJAX del controller con utente admin e nonce valido, catturando il JSON.
		 *
		 * @param callable $handler      Handler da eseguire (es. ajaxStatus).
		 * @param string   $nonce_action Azione del nonce verificato dall'handler.
		 * @return array<string, mixed>  Risposta JSON decodificata.
		 */
		private function callAjax( callable $handler, string $nonce_action ): array {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
			wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
			$_REQUEST['_ajax_nonce'] = wp_create_nonce( $nonce_action );

			// wp_send_json_*() termina con wp_die(): in AJAX il gestore lancia un'eccezione invece di uscire.
			add_filter( 'wp_doing_ajax', '__return_true' );
			$die_handler = static function () {
				return static function (): void {
					throw new \RuntimeException( 'mvd-dev-ajax-done' );
				};
			};
			add_filter( 'wp_die_ajax_handler', $die_handler );

			ob_start();
			try {
				call_user_func( $handler );
			} catch ( \RuntimeException $e ) {
				if ( 'mvd-dev-ajax-done' !== $e->getMessage() ) {
					ob_end_clean();
					throw $e;
				}
			}
			$output = (string) ob_get_clean();

			remove_filter( 'wp_die_ajax_handler', $die_handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );

			$decoded = json_decode( $output, true );
			return is_array( $decoded ) ? $decoded : [ 'raw' => $output ];
		}

		/**
		 * Mostra stato del controller, lock, heartbeat, flag PMXI e coda del log su file.
		 *
		 * ## OPTIONS
		 *
		 * [--lines=<n>]
		 * : Righe del log su file da mostrare.
		 * ---
		 * default: 15
		 * ---
		 *
		 * @param array<int, string>    $args       Argomenti posizionali (non usati).
		 * @param array<string, string> $assoc_args Opzioni.
		 * @return void
		 */
		public function status( array $args, array $assoc_args ): void {
			global $wpdb;

			$state     = MvdWaiCtrlState::get();
			$last_seen = MvdWaiCtrlState::lastActivity( $state );
			WP_CLI::log(
				sprintf(
					'Stato: %s | run %d | passo %d | record %d/%d | resuming %s | chain %s',
					$state['status'],
					(int) $state['run_id'],
					(int) $state['current_index'] + 1,
					(int) $state['current_chunk_done'],
					(int) $state['current_step_total_chunks'],
					empty( $state['resuming'] ) ? 'no' : 'sì',
					(string) ( $state['chain_id'] ?? '' )
				)
			);
			WP_CLI::log(
				sprintf(
					'Lock esecuzione: %s | ultima attività: %s fa | simulatore: %s',
					MvdWaiCtrlRunner::isExecLockFree() ? 'libero' : 'OCCUPATO',
					$last_seen ? ( time() - $last_seen ) . ' s' : 'mai',
					wp_json_encode( mvd_wai_dev_sim_config() )
				)
			);

			$ids  = implode( ',', array_map( 'intval', MVD_WAI_CTRL_IDS ) );
			$rows = $wpdb->get_results( "SELECT id, triggered, processing, queue_chunk_number, count, imported, created, updated FROM {$wpdb->prefix}pmxi_imports WHERE id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB
			WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'triggered', 'processing', 'queue_chunk_number', 'count', 'imported', 'created', 'updated' ] );

			$log = wp_upload_dir()['basedir'] . '/' . MVD_WAI_CTRL_LOG_DIR . '/' . gmdate( 'Y-m-d' ) . '.log';
			if ( is_readable( $log ) ) {
				$lines = file( $log, FILE_IGNORE_NEW_LINES );
				WP_CLI::log( '--- ' . basename( $log ) );
				foreach ( array_slice( (array) $lines, - max( 1, (int) ( $assoc_args['lines'] ?? 15 ) ) ) as $line ) {
					WP_CLI::log( $line );
				}
			}
		}

		/**
		 * Scrive un feed XML sintetico di prodotti semplici.
		 *
		 * @param string $path      Percorso del file da scrivere.
		 * @param int    $import_id ID dell'import (entra negli SKU per distinguere i feed).
		 * @param int    $records   Numero di prodotti.
		 * @return void
		 */
		private function writeFeed( string $path, int $import_id, int $records ): void {
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<products>\n";
			for ( $i = 1; $i <= $records; $i++ ) {
				$xml .= sprintf(
					"\t<product><sku>%1\$s-%2\$d-%3\$03d</sku><title>Prodotto test %2\$d-%3\$03d</title><price>%4\$s</price><description>Descrizione di prova %3\$d, generata il %5\$s.</description></product>\n",
					MVD_WAI_DEV_SKU_PREFIX,
					$import_id,
					$i,
					number_format( 10 + $i, 2, '.', '' ),
					gmdate( 'Y-m-d H:i:s' )
				);
			}
			$xml .= "</products>\n";
			file_put_contents( $path, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	WP_CLI::add_command( 'mvd-dev', 'MvdWaiDevCommand' );
}
