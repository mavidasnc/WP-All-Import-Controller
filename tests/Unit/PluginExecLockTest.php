<?php
/**
 * Test 1.6.0: watchdog, resume, reset e start basati sul lock MySQL di esecuzione.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Verifica che nessuna azione avvii un secondo blocco mentre il lock di esecuzione è occupato.
 */
class PluginExecLockTest extends TestCase {

	/**
	 * Mock globale di wpdb con emulazione dei lock.
	 *
	 * @var \Mockery\MockInterface&\wpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = $this->createWpdbMock();
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		Functions\when( '__' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status ): void {
				throw new \RuntimeException( 'error:' . $status . ':' . $data['message'] );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ): void {
				throw new \RuntimeException( 'success:' . wp_json_encode( $data ) );
			}
		);
	}

	/**
	 * Simula l'option di stato; le altre option restituiscono il default.
	 *
	 * @param array<string, mixed> $state Stato da restituire.
	 * @return void
	 */
	private function stubState( array $state ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => MVD_WAI_CTRL_STATE_OPTION === $name ? $state : $default
		);
	}

	/**
	 * Esegue un handler AJAX e restituisce il messaggio dell'eccezione che simula wp_send_json_*.
	 *
	 * @param callable $handler Handler da eseguire.
	 * @return string           'success:{json}' oppure 'error:{status}:{messaggio}'.
	 */
	private function runHandler( callable $handler ): string {
		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'L\'handler non ha inviato una risposta JSON.' );
	}

	public function test_watchdog_does_not_crash_a_long_but_alive_chunk(): void {
		// Nessuna attività da 20 minuti, ma il lock è occupato: il blocco è vivo.
		$this->stubState( [ 'status' => 'running', 'updated_at' => time() - 1200, 'run_id' => 7, 'current_index' => 0 ] );
		$this->wpdb->sim_is_free_lock = '0';
		Functions\expect( 'update_option' )->never();

		$response = $this->runHandler( [ \MvdWaiCtrlPlugin::class, 'ajaxStatus' ] );
		$data     = json_decode( substr( $response, strlen( 'success:' ) ), true );

		$this->assertSame( 'running', $data['state']['status'] );
		$this->assertTrue( $data['exec_alive'] );
		$this->assertGreaterThan( MVD_WAI_CTRL_SLOW_THRESHOLD, $data['stalled_sec'] );
		$this->assertFalse( $data['can_resume'] );
	}

	public function test_watchdog_uses_heartbeat_option_as_last_activity(): void {
		// updated_at vecchio ma heartbeat recente: nessun crash anche a lock libero (tra due hop).
		$state = [ 'status' => 'running', 'updated_at' => time() - 1200, 'run_id' => 7, 'current_index' => 0 ];
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $state ) {
				if ( MVD_WAI_CTRL_STATE_OPTION === $name ) {
					return $state;
				}
				return \MvdWaiCtrlState::HEARTBEAT_OPTION === $name ? time() - 10 : $default;
			}
		);
		Functions\expect( 'update_option' )->never();

		$response = $this->runHandler( [ \MvdWaiCtrlPlugin::class, 'ajaxStatus' ] );

		$this->assertStringContainsString( '"status":"running"', $response );
	}

	public function test_resume_is_refused_while_exec_lock_is_busy(): void {
		$this->stubState( [ 'status' => 'error', 'run_id' => 7, 'current_index' => 0 ] );
		$this->wpdb->sim_is_free_lock = '0';
		Functions\expect( 'wp_remote_post' )->never();

		$response = $this->runHandler( [ \MvdWaiCtrlPlugin::class, 'ajaxResume' ] );

		$this->assertStringStartsWith( 'error:409:', $response );
		// forceStopPmxiCron non deve essere stato chiamato (nessuna UPDATE su pmxi_imports).
		$this->wpdb->shouldNotHaveReceived( 'update' );
	}

	public function test_control_actions_are_serialized(): void {
		// Un'altra richiesta di controllo detiene il lock _ctl.
		$this->stubState( [ 'status' => 'error', 'run_id' => 7, 'current_index' => 0 ] );
		$this->wpdb->sim_get_lock = '0';

		$response = $this->runHandler( [ \MvdWaiCtrlPlugin::class, 'ajaxResume' ] );

		$this->assertStringStartsWith( 'error:409:', $response );
		$this->assertStringContainsString( '_ctl', $this->wpdb->lock_queries[0] );
	}

	public function test_reset_warns_when_a_chunk_is_still_running(): void {
		$this->stubState( [ 'status' => 'running', 'run_id' => 7 ] );
		$this->wpdb->sim_is_free_lock = '0';
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );

		$response = $this->runHandler( [ \MvdWaiCtrlPlugin::class, 'ajaxReset' ] );

		$this->assertStringStartsWith( 'success:', $response );
		$this->assertStringContainsString( 'ancora in esecuzione', $response );
	}

	public function test_legacy_state_without_chain_id_accepts_any_hop(): void {
		$this->stubState( [ 'status' => 'running' ] );

		$this->assertTrue( \MvdWaiCtrlState::isCurrentChain( '' ) );
		$this->assertTrue( \MvdWaiCtrlState::isCurrentChain( 'whatever' ) );

		$this->stubState( [ 'status' => 'running', 'chain_id' => 'abc' ] );

		$this->assertTrue( \MvdWaiCtrlState::isCurrentChain( 'abc' ) );
		$this->assertFalse( \MvdWaiCtrlState::isCurrentChain( 'xyz' ) );
		$this->assertFalse( \MvdWaiCtrlState::isCurrentChain( '' ) );
	}
}
