<?php
/**
 * Test per MvdWaiCtrlState: gestione dello stato di esecuzione tramite WP option.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Verifica le transizioni di stato: idle, running, completed, error.
 */
class StateTest extends TestCase {

	public function test_get_returns_defaults_when_option_absent(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MVD_WAI_CTRL_STATE_OPTION, null )
			->andReturn( null );

		$state = \MvdWaiCtrlState::get();

		$this->assertSame( 'idle', $state['status'] );
		$this->assertSame( 0, $state['run_id'] );
		$this->assertSame( 0, $state['step_current'] );
		$this->assertSame( count( MVD_WAI_CTRL_IDS ), $state['step_total'] );
		$this->assertSame( '', $state['step_label'] );
		$this->assertSame( '', $state['last_message'] );
		$this->assertNull( $state['finished_at'] );
	}

	public function test_get_returns_defaults_when_option_not_array(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupt_value' );

		$state = \MvdWaiCtrlState::get();

		$this->assertSame( 'idle', $state['status'] );
	}

	public function test_get_merges_partial_data_with_defaults(): void {
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'running', 'run_id' => 7 ] );

		$state = \MvdWaiCtrlState::get();

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 7, $state['run_id'] );
		$this->assertSame( '', $state['step_label'] ); // default mantenuto
	}

	public function test_save_persists_state_with_no_autoload(): void {
		Functions\expect( 'current_time' )->once()->andReturn( '2024-01-01 12:00:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on(
					fn( $v ) => 'running' === $v['status'] && '2024-01-01 12:00:00' === $v['updated_at']
				),
				false
			);

		\MvdWaiCtrlState::save( [ 'status' => 'running' ] );
	}

	public function test_is_running_true_when_status_is_running(): void {
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'running' ] );

		$this->assertTrue( \MvdWaiCtrlState::isRunning() );
	}

	public function test_is_running_false_when_status_is_idle(): void {
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'idle' ] );

		$this->assertFalse( \MvdWaiCtrlState::isRunning() );
	}

	public function test_is_running_false_when_status_is_completed(): void {
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'completed' ] );

		$this->assertFalse( \MvdWaiCtrlState::isRunning() );
	}

	public function test_is_running_false_when_status_is_error(): void {
		Functions\when( 'get_option' )->justReturn( [ 'status' => 'error' ] );

		$this->assertFalse( \MvdWaiCtrlState::isRunning() );
	}

	public function test_start_run_saves_running_status_with_correct_fields(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 10:00:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on(
					function ( $v ) {
						return 'running' === $v['status']
							&& 42 === $v['run_id']
							&& 0 === $v['step_current']
							&& count( MVD_WAI_CTRL_IDS ) === $v['step_total']
							&& null === $v['finished_at'];
					}
				),
				false
			);

		\MvdWaiCtrlState::startRun( 42 );
	}

	public function test_update_step_increments_current_step_by_one(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'status'       => 'running',
				'run_id'       => 1,
				'step_current' => 0,
				'step_total'   => 4,
				'step_label'   => '',
				'started_at'   => '',
				'updated_at'   => '',
				'finished_at'  => null,
				'last_message' => '',
			]
		);
		Functions\when( 'current_time' )->justReturn( '2024-01-01 10:01:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on(
					fn( $v ) => 2 === $v['step_current']
						&& 'Passo 2 di 4' === $v['step_label']
						&& 'Avvio...' === $v['last_message']
				),
				false
			);

		// step_index=1 → step_current deve diventare 2
		\MvdWaiCtrlState::updateStep( 1, 'Passo 2 di 4', 'Avvio...' );
	}

	public function test_finish_run_sets_completed_status_and_finished_at(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'status' => 'running', 'run_id' => 1, 'last_message' => '', 'finished_at' => null ]
		);
		Functions\when( 'current_time' )->justReturn( '2024-01-01 10:05:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on(
					fn( $v ) => 'completed' === $v['status']
						&& '2024-01-01 10:05:00' === $v['finished_at']
				),
				false
			);

		\MvdWaiCtrlState::finishRun( 'completed' );
	}

	public function test_finish_run_sets_message_when_provided(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'status' => 'running', 'run_id' => 1, 'last_message' => '', 'finished_at' => null ]
		);
		Functions\when( 'current_time' )->justReturn( '2024-01-01 10:05:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on( fn( $v ) => 'error' === $v['status'] && 'Import ID 13 non trovato.' === $v['last_message'] ),
				false
			);

		\MvdWaiCtrlState::finishRun( 'error', 'Import ID 13 non trovato.' );
	}

	public function test_finish_run_does_not_overwrite_message_when_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'status' => 'running', 'run_id' => 1, 'last_message' => 'messaggio precedente', 'finished_at' => null ]
		);
		Functions\when( 'current_time' )->justReturn( '2024-01-01 10:05:00' );

		$saved = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
			}
		);

		\MvdWaiCtrlState::finishRun( 'completed' ); // nessun message

		$this->assertSame( 'messaggio precedente', $saved['last_message'] );
	}

	public function test_reset_saves_idle_state(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
		Functions\expect( 'update_option' )
			->once()
			->with(
				MVD_WAI_CTRL_STATE_OPTION,
				Mockery::on( fn( $v ) => 'idle' === $v['status'] && 0 === $v['run_id'] ),
				false
			);

		\MvdWaiCtrlState::reset();
	}
}
