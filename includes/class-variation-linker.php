<?php
/**
 * Ricollegamento WPML delle varianti prodotto importate da WP All Import.
 *
 * WPML All Import collega ogni record alla sua traduzione usando il tipo dell'import
 * (`post_product`) anche quando il record è una variante: WPML scrive una riga spuria di
 * tipo `post_product` e la riga reale `post_product_variation` resta da sola, come se la
 * variante fosse un contenuto originale nella lingua secondaria. Questa classe, a fine di
 * ogni import collegato, riaggancia le varianti alla variante originale con il tipo corretto.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ricollega in WPML le varianti di un import alle varianti dell'import sorgente.
 */
class MvdWaiCtrlVariationLinker {

	/** Tipo elemento WPML delle varianti. */
	const ELEMENT_TYPE = 'post_product_variation';

	/** Tipo elemento WPML dei prodotti padre. */
	const PARENT_ELEMENT_TYPE = 'post_product';

	/** Meta di WCML che punta alla variante originale. */
	const DUPLICATE_META = '_wcml_duplicate_of_variation';

	/** Priorità su pmxi_after_xml_import: dopo la sostituzione varianti del tema (10), prima del restore (999). */
	const HOOK_PRIORITY = 50;

	/** Motivi di esclusione dal collegamento, usati nel report. */
	const SKIP_NO_TWIN               = 'no_twin';
	const SKIP_AMBIGUOUS_KEY         = 'ambiguous_unique_key';
	const SKIP_SKU_MISMATCH          = 'sku_mismatch';
	const SKIP_DUPLICATE_SKU         = 'duplicate_sku';
	const SKIP_PARENT_NOT_LINKED     = 'parent_not_linked';
	const SKIP_SOURCE_TRANSLATED     = 'source_already_translated';
	const SKIP_SOURCE_NO_LANGUAGE    = 'source_without_language';
	const SKIP_MISSING_TRANSLATION   = 'missing_translation';

	/**
	 * Registra l'hook di fine import e, in WP-CLI, il comando di riparazione.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pmxi_after_xml_import', [ __CLASS__, 'onAfterImport' ], self::HOOK_PRIORITY, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'mvd-wai-ctrl link-variations', [ __CLASS__, 'cliLinkVariations' ] );
		}
	}

	/**
	 * A fine import ricollega le varianti dell'import appena concluso, se è collegato a un import sorgente.
	 *
	 * @param int   $import_id ID dell'import WP All Import.
	 * @param mixed $import    Oggetto import (assente in alcune versioni di WPAI).
	 * @return void
	 */
	public static function onAfterImport( $import_id, $import = null ): void {
		// L'anteprima di WPAI esegue l'import con is_preview: niente modifiche.
		if ( is_object( $import ) && ! empty( $import->is_preview ) ) {
			return;
		}

		try {
			$result = self::linkImport( (int) $import_id );
		} catch ( \Throwable $e ) {
			MvdWaiCtrlLogger::writeFile(
				'ERROR',
				'VariationLinker: errore durante il ricollegamento',
				[
					'import_id' => (int) $import_id,
					'error'     => $e->getMessage(),
				]
			);
			return;
		}

		if ( ! $result['connected'] ) {
			return;
		}

		MvdWaiCtrlLogger::writeFile( 'INFO', 'VariationLinker: ricollegamento varianti WPML', self::summarize( $result ) );
	}

	/**
	 * Ricollega le varianti dell'import indicato alle varianti dell'import sorgente.
	 *
	 * @param int  $import_id ID dell'import nella lingua di traduzione (es. IT).
	 * @param bool $dry_run   Se true calcola il piano senza scrivere nulla.
	 * @return array{connected: bool, import_id: int, source_import_id: int, language: string, source_language: string, dry_run: bool, linked: int, meta_fixed: int, stray_rows: int, skipped: array<string,int>, report: array<int,array<string,mixed>>}
	 */
	public static function linkImport( int $import_id, bool $dry_run = false ): array {
		$result = [
			'connected'        => false,
			'import_id'        => $import_id,
			'source_import_id' => 0,
			'language'         => '',
			'source_language'  => '',
			'dry_run'          => $dry_run,
			'linked'           => 0,
			'meta_fixed'       => 0,
			'stray_rows'       => 0,
			'skipped'          => [],
			'report'           => [],
		];

		if ( ! class_exists( 'SitePress' ) ) {
			return $result;
		}

		$wpml = self::getWpmlSettings( $import_id );
		if ( null === $wpml ) {
			return $result;
		}

		$source = self::getWpmlSettings( $wpml['source_import_id'], false );
		if ( null === $source || '' === $source['language'] || $source['language'] === $wpml['language'] ) {
			return $result;
		}

		$result['connected']        = true;
		$result['source_import_id'] = $wpml['source_import_id'];
		$result['language']         = $wpml['language'];
		$result['source_language']  = $source['language'];

		$targets = self::loadImportVariations( $import_id );
		if ( empty( $targets ) ) {
			return $result;
		}
		$sources = self::loadImportVariations( $wpml['source_import_id'] );
		$members = self::loadTridMembers( array_merge( array_column( $targets, 'trid' ), array_column( $sources, 'trid' ) ) );

		$plan = self::buildLinkPlan( $targets, $sources, $members, $wpml['language'], $source['language'] );

		$result['skipped'] = array_map( 'count', $plan['skipped'] );
		$result['report']  = self::buildReport( $plan, $wpml['language'], $source['language'] );

		if ( $dry_run ) {
			$result['linked']     = count( $plan['link'] );
			$result['meta_fixed'] = count( $plan['fix_meta'] );
			$result['stray_rows'] = self::countStrayRows();
			return $result;
		}

		// Prima le righe spurie: con una riga post_product sulla stessa variante WPML rifiuterebbe il collegamento.
		$result['stray_rows'] = self::deleteStrayRows();

		$touched_parents = [];
		foreach ( $plan['link'] as $item ) {
			do_action(
				'wpml_set_element_language_details',
				[
					'element_id'           => $item['id'],
					'element_type'         => self::ELEMENT_TYPE,
					'trid'                 => $item['trid'],
					'language_code'        => $wpml['language'],
					'source_language_code' => $source['language'],
				]
			);
			update_post_meta( $item['id'], self::DUPLICATE_META, $item['source_id'] );
			$touched_parents[ $item['parent_id'] ] = true;
			++$result['linked'];
		}

		foreach ( $plan['fix_meta'] as $item ) {
			update_post_meta( $item['id'], self::DUPLICATE_META, $item['source_id'] );
			++$result['meta_fixed'];
		}

		if ( $result['linked'] > 0 || $result['stray_rows'] > 0 ) {
			do_action( 'wpml_cache_clear' );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			foreach ( array_keys( $touched_parents ) as $parent_id ) {
				wc_delete_product_transients( (int) $parent_id );
			}
		}

		return $result;
	}

	/**
	 * Calcola quali varianti collegare (funzione pura, senza WordPress).
	 *
	 * Una variante target viene collegata alla variante sorgente con la stessa unique_key
	 * dell'import solo se: lo SKU coincide, lo SKU non è duplicato nel proprio padre, i due
	 * padri sono già traduzioni l'uno dell'altro e la sorgente non ha già una traduzione
	 * nella lingua target. Tutto il resto finisce in `skipped`.
	 *
	 * @param array  $targets         Varianti dell'import target: liste di [ id, parent_id, unique_key, sku, trid, parent_trid, dup_of ].
	 * @param array  $sources         Varianti dell'import sorgente, stesso formato.
	 * @param array  $members         Mappa [ trid => [ lingua => [ id, ... ] ] ] delle varianti esistenti.
	 * @param string $language        Lingua dell'import target (es. 'it').
	 * @param string $source_language Lingua dell'import sorgente (es. 'en').
	 * @return array{link: array<int,array<string,int>>, fix_meta: array<int,array<string,int>>, skipped: array<string,array<int,array<string,mixed>>>}
	 */
	public static function buildLinkPlan( array $targets, array $sources, array $members, string $language, string $source_language ): array {
		$plan = [
			'link'     => [],
			'fix_meta' => [],
			'skipped'  => [],
		];

		$sources_by_key = [];
		foreach ( $sources as $source ) {
			$sources_by_key[ (string) $source['unique_key'] ][] = $source;
		}
		$targets_per_key = array_count_values( array_map( 'strval', array_column( $targets, 'unique_key' ) ) );

		$target_sku_count = self::countSkuPerParent( $targets );
		$source_sku_count = self::countSkuPerParent( $sources );

		// Sorgenti che hanno trovato (o hanno già) una traduzione: le altre vanno nel report come mancanti.
		$sources_covered = [];

		foreach ( $targets as $target ) {
			$target_id = (int) $target['id'];
			$trid      = (int) $target['trid'];

			// Già collegata a una variante esistente nella lingua sorgente: al massimo si corregge il meta WCML.
			$linked_source = $trid ? self::firstMember( $members, $trid, $source_language ) : 0;
			if ( $linked_source ) {
				$sources_covered[ $linked_source ] = true;
				if ( (int) $target['dup_of'] !== $linked_source ) {
					$plan['fix_meta'][] = [
						'id'        => $target_id,
						'source_id' => $linked_source,
					];
				}
				continue;
			}

			$key        = (string) $target['unique_key'];
			$candidates = $sources_by_key[ $key ] ?? [];

			if ( empty( $candidates ) ) {
				$plan['skipped'][ self::SKIP_NO_TWIN ][] = $target;
				continue;
			}
			if ( count( $candidates ) > 1 || ( $targets_per_key[ $key ] ?? 0 ) > 1 ) {
				$plan['skipped'][ self::SKIP_AMBIGUOUS_KEY ][] = $target;
				continue;
			}

			$source = $candidates[0];
			$sku    = (string) $target['sku'];

			if ( '' === $sku || (string) $source['sku'] !== $sku ) {
				$plan['skipped'][ self::SKIP_SKU_MISMATCH ][] = $target;
				continue;
			}
			if ( ( $target_sku_count[ (int) $target['parent_id'] ][ $sku ] ?? 0 ) > 1
				|| ( $source_sku_count[ (int) $source['parent_id'] ][ $sku ] ?? 0 ) > 1 ) {
				$plan['skipped'][ self::SKIP_DUPLICATE_SKU ][] = $target;
				continue;
			}
			if ( empty( $target['parent_trid'] ) || (int) $target['parent_trid'] !== (int) $source['parent_trid'] ) {
				$plan['skipped'][ self::SKIP_PARENT_NOT_LINKED ][] = $target;
				continue;
			}

			$source_trid = (int) $source['trid'];
			if ( ! $source_trid ) {
				$plan['skipped'][ self::SKIP_SOURCE_NO_LANGUAGE ][] = $target;
				continue;
			}
			if ( self::firstMember( $members, $source_trid, $language ) ) {
				$plan['skipped'][ self::SKIP_SOURCE_TRANSLATED ][] = $target;
				continue;
			}

			$plan['link'][] = [
				'id'        => $target_id,
				'source_id' => (int) $source['id'],
				'trid'      => $source_trid,
				'parent_id' => (int) $target['parent_id'],
			];
			$sources_covered[ (int) $source['id'] ] = true;
			// Segna la trid sorgente come occupata: una seconda variante target non può finirci dentro.
			$members[ $source_trid ][ $language ][] = $target_id;
		}

		foreach ( $sources as $source ) {
			$source_id = (int) $source['id'];
			if ( isset( $sources_covered[ $source_id ] ) ) {
				continue;
			}
			if ( (int) $source['trid'] && self::firstMember( $members, (int) $source['trid'], $language ) ) {
				continue;
			}
			$plan['skipped'][ self::SKIP_MISSING_TRANSLATION ][] = $source;
		}

		return $plan;
	}

	/**
	 * Comando WP-CLI: ricollega in WPML le varianti di un import.
	 *
	 * ## OPTIONS
	 *
	 * --import=<id>
	 * : ID dell'import nella lingua di traduzione (es. 2 per le varianti IT).
	 *
	 * [--dry-run]
	 * : Calcola cosa verrebbe fatto senza modificare nulla.
	 *
	 * [--report=<file>]
	 * : Percorso del file CSV con le varianti non collegate e il motivo.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvd-wai-ctrl link-variations --import=2 --dry-run --report=/tmp/varianti.csv
	 *
	 * @param array $args       Argomenti posizionali (non usati).
	 * @param array $assoc_args Argomenti con nome.
	 * @return void
	 */
	public static function cliLinkVariations( array $args, array $assoc_args ): void {
		$import_id = isset( $assoc_args['import'] ) ? absint( $assoc_args['import'] ) : 0;
		if ( ! $import_id ) {
			WP_CLI::error( 'Indicare --import=<id>.' );
		}
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$result = self::linkImport( $import_id, $dry_run );
		if ( ! $result['connected'] ) {
			WP_CLI::warning( sprintf( 'L\'import %d non è collegato in WPML a un import sorgente, oppure WPML non è attivo: nessuna azione.', $import_id ) );
			return;
		}

		foreach ( self::summarize( $result ) as $label => $value ) {
			WP_CLI::log( sprintf( '%-28s %s', $label, is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
		}

		if ( ! empty( $assoc_args['report'] ) ) {
			$written = self::writeReportCsv( (string) $assoc_args['report'], $result['report'] );
			if ( false === $written ) {
				WP_CLI::warning( 'Impossibile scrivere il report: ' . $assoc_args['report'] );
			} else {
				WP_CLI::log( sprintf( 'Report: %s (%d righe)', $assoc_args['report'], $written ) );
			}
		}

		WP_CLI::success( $dry_run ? 'Prova completata, nessuna modifica.' : 'Ricollegamento completato.' );
	}

	/**
	 * Legge le impostazioni WPML All Import di un import.
	 *
	 * @param int  $import_id        ID dell'import.
	 * @param bool $require_source   Se true restituisce null quando l'import non ha un import sorgente collegato.
	 * @return array{language: string, source_import_id: int}|null
	 */
	private static function getWpmlSettings( int $import_id, bool $require_source = true ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tabella di WP All Import, nessuna API pubblica equivalente.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT options FROM {$wpdb->prefix}pmxi_imports WHERE id = %d", $import_id ) );
		if ( null === $raw ) {
			return null;
		}

		$options = maybe_unserialize( $raw );
		$addon   = is_array( $options ) && isset( $options['wpml_addon'] ) && is_array( $options['wpml_addon'] ) ? $options['wpml_addon'] : [];

		$settings = [
			'language'         => isset( $addon['lng'] ) ? (string) $addon['lng'] : '',
			'source_import_id' => isset( $addon['import'] ) ? (int) $addon['import'] : 0,
		];

		if ( $require_source && ( '' === $settings['language'] || ! $settings['source_import_id'] || empty( $addon['matching_logic'] ) ) ) {
			return null;
		}

		return $settings;
	}

	/**
	 * Carica le varianti di un import con i dati necessari al piano di collegamento.
	 *
	 * @param int $import_id ID dell'import.
	 * @return array<int,array<string,mixed>> Una riga per variante.
	 */
	private static function loadImportVariations( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lettura in blocco su pmxi_posts/icl_translations, nessuna API WP/WC equivalente.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_parent AS parent_id, pp.unique_key, sku.meta_value AS sku,
					t.trid, tp.trid AS parent_trid, dup.meta_value AS dup_of
				FROM {$wpdb->prefix}pmxi_posts pp
				INNER JOIN {$wpdb->posts} p ON p.ID = pp.post_id AND p.post_type = 'product_variation'
				LEFT JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = %s
				LEFT JOIN {$wpdb->prefix}icl_translations tp ON tp.element_id = p.post_parent AND tp.element_type = %s
				LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				LEFT JOIN {$wpdb->postmeta} dup ON dup.post_id = p.ID AND dup.meta_key = %s
				WHERE pp.import_id = %d",
				self::ELEMENT_TYPE,
				self::PARENT_ELEMENT_TYPE,
				self::DUPLICATE_META,
				$import_id
			),
			ARRAY_A
		);

		// Una variante può comparire più volte in pmxi_posts: si tiene la prima.
		$unique = [];
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];
			if ( ! isset( $unique[ $id ] ) ) {
				$unique[ $id ] = $row;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Carica, per le trid indicate, le varianti esistenti raggruppate per lingua.
	 *
	 * Le righe WPML che puntano a post cancellati sono escluse: non contano come traduzione.
	 *
	 * @param array $trids Elenco di trid (anche con duplicati o vuoti).
	 * @return array<int,array<string,int[]>> Mappa [ trid => [ lingua => [ id, ... ] ] ].
	 */
	private static function loadTridMembers( array $trids ): array {
		global $wpdb;

		$trids = array_values( array_unique( array_filter( array_map( 'intval', $trids ) ) ) );
		if ( empty( $trids ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lettura in blocco su icl_translations.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.trid, t.language_code, t.element_id
				FROM {$wpdb->prefix}icl_translations t
				INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND p.post_type = 'product_variation'
				WHERE t.element_type = %s",
				self::ELEMENT_TYPE
			),
			ARRAY_A
		);

		$wanted  = array_flip( $trids );
		$members = [];
		foreach ( (array) $rows as $row ) {
			$trid = (int) $row['trid'];
			if ( isset( $wanted[ $trid ] ) ) {
				$members[ $trid ][ (string) $row['language_code'] ][] = (int) $row['element_id'];
			}
		}

		return $members;
	}

	/**
	 * Conta le righe WPML spurie di tipo post_product assegnate a varianti.
	 *
	 * @return int Numero di righe spurie.
	 */
	private static function countStrayRows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- diagnostica su icl_translations.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations t
				INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND p.post_type = 'product_variation'
				WHERE t.element_type = %s",
				self::PARENT_ELEMENT_TYPE
			)
		);
	}

	/**
	 * Elimina le righe WPML spurie di tipo post_product assegnate a varianti.
	 *
	 * Le crea WPML All Import quando collega una variante con il tipo dell'import.
	 *
	 * @return int Numero di righe eliminate.
	 */
	private static function deleteStrayRows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- pulizia mirata su icl_translations, nessuna API WPML per righe di tipo errato.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.translation_id FROM {$wpdb->prefix}icl_translations t
				INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND p.post_type = 'product_variation'
				WHERE t.element_type = %s",
				self::PARENT_ELEMENT_TYPE
			)
		);

		$deleted = 0;
		foreach ( (array) $ids as $translation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$deleted += (int) $wpdb->delete( $wpdb->prefix . 'icl_translations', [ 'translation_id' => (int) $translation_id ], [ '%d' ] );
		}

		return $deleted;
	}

	/**
	 * Conta le occorrenze di ogni SKU all'interno di ciascun padre.
	 *
	 * @param array $variations Varianti con chiavi parent_id e sku.
	 * @return array<int,array<string,int>> Mappa [ parent_id => [ sku => occorrenze ] ].
	 */
	private static function countSkuPerParent( array $variations ): array {
		$count = [];
		foreach ( $variations as $variation ) {
			$sku = (string) $variation['sku'];
			if ( '' === $sku ) {
				continue;
			}
			$parent_id                   = (int) $variation['parent_id'];
			$count[ $parent_id ][ $sku ] = ( $count[ $parent_id ][ $sku ] ?? 0 ) + 1;
		}
		return $count;
	}

	/**
	 * Restituisce il primo ID di variante di una lingua in una trid, o 0.
	 *
	 * @param array  $members  Mappa [ trid => [ lingua => [ id, ... ] ] ].
	 * @param int    $trid     Trid da cercare.
	 * @param string $language Codice lingua.
	 * @return int ID della variante, 0 se assente.
	 */
	private static function firstMember( array $members, int $trid, string $language ): int {
		return isset( $members[ $trid ][ $language ][0] ) ? (int) $members[ $trid ][ $language ][0] : 0;
	}

	/**
	 * Trasforma le esclusioni del piano in righe di report.
	 *
	 * @param array  $plan            Piano restituito da buildLinkPlan().
	 * @param string $language        Lingua delle varianti target.
	 * @param string $source_language Lingua delle varianti sorgente.
	 * @return array<int,array<string,mixed>> Righe con reason, language, id, parent_id, sku, unique_key.
	 */
	private static function buildReport( array $plan, string $language, string $source_language ): array {
		$report = [];
		foreach ( $plan['skipped'] as $reason => $items ) {
			$item_language = self::SKIP_MISSING_TRANSLATION === $reason ? $source_language : $language;
			foreach ( $items as $item ) {
				$report[] = [
					'reason'     => $reason,
					'language'   => $item_language,
					'id'         => (int) $item['id'],
					'parent_id'  => (int) $item['parent_id'],
					'sku'        => (string) $item['sku'],
					'unique_key' => (string) $item['unique_key'],
				];
			}
		}
		return $report;
	}

	/**
	 * Riassunto leggibile dell'esito, per il log e per WP-CLI.
	 *
	 * @param array $result Esito di linkImport().
	 * @return array<string,mixed>
	 */
	private static function summarize( array $result ): array {
		return [
			'import_id'        => $result['import_id'],
			'source_import_id' => $result['source_import_id'],
			'lingue'           => $result['language'] . ' <- ' . $result['source_language'],
			'prova'            => $result['dry_run'] ? 'si' : 'no',
			'collegate'        => $result['linked'],
			'meta_corretti'    => $result['meta_fixed'],
			'righe_spurie'     => $result['stray_rows'],
			'escluse'          => $result['skipped'],
		];
	}

	/**
	 * Scrive il report CSV delle varianti non collegate.
	 *
	 * @param string $path   Percorso del file.
	 * @param array  $report Righe del report.
	 * @return int|false     Numero di righe scritte, false in caso di errore.
	 */
	private static function writeReportCsv( string $path, array $report ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- file di report scritto da WP-CLI.
		$handle = fopen( $path, 'w' );
		if ( false === $handle ) {
			return false;
		}
		fputcsv( $handle, [ 'reason', 'language', 'id', 'parent_id', 'sku', 'unique_key' ] );
		foreach ( $report as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		return count( $report );
	}
}
