<?php
/**
 * Test per MvdWaiCtrlLogger: CRUD sulla tabella di log mvd_wai_ctrl_log.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;

/**
 * Verifica inserimento run, step e query di lettura.
 */
class LoggerTest extends TestCase {

	private MockInterface $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = $this->createWpdbMock();
	}

	public function test_table_name_returns_prefixed_table(): void {
		$this->assertSame( 'wp_mvd_wai_ctrl_log', \MvdWaiCtrlLogger::tableName() );
	}

	public function test_create_run_inserts_start_row_and_returns_id(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 42;
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$run_id = \MvdWaiCtrlLogger::createRun();

		$this->assertSame( 42, $run_id );
	}

	public function test_create_run_sets_run_id_equal_to_inserted_id(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );

		$captured_update_data = null;
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$this->wpdb->insert_id = 99;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured_update_data ) {
					$captured_update_data = [ 'data' => $data, 'where' => $where ];
					return 1;
				}
			);

		\MvdWaiCtrlLogger::createRun();

		$this->assertSame( 99, $captured_update_data['data']['run_id'] );
		$this->assertSame( 99, $captured_update_data['where']['id'] );
	}

	public function test_append_step_inserts_row_with_all_fields(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:01:00' );
		Functions\when( 'sanitize_key' )->returnArg();

		$inserted = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$inserted ) {
					$inserted = $data;
					return 1;
				}
			);

		\MvdWaiCtrlLogger::appendStep(
			10,
			[
				'step_index'   => 0,
				'import_id'    => 13,
				'outcome'      => 'success',
				'created'      => 5,
				'updated'      => 3,
				'skipped'      => 1,
				'duration_sec' => 12,
				'message'      => 'tutto ok',
			]
		);

		$this->assertSame( 10, $inserted['run_id'] );
		$this->assertSame( 0, $inserted['step_index'] );
		$this->assertSame( 13, $inserted['import_id'] );
		$this->assertSame( 'success', $inserted['outcome'] );
		$this->assertSame( 5, $inserted['created'] );
		$this->assertSame( 3, $inserted['updated'] );
		$this->assertSame( 1, $inserted['skipped'] );
		$this->assertSame( 12, $inserted['duration_sec'] );
		$this->assertSame( 'tutto ok', $inserted['message'] );
		$this->assertSame( '2024-01-01 00:01:00', $inserted['created_at'] );
	}

	public function test_append_step_truncates_message_at_65535_bytes(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:01:00' );
		Functions\when( 'sanitize_key' )->returnArg();

		$actual_message = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$actual_message ) {
					$actual_message = $data['message'];
					return 1;
				}
			);

		\MvdWaiCtrlLogger::appendStep( 1, [ 'message' => str_repeat( 'a', 70_000 ) ] );

		$this->assertSame( 65535, strlen( (string) $actual_message ) );
	}

	public function test_append_step_uses_null_when_no_message(): void {
		Functions\when( 'current_time' )->justReturn( '2024-01-01 00:01:00' );
		Functions\when( 'sanitize_key' )->returnArg();

		$inserted_message = 'not-set';
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$inserted_message ) {
					$inserted_message = $data['message'];
					return 1;
				}
			);

		\MvdWaiCtrlLogger::appendStep( 1, [] );

		$this->assertNull( $inserted_message );
	}

	public function test_close_run_updates_outcome_on_start_row(): void {
		Functions\when( 'sanitize_key' )->returnArg();

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_mvd_wai_ctrl_log',
				[ 'outcome'       => 'success' ],
				[ 'id'            => 5, 'is_run_header' => 1 ],
				[ '%s' ],
				[ '%d', '%d' ]
			)
			->andReturn( 1 );

		\MvdWaiCtrlLogger::closeRun( 5, 'success' );
	}

	public function test_close_run_sanitizes_outcome(): void {
		$sanitized_outcome = null;
		Functions\when( 'sanitize_key' )->alias(
			function ( $v ) use ( &$sanitized_outcome ) {
				$sanitized_outcome = $v;
				return $v;
			}
		);

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		\MvdWaiCtrlLogger::closeRun( 1, 'error' );

		$this->assertSame( 'error', $sanitized_outcome );
	}

	public function test_get_recent_runs_returns_empty_when_table_is_empty(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT_QUERY' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'SELECT_QUERY', ARRAY_A )
			->andReturn( [] );

		$result = \MvdWaiCtrlLogger::getRecentRuns( 5 );

		$this->assertSame( [], $result );
	}

	public function test_get_recent_runs_clamps_limit_to_max_100(): void {
		$limit_used = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql, ...$args ) use ( &$limit_used ) {
					$limit_used = $args[0];
					return 'SQL';
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		\MvdWaiCtrlLogger::getRecentRuns( 500 );

		$this->assertSame( 100, $limit_used );
	}

	public function test_get_recent_runs_clamps_limit_to_min_1(): void {
		$limit_used = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql, ...$args ) use ( &$limit_used ) {
					$limit_used = $args[0];
					return 'SQL';
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		\MvdWaiCtrlLogger::getRecentRuns( 0 );

		$this->assertSame( 1, $limit_used );
	}

	public function test_get_recent_runs_groups_steps_by_run_id(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturnValues(
				[
					// Prima chiamata: righe di run (step_index = -1).
					[
						[ 'id' => '3', 'run_id' => '3', 'outcome' => 'success', 'created_at' => '2024-01-02 00:00:00' ],
						[ 'id' => '1', 'run_id' => '1', 'outcome' => 'error',   'created_at' => '2024-01-01 00:00:00' ],
					],
					// Seconda chiamata: step dei run trovati.
					[
						[ 'run_id' => '1', 'step_index' => '0', 'import_id' => '13', 'outcome' => 'success', 'created' => '5', 'updated' => '0', 'skipped' => '0', 'duration_sec' => '10', 'message' => null, 'created_at' => '2024-01-01 00:00:01' ],
						[ 'run_id' => '3', 'step_index' => '0', 'import_id' => '13', 'outcome' => 'success', 'created' => '8', 'updated' => '0', 'skipped' => '0', 'duration_sec' => '5',  'message' => null, 'created_at' => '2024-01-02 00:00:01' ],
					],
				]
			);

		$result = \MvdWaiCtrlLogger::getRecentRuns( 2 );

		$this->assertCount( 2, $result );
		// Primo risultato = run_id 3
		$this->assertSame( '3', $result[0]['run']['run_id'] );
		$this->assertCount( 1, $result[0]['steps'] );
		// Secondo risultato = run_id 1
		$this->assertSame( '1', $result[1]['run']['run_id'] );
		$this->assertCount( 1, $result[1]['steps'] );
	}
}
