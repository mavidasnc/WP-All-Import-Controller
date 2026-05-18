<?php
/**
 * Test per MvdWaiCtrlPlugin: registrazione hook, handler AJAX e controlli di sicurezza.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Verifica i controlli di sicurezza e la logica degli handler AJAX.
 */
class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->createWpdbMock();
		// __ è usata in tutti i messaggi di errore delle funzioni AJAX.
		Functions\when( '__' )->returnArg();
	}

	// ── init() ────────────────────────────────────────────────────────────────

	public function test_init_registers_all_required_hooks(): void {
		$hooks_registered = [];
		Functions\when( 'add_action' )->alias(
			function ( string $hook ) use ( &$hooks_registered ): void {
				$hooks_registered[] = $hook;
			}
		);

		\MvdWaiCtrlPlugin::init();

		$this->assertContains( 'admin_menu', $hooks_registered );
		$this->assertContains( 'admin_enqueue_scripts', $hooks_registered );
		$this->assertContains( 'wp_ajax_mvd_wai_ctrl_start', $hooks_registered );
		$this->assertContains( 'wp_ajax_mvd_wai_ctrl_status', $hooks_registered );
		$this->assertContains( MVD_WAI_CTRL_CRON_HOOK, $hooks_registered );
	}

	// ── ajaxStart() — controlli di sicurezza ─────────────────────────────────

	public function test_ajax_start_rejects_when_capability_denied(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\expect( 'current_user_can' )
			->once()
			->with( MVD_WAI_CTRL_CAPABILITY )
			->andReturn( false );

		$error_called = false;
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status ) use ( &$error_called ): void {
				$error_called = true;
				throw new \RuntimeException( 'wp_send_json_error: ' . $status );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/403/' );

		\MvdWaiCtrlPlugin::ajaxStart();
	}

	public function test_ajax_start_rejects_when_pmxi_class_missing(): void {
		// Scenario non testabile in unit test: PMXI_Import_Record è già definita dal bootstrap
		// (tests/stubs/pmxi-stubs.php) e class_exists() non può essere intercettato da Patchwork
		// una volta che la classe è già registrata nel processo PHP corrente.
		// La logica è coperta dal test Runner::test_run_chain_aborts_when_state_is_not_running
		// e dal fatto che il controllo in ajaxStart() è identico a quello in runChain().
		$this->markTestSkipped(
			'class_exists("PMXI_Import_Record") non è mockabile quando la classe è già definita nel processo PHP.'
		);
	}

	public function test_ajax_start_rejects_when_already_running(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		// class_exists('PMXI_Import_Record') ritorna true per default (stub definito nel bootstrap).
		// Stato: running.
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'running' ] );

		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status ): void {
				throw new \RuntimeException( 'wp_send_json_error: ' . $status );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/409/' );

		\MvdWaiCtrlPlugin::ajaxStart();
	}

	public function test_ajax_start_rejects_when_lock_transient_is_set(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		// class_exists('PMXI_Import_Record') ritorna true per default (stub definito nel bootstrap).
		// Stato: idle (isRunning = false).
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );
		// Lock attivo.
		Functions\expect( 'get_transient' )
			->once()
			->with( MVD_WAI_CTRL_LOCK_KEY )
			->andReturn( 1 );

		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status ): void {
				throw new \RuntimeException( 'wp_send_json_error: ' . $status );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/409/' );

		\MvdWaiCtrlPlugin::ajaxStart();
	}

	public function test_ajax_start_happy_path_schedules_cron_and_returns_run_id(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		// class_exists('PMXI_Import_Record') = true per default (stub nel bootstrap).
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'update_option' )->justReturn( true );

		// $wpdb per Logger::createRun().
		global $wpdb;
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$wpdb->insert_id = 55;
		$wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		// scheduleSelf() tenta il loopback (wp_remote_post) e cade nel fallback cron.
		Functions\when( 'wp_generate_password' )->justReturn( 'test-secret' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://localhost/wp-admin/admin-ajax.php' );
		Functions\when( 'apply_filters' )->justReturn( false );
		$wp_err = \Mockery::mock( 'WP_Error' );
		Functions\when( 'wp_remote_post' )->justReturn( $wp_err );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\expect( 'wp_schedule_single_event' )->once();
		Functions\expect( 'spawn_cron' )->once();

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ): void {
				$success_data = $data;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		try {
			\MvdWaiCtrlPlugin::ajaxStart();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$this->assertNotNull( $success_data );
		$this->assertSame( 55, $success_data['run_id'] );
	}

	// ── ajaxStatus() ─────────────────────────────────────────────────────────

	public function test_ajax_status_rejects_when_capability_denied(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\expect( 'current_user_can' )
			->once()
			->with( MVD_WAI_CTRL_CAPABILITY )
			->andReturn( false );

		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status ): void {
				throw new \RuntimeException( 'wp_send_json_error: ' . $status );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/403/' );

		\MvdWaiCtrlPlugin::ajaxStatus();
	}

	public function test_ajax_status_watchdog_crashes_dead_run(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Stato: running con heartbeat più vecchio di 180s.
		$old_updated_at = time() - 200;
		Functions\when( 'get_option' )->justReturn( [
			'status'        => 'running',
			'updated_at'    => $old_updated_at,
			'run_id'        => 99,
			'current_index' => 0,
		] );

		// Nessun lock attivo → watchdog può scattare.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Funzioni WP usate da markRunCrashed(), writeFile(), markCrashed().
		// wp_upload_dir, wp_mkdir_p, trailingslashit, wp_json_encode sono già definite in wpdb-stub.php.
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-05-18 12:00:00' );

		// update_option deve essere chiamato con stato crashed.
		Functions\expect( 'update_option' )
			->atLeast()->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on( fn( $v ) => 'error' === $v['status'] && ! empty( $v['crash_reason'] ) ),
				false
			);

		// DB: appendStep (insert) + closeRun (update) + getRecentRuns (prepare + get_results).
		global $wpdb;
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$wpdb->insert_id = 1;
		$wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$success_called = false;
		Functions\when( 'wp_send_json_success' )->alias(
			function () use ( &$success_called ): void {
				$success_called = true;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		try {
			\MvdWaiCtrlPlugin::ajaxStatus();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$this->assertTrue( $success_called, 'Il watchdog dovrebbe chiamare wp_send_json_success dopo aver marcato il run come crashed.' );
	}

	public function test_ajax_status_watchdog_ignores_recent_run(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Stato: running con heartbeat recente (100s < 180s).
		Functions\when( 'get_option' )->justReturn( [
			'status'     => 'running',
			'updated_at' => time() - 100,
			'run_id'     => 5,
		] );

		// Lock assente — ma il threshold non è superato, il watchdog non deve scattare.
		Functions\when( 'get_transient' )->justReturn( false );

		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ): void {
				$success_data = $data;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		try {
			\MvdWaiCtrlPlugin::ajaxStatus();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		// Il watchdog non deve aver cambiato lo stato.
		$this->assertSame( 'running', $success_data['state']['status'] );
	}

	public function test_ajax_status_returns_state_and_runs(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle', 'run_id' => 0 ] );

		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$success_data = null;
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) use ( &$success_data ): void {
				$success_data = $data;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		try {
			\MvdWaiCtrlPlugin::ajaxStatus();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$this->assertArrayHasKey( 'state', $success_data );
		$this->assertArrayHasKey( 'runs', $success_data );
		$this->assertSame( 'idle', $success_data['state']['status'] );
		$this->assertSame( [], $success_data['runs'] );
	}
}
