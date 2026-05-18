<?php
/**
 * Test per MvdWaiCtrlRunner::forceStopPmxiCron().
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Verifica che forceStopPmxiCron resetti i flag runtime PMXI o ritorni not_found.
 */
class RunnerForceStopPmxiCronTest extends TestCase {

	/**
	 * Se la riga PMXI esiste, update viene chiamato con i 3 flag a 0 e viene restituito ok=true.
	 */
	public function test_resets_flags_when_row_exists(): void {
		$wpdb         = $this->createWpdbMock();
		$wpdb->prefix = 'wp_';

		$before_row = [
			'triggered'     => '1',
			'processing'    => '1',
			'executing'     => '0',
			'last_activity' => '2026-05-18 10:00:00',
		];

		Functions\when( 'sanitize_key' )->returnArg();

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT ... FROM wp_pmxi_imports WHERE id = 2' );

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $before_row );

		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_pmxi_imports',
				[
					'triggered'  => 0,
					'processing' => 0,
					'executing'  => 0,
				],
				[ 'id' => 2 ],
				[ '%d', '%d', '%d' ],
				[ '%d' ]
			)
			->andReturn( 1 );

		$result = \MvdWaiCtrlRunner::forceStopPmxiCron( 2 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $before_row, $result['before'] );
	}

	/**
	 * Se la riga PMXI non esiste, update non viene chiamato e viene restituito ok=false.
	 */
	public function test_returns_not_found_when_row_missing(): void {
		$wpdb         = $this->createWpdbMock();
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT ... FROM wp_pmxi_imports WHERE id = 99' );

		$wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( null );

		$wpdb->shouldReceive( 'update' )->never();

		$result = \MvdWaiCtrlRunner::forceStopPmxiCron( 99 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['reason'] );
	}
}
