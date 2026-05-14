<?php
/**
 * Classe base per tutti i test unitari del plugin.
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * TestCase base: inizializza Brain Monkey e Mockery, fornisce helper per wpdb.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// Resetta il contatore dello stub PMXI tra un test e l'altro.
		\PMXI_Import_Record::resetStub();

		// Rimuove il mock globale di $wpdb.
		global $wpdb;
		$wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		// Conta le Mockery expectations come assertion PHPUnit.
		$container = Mockery::getContainer();
		if ( null !== $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Crea un mock parziale di wpdb e lo assegna alla variabile globale $wpdb.
	 *
	 * I test che interagiscono con Logger o con qualsiasi codice che usa $wpdb
	 * devono chiamare questo helper nel loro setUp o all'inizio del test.
	 */
	protected function createWpdbMock(): MockInterface {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$wpdb->prefix = 'wp_';
		$wpdb->insert_id = 0;
		return $wpdb;
	}
}
