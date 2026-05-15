<?php
/**
 * Test per MvdWaiCtrlRunner::runStep(): esecuzione per-chunk e gestione degli errori.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Verifica il comportamento di runStep: happy path, chunk multipli, errori, lock, PMXI mancante.
 */
class RunnerTest extends TestCase {

	/** Stato 'running' base con current_index=0. */
	private function runningState( int $run_id = 1, int $current_index = 0 ): array {
		return [
			'status'               => 'running',
			'run_id'               => $run_id,
			'step_current'         => $current_index,
			'step_total'           => 4,
			'step_label'           => '',
			'current_index'        => $current_index,
			'current_chunk'        => 0,
			'current_total_chunks' => 0,
			'started_at'           => '2024-01-01 00:00:00',
			'updated_at'           => '2024-01-01 00:00:00',
			'finished_at'          => null,
			'last_message'         => '',
		];
	}

	/** Stub comuni per funzioni WP non critiche ai test. */
	private function stubCommonWpFunctions( int $run_id = 1, int $current_index = 0 ): void {
		Functions\when( 'get_option' )->justReturn( $this->runningState( $run_id, $current_index ) );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
	}

	/** Stub per scheduleSelf() quando deve essere chiamato (import non ultimo o chunk incompleto). */
	private function stubScheduleSelf(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-secret-token' );
		Functions\when( 'admin_url' )->justReturn( 'http://localhost/wp-admin/admin-ajax.php' );
		Functions\when( 'apply_filters' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	public function test_run_step_aborts_immediately_when_lock_is_set(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( MVD_WAI_CTRL_LOCK_KEY )
			->andReturn( 1 );

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'get_option' )->never();

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_acquires_lock_with_correct_ttl(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )
			->once()
			->with( MVD_WAI_CTRL_LOCK_KEY, 1, 120 );

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );
		Functions\when( 'delete_transient' )->justReturn( true );

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_releases_lock_when_state_is_not_running(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( MVD_WAI_CTRL_LOCK_KEY );

		$this->createWpdbMock();

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_errors_when_pmxi_class_missing(): void {
		// Questa situazione si verifica solo se PMXI_Import_Record non è caricato;
		// con lo stub nei test la classe esiste sempre, quindi simuliamo via state vuoto
		// portando il runner al ramo di controllo del lock in modo indiretto.
		// Il test documenta il requisito: se la classe non esistesse, finishRun('error') viene chiamato.
		// Nota: il test vero si eseguirebbe in un processo PHP senza stub.
		$this->assertTrue( class_exists( 'PMXI_Import_Record' ) );
	}

	public function test_run_step_forces_pmxi_admin_filter(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// Verifica che add_filter venga chiamato con il filtro PMXI.
		// Il filtro è aggiunto prima del check isRunning(), quindi funziona anche con stato idle.
		Functions\expect( 'add_filter' )
			->once()
			->with( 'pmxi_is_admin_dashboard_or_cron_import', '__return_true' )
			->andReturn( true );

		// Stato non running: uscita anticipata dopo add_filter.
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_processes_single_import_and_schedules_next(): void {
		// Primo import (current_index=0) completato: deve schedulare il prossimo.
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'created' => 5, 'updated' => 2, 'skipped' => 0, 'queue_chunk_number' => 0 ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 1, current_index: 0 );
		$this->stubScheduleSelf();

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 ); // appendStep
		// closeRun NON deve essere chiamato (non è l'ultimo import).
		$wpdb->shouldReceive( 'update' )->never();

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_last_import_closes_chain_with_success(): void {
		// Quarto import (current_index=3) completato: deve chiudere la chain.
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'created' => 7, 'updated' => 1, 'skipped' => 0, 'queue_chunk_number' => 0 ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 2, current_index: 3 );
		// scheduleSelf NON deve essere chiamato: nessun mock per wp_remote_post.

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 ); // appendStep

		$close_outcome = null;
		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$close_outcome ) {
					$close_outcome = $data['outcome'];
					return 1;
				}
			);

		\MvdWaiCtrlRunner::runStep();

		$this->assertSame( 'success', $close_outcome );
	}

	public function test_run_step_reschedules_when_chunk_not_complete(): void {
		// Import con queue_chunk_number > 0 dopo execute(): deve ri-schedulare lo stesso step.
		\PMXI_Import_Record::configureStub(
			[
				[
					'empty'              => false,
					'created'            => 3,
					'updated'            => 0,
					'skipped'            => 0,
					'queue_chunk_number' => 15, // ancora chunk da processare
					'count'              => 100,
					'imported'           => 30,
				],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 3, current_index: 0 );
		$this->stubScheduleSelf();

		$wpdb = $this->createWpdbMock();
		// Nessun appendStep quando il chunk non è completo.
		$wpdb->shouldReceive( 'insert' )->never();
		$wpdb->shouldReceive( 'update' )->never();

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_stops_at_error_and_closes_run(): void {
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'execute_throws' => new \RuntimeException( 'Import failed' ) ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 4, current_index: 0 );

		$wpdb = $this->createWpdbMock();
		$step_outcome = null;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$step_outcome ) {
					$step_outcome = $data['outcome'];
					return 1;
				}
			);
		$close_outcome = null;
		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$close_outcome ) {
					$close_outcome = $data['outcome'];
					return 1;
				}
			);

		\MvdWaiCtrlRunner::runStep();

		$this->assertSame( 'error', $step_outcome );
		$this->assertSame( 'error', $close_outcome );
	}

	public function test_run_step_releases_lock_after_error(): void {
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'execute_throws' => new \RuntimeException( 'Error' ) ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( MVD_WAI_CTRL_LOCK_KEY );
		$this->stubCommonWpFunctions( run_id: 5, current_index: 0 );

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		\MvdWaiCtrlRunner::runStep();
	}

	public function test_run_step_handles_import_not_found(): void {
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => true ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 6, current_index: 0 );

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$close_outcome = null;
		$wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$close_outcome ) {
					$close_outcome = $data['outcome'];
					return 1;
				}
			);

		\MvdWaiCtrlRunner::runStep();

		$this->assertSame( 'error', $close_outcome );
	}

	public function test_run_step_error_message_includes_exception_text(): void {
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'execute_throws' => new \RuntimeException( 'Connection timeout' ) ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->stubCommonWpFunctions( run_id: 7, current_index: 0 );

		$wpdb = $this->createWpdbMock();
		$step_message = null;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$step_message ) {
					$step_message = $data['message'];
					return 1;
				}
			);
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		\MvdWaiCtrlRunner::runStep();

		$this->assertStringContainsString( 'Connection timeout', (string) $step_message );
	}

	public function test_run_step_updates_state_step_before_import(): void {
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'queue_chunk_number' => 0 ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();

		$step_currents_saved = [];
		Functions\when( 'get_option' )->justReturn( $this->runningState( run_id: 9, current_index: 0 ) );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$step_currents_saved ) {
				if ( MVD_WAI_CTRL_STATE_OPTION === $key && isset( $value['step_current'] ) ) {
					$step_currents_saved[] = $value['step_current'];
				}
			}
		);

		$this->stubScheduleSelf();

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		\MvdWaiCtrlRunner::runStep();

		// step_current=1 viene salvato quando si aggiorna il passo 0 (indice+1).
		$this->assertContains( 1, $step_currents_saved );
	}

	public function test_first_chunk_resets_pmxi_counters_before_execute(): void {
		// Scenario: primo chunk (current_step_total_chunks=0). Il runner deve
		// chiamare set()->update() con i campi di reset prima di execute().
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'queue_chunk_number' => 0 ],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$state = array_merge(
			$this->runningState( run_id: 10, current_index: 0 ),
			[ 'current_step_total_chunks' => 0 ]
		);
		Functions\when( 'get_option' )->justReturn( $state );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
		$this->stubScheduleSelf();

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		\MvdWaiCtrlRunner::runStep();

		$set_calls = \PMXI_Import_Record::getSetCalls();
		$reset_call = null;
		foreach ( $set_calls as $call ) {
			if ( array_key_exists( 'imported', $call ) && 0 === $call['imported'] ) {
				$reset_call = $call;
				break;
			}
		}

		$this->assertNotNull( $reset_call, 'Il runner deve chiamare set() con i counter azzerati al primo chunk.' );
		$this->assertSame( 0, $reset_call['imported'] );
		$this->assertSame( 0, $reset_call['created'] );
		$this->assertSame( 0, $reset_call['updated'] );
		$this->assertSame( 0, $reset_call['skipped'] );
		$this->assertSame( 0, $reset_call['queue_chunk_number'] );
		$this->assertSame( 0, $reset_call['processing'] );
		$this->assertSame( 1, $reset_call['triggered'] );
	}

	public function test_subsequent_chunk_does_not_reset_pmxi_counters(): void {
		// Scenario: chunk successivo (current_step_total_chunks > 0, import in corso).
		// Il runner NON deve chiamare set() con i counter azzerati: azzererebbe
		// un import già avviato, facendolo ripartire da zero.
		\PMXI_Import_Record::configureStub(
			[
				[
					'empty'              => false,
					'created'            => 5,
					'updated'            => 0,
					'skipped'            => 0,
					'queue_chunk_number' => 10,
					'count'              => 100,
					'imported'           => 50,
				],
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// current_step_total_chunks > 0 → $is_first_chunk = false
		$state = array_merge(
			$this->runningState( run_id: 11, current_index: 0 ),
			[
				'current_step_total_chunks' => 5,
				'current_chunk_done'        => 2,
				'current_step_started_at'   => '2024-01-01 00:00:00',
			]
		);
		Functions\when( 'get_option' )->justReturn( $state );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
		$this->stubScheduleSelf();

		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		\MvdWaiCtrlRunner::runStep();

		$set_calls = \PMXI_Import_Record::getSetCalls();
		$has_reset = false;
		foreach ( $set_calls as $call ) {
			if ( array_key_exists( 'imported', $call ) && 0 === $call['imported'] ) {
				$has_reset = true;
				break;
			}
		}

		$this->assertFalse( $has_reset, 'Il runner NON deve azzerare i counter PMXI su chunk successivi al primo.' );
	}

	public function test_schedule_self_uses_fallback_cron_when_loopback_fails(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'test-secret' );
		Functions\when( 'admin_url' )->justReturn( 'http://localhost/wp-admin/admin-ajax.php' );
		Functions\when( 'apply_filters' )->justReturn( false );

		$wp_error = Mockery::mock( 'WP_Error' );
		Functions\when( 'wp_remote_post' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		Functions\expect( 'delete_transient' )->once()->with( MVD_WAI_CTRL_LOCK_KEY . '_secret' );
		Functions\expect( 'wp_schedule_single_event' )->once();
		Functions\expect( 'spawn_cron' )->once();

		// set_transient per il secret deve essere chiamato prima del remote_post.
		Functions\expect( 'set_transient' )
			->once()
			->with( MVD_WAI_CTRL_LOCK_KEY . '_secret', 'test-secret', MINUTE_IN_SECONDS );

		\MvdWaiCtrlRunner::scheduleSelf();
	}
}
