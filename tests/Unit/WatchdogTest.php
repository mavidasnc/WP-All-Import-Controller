<?php
/**
 * Test 1.7.0: ripresa automatica dei blocchi morti (MvdWaiCtrlWatchdog).
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Verifica rilevamento, ripresa automatica, dimezzamento dei record e limiti del watchdog.
 */
class WatchdogTest extends TestCase {

	/**
	 * Stato simulato dell'option di stato.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_state = [];

	/**
	 * Mock globale di wpdb con emulazione dei lock.
	 *
	 * @var \Mockery\MockInterface&\wpdb
	 */
	private $wpdb;

	/**
	 * Righe aggiornate su wpdb (tabella => dati), per verificare forceStopPmxiCron.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $db_updates = [];

	/**
	 * Inizializza stato, wpdb e stub WordPress comuni.
	 *
	 * @param array<string, mixed> $state Campi di stato da sovrascrivere ai valori di una catena morta.
	 * @return void
	 */
	private function boot( array $state = [] ): void {
		$this->saved_state = array_merge(
			[
				'status'                    => 'running',
				'run_id'                    => 7,
				'current_index'             => 0,
				'current_chunk_done'        => 225,
				'current_step_total_chunks' => 276,
				'updated_at'                => time() - 300,
				'chain_id'                  => 'old-chain',
				'auto_resume_count'         => 0,
				'auto_resume_position'      => '',
				'last_message'              => '',
			],
			$state
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => MVD_WAI_CTRL_STATE_OPTION === $name ? $this->saved_state : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ): bool {
				if ( MVD_WAI_CTRL_STATE_OPTION === $name ) {
					$this->saved_state = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'new-chain' );
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://localhost/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 );

		$this->wpdb = $this->createWpdbMock();
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( [ 'triggered' => '1', 'processing' => '1', 'executing' => '0' ] );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->db_updates[] = [ 'table' => $table, 'data' => $data ];
				return 1;
			}
		);
	}

	public function test_dead_chain_is_resumed_automatically_with_halved_records(): void {
		$this->boot();
		\PMXI_Import_Record::configureStub( [ [ 'empty' => false, 'options' => [ 'records_per_request' => 4 ] ] ] );
		$loopbacks = [];
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$loopbacks ) {
				$loopbacks[] = $args['body'];
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'auto_resumed', $result['action'] );
		$this->assertCount( 1, $loopbacks, 'Deve partire un solo nuovo hop.' );
		$this->assertSame( 'new-chain', $loopbacks[0]['chain_id'] );
		$this->assertSame( 'running', $this->saved_state['status'] );
		$this->assertTrue( $this->saved_state['resuming'] );
		$this->assertSame( 'new-chain', $this->saved_state['chain_id'] );
		$this->assertSame( 1, $this->saved_state['auto_resume_count'] );
		$this->assertSame( '0:225', $this->saved_state['auto_resume_position'] );
		$this->assertStringContainsString( 'Ripresa automatica 1 di 3', $this->saved_state['last_message'] );
		$this->assertStringContainsString( 'da 4 a 2', $this->saved_state['last_message'] );

		$set_calls = \PMXI_Import_Record::getSetCalls();
		$this->assertSame( 2, $set_calls[0]['options']['records_per_request'] ?? null, 'records_per_request va dimezzato e salvato.' );

		$pmxi_reset = array_filter( $this->db_updates, static fn( $u ) => str_ends_with( $u['table'], 'pmxi_imports' ) );
		$this->assertNotEmpty( $pmxi_reset, 'forceStopPmxiCron deve azzerare i flag PMXI.' );
	}

	public function test_attempt_counter_restarts_when_chain_made_progress(): void {
		// Ultima ripresa automatica avvenuta al record 200: ora la catena è al 225.
		$this->boot( [ 'auto_resume_position' => '0:200', 'auto_resume_count' => 3 ] );
		\PMXI_Import_Record::configureStub( [ [ 'empty' => false, 'options' => [ 'records_per_request' => 2 ] ] ] );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'auto_resumed', $result['action'] );
		$this->assertSame( 1, $this->saved_state['auto_resume_count'] );
	}

	public function test_exhausted_attempts_mark_the_run_as_crashed(): void {
		$this->boot( [ 'auto_resume_position' => '0:225', 'auto_resume_count' => 3 ] );
		Functions\when( 'wp_remote_post' )->alias( function () { $this->fail( 'Nessun nuovo hop atteso.' ); } );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'crashed', $result['action'] );
		$this->assertSame( 'error', $this->saved_state['status'] );
		$this->assertStringContainsString( 'riprese automatiche esaurite', $this->saved_state['crash_reason'] );
	}

	public function test_alive_chunk_is_never_touched_even_if_stale(): void {
		$this->boot( [ 'updated_at' => time() - 1200 ] );
		$this->wpdb->sim_is_free_lock = '0';
		Functions\when( 'wp_remote_post' )->alias( function () { $this->fail( 'Nessun nuovo hop atteso.' ); } );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'none', $result['action'] );
		$this->assertTrue( $result['exec_alive'] );
		$this->assertGreaterThan( MVD_WAI_CTRL_SLOW_THRESHOLD, $result['stalled_sec'] );
		$this->assertSame( 'running', $this->saved_state['status'] );
		$this->assertSame( 'old-chain', $this->saved_state['chain_id'] );
	}

	public function test_concurrent_watchdog_without_control_lock_does_nothing(): void {
		$this->boot();
		$this->wpdb->sim_get_lock = '0';
		Functions\when( 'wp_remote_post' )->alias( function () { $this->fail( 'Nessun nuovo hop atteso.' ); } );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'busy', $result['action'] );
		$this->assertSame( 'old-chain', $this->saved_state['chain_id'] );
	}

	public function test_auto_resume_can_be_disabled_by_filter(): void {
		$this->boot();
		Filters\expectApplied( 'mvd_wai_ctrl_auto_resume_max' )->andReturn( 0 );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'crashed', $result['action'] );
	}

	public function test_halving_can_be_disabled_by_filter(): void {
		$this->boot();
		\PMXI_Import_Record::configureStub( [ [ 'empty' => false, 'options' => [ 'records_per_request' => 4 ] ] ] );
		Filters\expectApplied( 'mvd_wai_ctrl_auto_halve_records' )->andReturn( false );

		$result = \MvdWaiCtrlWatchdog::check();

		$this->assertSame( 'auto_resumed', $result['action'] );
		$this->assertSame( [], \PMXI_Import_Record::getSetCalls() );
	}

	public function test_halve_records_never_goes_below_one(): void {
		$this->boot();
		\PMXI_Import_Record::configureStub( [ [ 'empty' => false, 'options' => [ 'records_per_request' => 1 ] ] ] );

		$this->assertSame( [ 'before' => 1, 'after' => 1 ], \MvdWaiCtrlRunner::halveRecordsPerRequest( 13 ) );
		$this->assertSame( [], \PMXI_Import_Record::getSetCalls(), 'Nessun salvataggio se il valore non cambia.' );
	}

	public function test_cron_tick_unschedules_itself_when_chain_is_not_running(): void {
		$this->boot( [ 'status' => 'completed' ] );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( \MvdWaiCtrlWatchdog::CRON_HOOK );

		\MvdWaiCtrlWatchdog::cronTick();
	}
}
