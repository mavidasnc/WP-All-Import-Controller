<?php
/**
 * Test 1.8.0: ricollegamento WPML delle varianti (MvdWaiCtrlVariationLinker).
 */
declare( strict_types=1 );

namespace MvdWaiCtrl\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Verifica il piano di collegamento e i casi in cui l'hook non deve agire.
 */
class VariationLinkerTest extends TestCase {

	/**
	 * Costruisce una variante nel formato usato dal piano.
	 *
	 * @param int        $id          ID variante.
	 * @param int        $parent_id   ID padre.
	 * @param string     $sku         SKU.
	 * @param int|null   $trid        Trid della variante.
	 * @param int|null   $parent_trid Trid del padre.
	 * @param string     $unique_key  Unique key dell'import (default = SKU).
	 * @param int|null   $dup_of      Valore del meta _wcml_duplicate_of_variation.
	 * @return array<string, mixed>
	 */
	private function variation( int $id, int $parent_id, string $sku, ?int $trid, ?int $parent_trid, string $unique_key = '', ?int $dup_of = null ): array {
		return [
			'id'          => (string) $id,
			'parent_id'   => (string) $parent_id,
			'unique_key'  => '' === $unique_key ? $sku . '-KEY' : $unique_key,
			'sku'         => $sku,
			'trid'        => null === $trid ? null : (string) $trid,
			'parent_trid' => null === $parent_trid ? null : (string) $parent_trid,
			'dup_of'      => null === $dup_of ? null : (string) $dup_of,
		];
	}

	/**
	 * Coppia valida: stessa unique key, stesso SKU, padri collegati, variante IT sola nella trid.
	 */
	public function test_links_orphan_target_to_source_trid(): void {
		$targets = [ $this->variation( 200, 20, 'SKU-A', 900, 50 ) ];
		$sources = [ $this->variation( 100, 10, 'SKU-A', 800, 50 ) ];
		$members = [
			800 => [ 'en' => [ 100 ] ],
			900 => [ 'it' => [ 200 ] ],
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, $members, 'it', 'en' );

		$this->assertSame(
			[
				[
					'id'        => 200,
					'source_id' => 100,
					'trid'      => 800,
					'parent_id' => 20,
				],
			],
			$plan['link']
		);
		$this->assertSame( [], $plan['skipped'] );
	}

	/**
	 * SKU duplicato nello stesso padre: nessun collegamento, motivo duplicate_sku.
	 */
	public function test_skips_duplicate_sku_in_same_parent(): void {
		$targets = [
			$this->variation( 200, 20, 'SKU-A', 900, 50, 'K1' ),
			$this->variation( 201, 20, 'SKU-A', 901, 50, 'K2' ),
		];
		$sources = [
			$this->variation( 100, 10, 'SKU-A', 800, 50, 'K1' ),
			$this->variation( 101, 10, 'SKU-A', 801, 50, 'K2' ),
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, [], 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertCount( 2, $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_DUPLICATE_SKU ] );
	}

	/**
	 * La sorgente ha già una variante IT esistente nella sua trid: non si sovrascrive.
	 */
	public function test_skips_source_already_translated(): void {
		$targets = [ $this->variation( 200, 20, 'SKU-A', 900, 50 ) ];
		$sources = [ $this->variation( 100, 10, 'SKU-A', 800, 50 ) ];
		$members = [
			800 => [
				'en' => [ 100 ],
				'it' => [ 555 ],
			],
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, $members, 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertCount( 1, $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_SOURCE_TRANSLATED ] );
	}

	/**
	 * Padri in trid diverse (o padre senza lingua): non si collega.
	 */
	public function test_skips_when_parents_are_not_translations(): void {
		$targets = [
			$this->variation( 200, 20, 'SKU-A', 900, 51 ),
			$this->variation( 201, 21, 'SKU-B', 901, null ),
		];
		$sources = [
			$this->variation( 100, 10, 'SKU-A', 800, 50 ),
			$this->variation( 101, 11, 'SKU-B', 801, null ),
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, [], 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertCount( 2, $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_PARENT_NOT_LINKED ] );
	}

	/**
	 * Variante già collegata con meta WCML che punta a un ID inesistente: si corregge solo il meta.
	 */
	public function test_fixes_meta_of_already_linked_variation(): void {
		$targets = [ $this->variation( 200, 20, 'SKU-A', 800, 50, '', 99999 ) ];
		$sources = [ $this->variation( 100, 10, 'SKU-A', 800, 50 ) ];
		$members = [
			800 => [
				'en' => [ 100 ],
				'it' => [ 200 ],
			],
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, $members, 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertSame(
			[
				[
					'id'        => 200,
					'source_id' => 100,
				],
			],
			$plan['fix_meta']
		);
	}

	/**
	 * Varianti senza gemella in entrambe le direzioni e SKU diverso a parità di unique key.
	 */
	public function test_reports_missing_twins_and_sku_mismatch(): void {
		$targets = [
			$this->variation( 200, 20, 'SKU-IT-ONLY', 900, 50 ),
			$this->variation( 201, 20, 'SKU-X', 901, 50, 'SAME-KEY' ),
		];
		$sources = [
			$this->variation( 100, 10, 'SKU-EN-ONLY', 800, 50 ),
			$this->variation( 101, 10, 'SKU-Y', 801, 50, 'SAME-KEY' ),
		];
		$members = [
			800 => [ 'en' => [ 100 ] ],
			801 => [ 'en' => [ 101 ] ],
		];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, $members, 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertSame( '200', $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_NO_TWIN ][0]['id'] );
		$this->assertSame( '201', $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_SKU_MISMATCH ][0]['id'] );
		$missing = array_column( $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_MISSING_TRANSLATION ], 'id' );
		$this->assertSame( [ '100', '101' ], $missing );
	}

	/**
	 * Due varianti target con la stessa unique key: ambiguo, nessun collegamento.
	 */
	public function test_skips_ambiguous_unique_key(): void {
		$targets = [
			$this->variation( 200, 20, 'SKU-A', 900, 50, 'K' ),
			$this->variation( 201, 21, 'SKU-A', 901, 50, 'K' ),
		];
		$sources = [ $this->variation( 100, 10, 'SKU-A', 800, 50, 'K' ) ];

		$plan = \MvdWaiCtrlVariationLinker::buildLinkPlan( $targets, $sources, [], 'it', 'en' );

		$this->assertSame( [], $plan['link'] );
		$this->assertCount( 2, $plan['skipped'][ \MvdWaiCtrlVariationLinker::SKIP_AMBIGUOUS_KEY ] );
	}

	/**
	 * Import non collegato a un import sorgente (es. l'import EN): nessuna scrittura.
	 */
	public function test_link_import_does_nothing_for_unconnected_import(): void {
		if ( ! class_exists( 'SitePress' ) ) {
			eval( 'class SitePress {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub minimo per il test.
		}
		$wpdb = $this->createWpdbMock();
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn(
			serialize( [ 'wpml_addon' => [ 'lng' => 'en', 'matching_logic' => '', 'import' => '' ] ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		);
		$wpdb->shouldNotReceive( 'get_results' );
		Functions\when( 'maybe_unserialize' )->alias( 'unserialize' );
		Actions\expectDone( 'wpml_set_element_language_details' )->never();

		$result = \MvdWaiCtrlVariationLinker::linkImport( 13 );

		$this->assertFalse( $result['connected'] );
		$this->assertSame( 0, $result['linked'] );
	}

	/**
	 * Anteprima di WPAI: l'hook esce senza leggere nulla.
	 */
	public function test_after_import_ignores_preview(): void {
		$wpdb = $this->createWpdbMock();
		$wpdb->shouldNotReceive( 'get_var' );

		\MvdWaiCtrlVariationLinker::onAfterImport( 2, (object) [ 'is_preview' => true ] );

		$this->assertTrue( true );
	}
}
