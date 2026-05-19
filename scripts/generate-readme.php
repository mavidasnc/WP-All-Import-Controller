<?php
/**
 * Genera readme.txt in formato WordPress.org a partire da CHANGELOG.md.
 *
 * Utilizzo:
 *   php scripts/generate-readme.php                       # scrive readme.txt nella root
 *   php scripts/generate-readme.php --output=/tmp/r.txt   # output alternativo (per verifica CI)
 */

declare( strict_types=1 );

// ── Configurazione ─────────────────────────────────────────────────────────────

/** Versione di WordPress con cui il plugin è stato testato. */
const TESTED_UP_TO = '6.9.4';

const PLUGIN_SLUG = 'mvd-wp-all-import-controller';

// ── Percorsi ───────────────────────────────────────────────────────────────────

$root_dir      = dirname( __DIR__ );
$plugin_file   = $root_dir . '/mvd-wp-all-import-controller.php';
$changelog_file = $root_dir . '/CHANGELOG.md';

// Gestione --output=PATH
$output_file = $root_dir . '/readme.txt';
foreach ( $argv as $arg ) {
	if ( str_starts_with( $arg, '--output=' ) ) {
		$output_file = substr( $arg, strlen( '--output=' ) );
	}
}

// ── Lettura file sorgente ──────────────────────────────────────────────────────

$plugin_content   = file_get_contents( $plugin_file );
$changelog_content = file_get_contents( $changelog_file );

if ( false === $plugin_content || false === $changelog_content ) {
	fwrite( STDERR, "Errore: impossibile leggere i file sorgente.\n" );
	exit( 1 );
}

// Normalizza a LF per garantire output identico su Windows (CRLF checkout) e Linux (LF checkout).
$changelog_content = str_replace( "\r\n", "\n", $changelog_content );
$plugin_content    = str_replace( "\r\n", "\n", $plugin_content );

// ── Estrazione header dal file principale del plugin ──────────────────────────

/**
 * Estrae un campo dall'header di un plugin WordPress.
 *
 * @param string $content  Contenuto del file PHP del plugin.
 * @param string $field    Nome del campo (es. 'Version', 'Requires at least').
 * @return string          Valore del campo, oppure stringa vuota.
 */
function extract_plugin_header( string $content, string $field ): string {
	if ( preg_match( '/^[ \t\/*#]*' . preg_quote( $field, '/' ) . ':[ \t]*(.+)$/mi', $content, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

$version      = extract_plugin_header( $plugin_content, 'Version' );
$requires_at  = extract_plugin_header( $plugin_content, 'Requires at least' );
$requires_php = extract_plugin_header( $plugin_content, 'Requires PHP' );
$description  = extract_plugin_header( $plugin_content, 'Description' );
$author       = extract_plugin_header( $plugin_content, 'Author' );

if ( '' === $version ) {
	fwrite( STDERR, "Errore: impossibile estrarre Version dal file principale del plugin.\n" );
	exit( 1 );
}

// ── Conversione CHANGELOG.md → sezione WP.org ─────────────────────────────────

/**
 * Converte il contenuto di CHANGELOG.md nel formato sezione WP.org readme.
 *
 * @param string $markdown  Contenuto di CHANGELOG.md.
 * @return string           Sezione changelog in formato WP.org.
 */
function convert_changelog( string $markdown ): string {
	$lines  = explode( "\n", $markdown );
	$output = [];
	$in_version = false;

	foreach ( $lines as $line ) {
		// Intestazione versione: ## [1.5.1] - 2026-05-18
		if ( preg_match( '/^## \[([^\]]+)\](?: - (.+))?$/', $line, $m ) ) {
			$ver  = $m[1];
			$date = isset( $m[2] ) ? ' - ' . trim( $m[2] ) : '';

			// Salta righe [Unreleased]
			if ( 'Unreleased' === $ver ) {
				$in_version = false;
				continue;
			}

			if ( $in_version ) {
				$output[] = '';
			}
			$output[]   = "= {$ver}{$date} =";
			$in_version = true;
			continue;
		}

		// Salta preambolo Keep a Changelog (righe prima della prima versione)
		if ( ! $in_version ) {
			continue;
		}

		// Sottosezione: ### Added / Changed / Fixed / Removed / Security / Deprecated
		if ( preg_match( '/^### (.+)$/', $line, $m ) ) {
			$output[] = '**' . $m[1] . ':**';
			continue;
		}

		// Bullet list: - testo  →  * testo
		if ( preg_match( '/^- (.+)$/', $line, $m ) ) {
			$output[] = '* ' . $m[1];
			continue;
		}

		// Riga vuota
		$output[] = $line;
	}

	return implode( "\n", $output );
}

$changelog_section = convert_changelog( $changelog_content );

// ── Composizione readme.txt ────────────────────────────────────────────────────

$readme = <<<README
=== MVD WP All Import Controller ===
Contributors: mavidasnc
Tags: wp-all-import, import, automation, woocommerce, cron
Requires at least: {$requires_at}
Tested up to: TESTED_UP_TO_PLACEHOLDER
Stable tag: {$version}
Requires PHP: {$requires_php}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

{$description}

== Description ==

{$description}

Esegue le importazioni WP All Import Pro in sequenza con un solo click dal pannello admin, con arresto al primo errore, barra di progresso live e log persistente.

**Requisiti runtime:** WordPress >= {$requires_at}, PHP >= {$requires_php}, WP All Import Pro >= 5.0.

== Changelog ==

{$changelog_section}
README;

// Sostituisce il placeholder con la costante (evita interpolazione in heredoc di costanti PHP)
$readme = str_replace( 'TESTED_UP_TO_PLACEHOLDER', TESTED_UP_TO, $readme );

// Normalizza line endings (LF)
$readme = str_replace( "\r\n", "\n", $readme );

// ── Scrittura output ───────────────────────────────────────────────────────────

if ( false === file_put_contents( $output_file, $readme ) ) {
	fwrite( STDERR, "Errore: impossibile scrivere {$output_file}.\n" );
	exit( 1 );
}

echo "readme.txt generato con successo ({$output_file}) — versione {$version}, tested up to " . TESTED_UP_TO . "\n";
exit( 0 );
