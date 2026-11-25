<?php
/**
 * Test per le correzioni 1.5.3: flag resuming, esito di execute() diverso da 200, durata dello step.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Verifica azzeramento di resuming, gestione degli status PMXI e calcolo della durata.
 */
class RunnerResumeAndStatusTest extends TestCase {

	/**
	 * Stato salvato nell'option, aggiornato da update_option() durante il test.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_state = [];

	/**
	 * Inizializza lo stato in memoria e gli stub WP comuni.
	 *
	 * @param array<string, mixed> $state Stato iniziale dell'option.
	 * @return void
	 */
	private function bootState( array $state ): void {
		$this->saved_state = array_merge(
			[
				'status'                    => 'running',
				'run_id'                    => 9,
				'current_index'             => 0,
				'current_step_total_chunks' => 0,
				'current_step_started_at'   => '2024-01-01 00:00:00',
				'resuming'                  => false,
			],
			$state
		);

		// Solo l'option di stato è simulata; le altre (es. heartbeat) restituiscono il default.
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
		Functions\when( 'get_transient' )->alias(
			static fn( $key ) => MVD_WAI_CTRL_LOCK_KEY === $key ? false : true
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'secret' );
		Functions\when( 'admin_url' )->justReturn( 'http://localhost/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	public function test_mid_step_resume_clears_resuming_flag(): void {
		// Resume a metà passo: chunk già contati, quindi non è il primo chunk.
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'queue_chunk_number' => 40, 'count' => 276 ],
			]
		);
		$this->bootState(
			[
				'current_step_total_chunks' => 276,
				'resuming'                  => true,
			]
		);
		$this->createWpdbMock();

		\MvdWaiCtrlRunner::runStep();

		$this->assertFalse( $this->saved_state['resuming'], 'Il flag resuming deve essere azzerato anche con resume a metà passo.' );
	}

	public function test_advance_to_next_import_clears_resuming_flag(): void {
		$this->bootState( [ 'resuming' => true ] );

		$this->assertTrue( \MvdWaiCtrlState::advanceToNextImport() );
		$this->assertFalse( $this->saved_state['resuming'] );
		$this->assertSame( 1, $this->saved_state['current_index'] );
	}

	public function test_non_200_status_on_completed_import_is_logged_as_warning_and_chain_continues(): void {
		\PMXI_Import_Record::configureStub(
			[
				[
					'empty'              => false,
					'queue_chunk_number' => 0,
					'execute_return'     => [
						'status'  => 500,
						'message' => '#1 No matching elements found for Root element and XPath expression specified',
					],
				],
			]
		);
		$this->bootState( [ 'current_index' => 2 ] );

		$inserted = [];
		$wpdb     = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( &$inserted ) {
				$inserted[] = $data;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->never(); // closeRun non deve essere chiamato.

		\MvdWaiCtrlRunner::runStep();

		$this->assertCount( 1, $inserted );
		$this->assertSame( 'warning', $inserted[0]['outcome'], (string) $inserted[0]['message'] );
		$this->assertStringContainsString( 'status 500', $inserted[0]['message'] );
		$this->assertSame( 3, $this->saved_state['current_index'], 'La catena deve proseguire con l\'import successivo.' );
		$this->assertSame( 'running', $this->saved_state['status'] );
	}

	public function test_non_200_status_with_pending_chunks_stops_chain_with_error(): void {
		\PMXI_Import_Record::configureStub(
			[
				[
					'empty'              => false,
					'queue_chunk_number' => 120,
					'count'              => 276,
					'execute_return'     => [
						'status'  => 403,
						'message' => 'Licence refused',
					],
				],
			]
		);
		$this->bootState( [ 'current_step_total_chunks' => 276 ] );

		$outcomes = [];
		$wpdb     = $this->createWpdbMock();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( &$outcomes ) {
				$outcomes[] = $data['outcome'];
				return 1;
			}
		);
		$close_outcome = null;
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) use ( &$close_outcome ) {
				$close_outcome = $data['outcome'] ?? null;
				return 1;
			}
		);
		Functions\expect( 'wp_remote_post' )->never();

		\MvdWaiCtrlRunner::runStep();

		$this->assertSame( [ 'error' ], $outcomes );
		$this->assertSame( 'error', $close_outcome );
		$this->assertSame( 'error', $this->saved_state['status'] );
	}

	public function test_orphan_hop_does_not_write_state_nor_reschedule(): void {
		// Chunk terminato, ma nel frattempo la connessione ha perso il lock (es. riconnessione wpdb).
		\PMXI_Import_Record::configureStub(
			[
				[ 'empty' => false, 'queue_chunk_number' => 80, 'count' => 276 ],
			]
		);
		$this->bootState(
			[
				'current_step_total_chunks' => 276,
				'current_chunk_done'        => 40,
			]
		);
		$wpdb                = $this->createWpdbMock();
		$wpdb->sim_owns_lock = '0';
		Functions\expect( 'wp_remote_post' )->never();

		\MvdWaiCtrlRunner::runStep();

		$this->assertSame( 40, $this->saved_state['current_chunk_done'], 'Lo stato non deve essere aggiornato da un hop orfano.' );
		$this->assertSame( 'running', $this->saved_state['status'] );
	}

	public function test_extract_execute_warning(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$this->assertNull( \MvdWaiCtrlRunner::extractExecuteWarning( new \stdClass() ) );
		$this->assertNull( \MvdWaiCtrlRunner::extractExecuteWarning( [ 'status' => 200, 'message' => 'ok' ] ) );
		$this->assertSame(
			'status 500: a | b',
			\MvdWaiCtrlRunner::extractExecuteWarning( [ 'status' => 500, 'message' => [ '<b>a</b>', 'b' ] ] )
		);
	}

	public function test_seconds_since_local_uses_site_timezone(): void {
		$tz = new \DateTimeZone( 'Europe/Rome' );
		Functions\when( 'wp_timezone' )->justReturn( $tz );

		$ten_min_ago = ( new \DateTimeImmutable( '@' . ( time() - 600 ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );

		$elapsed = \MvdWaiCtrlRunner::secondsSinceLocal( $ten_min_ago );

		$this->assertGreaterThanOrEqual( 600, $elapsed );
		$this->assertLessThan( 605, $elapsed );
	}
}
