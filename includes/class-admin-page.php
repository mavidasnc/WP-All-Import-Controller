<?php
/**
 * Render del pannello admin del plugin.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe che genera l'HTML del pannello amministrativo.
 */
class MvdWaiCtrlAdminPage {

	/**
	 * Renderizza la pagina admin completa.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( MVD_WAI_CTRL_CAPABILITY ) ) {
			wp_die( esc_html__( 'Accesso non autorizzato.', 'mvd-wai-ctrl' ) );
		}

		$state        = MvdWaiCtrlState::get();
		$is_running   = 'running' === $state['status'];
		$pmxi_active  = class_exists( 'PMXI_Import_Record' );
		$import_ids   = MVD_WAI_CTRL_IDS;
		$runs         = MvdWaiCtrlLogger::getRecentRuns( 20 );

		// Recupera i nomi reali degli import tramite l'helper del Runner.
		$import_names = [];
		foreach ( $import_ids as $id ) {
			$import_names[ $id ] = $pmxi_active
				? MvdWaiCtrlRunner::getImportDisplayName( $id )
				: sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $id );
		}
		?>
		<div class="wrap mvd-wai-ctrl-wrap">
			<h1><?php esc_html_e( 'Importazioni Sequenziali', 'mvd-wai-ctrl' ); ?></h1>

			<?php if ( ! $pmxi_active ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'WP All Import Pro non è attivo.', 'mvd-wai-ctrl' ); ?></strong>
					<?php esc_html_e( 'Attivare il plugin WP All Import Pro per utilizzare questa funzionalità.', 'mvd-wai-ctrl' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="mvd-wai-ctrl-card">
				<h2><?php esc_html_e( 'Sequenza configurata', 'mvd-wai-ctrl' ); ?></h2>
				<ol class="mvd-wai-ctrl-import-list">
					<?php foreach ( $import_ids as $step => $id ) : ?>
						<li>
							<span class="mvd-wai-ctrl-step-num"><?php echo esc_html( (string) ( $step + 1 ) ); ?></span>
							<span class="mvd-wai-ctrl-import-name"><?php echo esc_html( $import_names[ $id ] ); ?></span>
							<code class="mvd-wai-ctrl-import-id">ID: <?php echo esc_html( (string) $id ); ?></code>
						</li>
					<?php endforeach; ?>
				</ol>

				<div class="mvd-wai-ctrl-actions">
					<button
						id="mvd-wai-ctrl-start-btn"
						class="button button-primary button-hero"
						<?php disabled( ! $pmxi_active || $is_running ); ?>
					>
						<?php if ( $is_running ) : ?>
							<?php esc_html_e( 'Esecuzione in corso...', 'mvd-wai-ctrl' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Avvia importazione sequenziale', 'mvd-wai-ctrl' ); ?>
						<?php endif; ?>
					</button>

					<?php if ( $is_running ) : ?>
						<button
							id="mvd-wai-ctrl-reset-btn"
							class="button button-secondary"
							style="margin-left:12px; color:#b32d2e;"
						>
							<?php esc_html_e( 'Sblocca (reset stato)', 'mvd-wai-ctrl' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<div
				id="mvd-wai-ctrl-error-banner"
				class="mvd-wai-ctrl-error-banner"
				<?php if ( 'error' !== $state['status'] ) : ?>style="display:none;"<?php endif; ?>
			>
				<strong class="mvd-wai-ctrl-error-title"><?php esc_html_e( 'Importazione interrotta', 'mvd-wai-ctrl' ); ?></strong>
				<p class="mvd-wai-ctrl-error-message"><?php echo esc_html( (string) ( $state['crash_reason'] ?? $state['last_message'] ?? '' ) ); ?></p>
				<p class="mvd-wai-ctrl-error-step">
					<?php if ( 'error' === $state['status'] ) : ?>
						<?php
						$step_num = (int) $state['current_index'] + 1;
						printf(
							/* translators: 1: numero passo corrente, 2: totale passi */
							esc_html__( 'Interrotto al passo %1$d di %2$d.', 'mvd-wai-ctrl' ),
							esc_html( (string) $step_num ),
							esc_html( (string) count( $import_ids ) )
						);
						?>
					<?php endif; ?>
				</p>
				<div class="mvd-wai-ctrl-error-actions">
					<button type="button" class="button button-primary" id="mvd-wai-ctrl-resume-btn">
						<?php esc_html_e( 'Riprendi', 'mvd-wai-ctrl' ); ?>
					</button>
					<button type="button" class="button" id="mvd-wai-ctrl-reset-banner-btn">
						<?php esc_html_e( 'Reset', 'mvd-wai-ctrl' ); ?>
					</button>
				</div>
			</div>

			<div id="mvd-wai-ctrl-progress" class="mvd-wai-ctrl-card" style="<?php echo ( $is_running || 'error' === $state['status'] ) ? '' : 'display:none;'; ?>">
				<h2><?php esc_html_e( 'Progresso', 'mvd-wai-ctrl' ); ?></h2>
				<div class="mvd-wai-ctrl-progress-bar-wrap">
					<div
						id="mvd-wai-ctrl-progress-bar"
						class="mvd-wai-ctrl-progress-bar"
						style="width: <?php echo esc_attr( (string) self::progressPercent( $state ) ); ?>%"
					></div>
				</div>
				<p id="mvd-wai-ctrl-progress-label" class="mvd-wai-ctrl-progress-label">
					<?php echo esc_html( $state['step_label'] ?: __( 'In attesa di avvio...', 'mvd-wai-ctrl' ) ); ?>
				</p>
				<?php
				$chunk_info = '';
				$tot_ch     = (int) ( $state['current_step_total_chunks'] ?? 0 );
				$done_ch    = (int) ( $state['current_chunk_done']         ?? 0 );
				if ( $tot_ch > 0 ) {
					$chunk_info = sprintf(
						/* translators: 1: chunk completati, 2: totale chunk */
						__( 'Chunk %1$d / %2$d', 'mvd-wai-ctrl' ),
						$done_ch,
						$tot_ch
					);
				}
				?>
				<p id="mvd-wai-ctrl-chunk-info" class="mvd-wai-ctrl-chunk-info"><?php echo esc_html( $chunk_info ); ?></p>
				<pre id="mvd-wai-ctrl-last-msg" class="mvd-wai-ctrl-last-msg"><?php echo esc_html( $state['last_message'] ); ?></pre>
			</div>

			<div id="mvd-wai-ctrl-toast" class="mvd-wai-ctrl-toast" style="display:none;"></div>

			<div class="mvd-wai-ctrl-card">
				<h2><?php esc_html_e( 'Storico esecuzioni', 'mvd-wai-ctrl' ); ?></h2>
				<div id="mvd-wai-ctrl-log-table">
					<?php self::renderRunsTable( $runs ); ?>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Calcola la percentuale di avanzamento in base allo stato corrente.
	 *
	 * Quando un import è in corso e il totale chunk è noto, calcola una percentuale
	 * frazionaria intra-import (es. 37.5% a metà del secondo import su 4).
	 *
	 * @param array<string, mixed> $state Stato corrente.
	 * @return int Percentuale 0-100.
	 */
	private static function progressPercent( array $state ): int {
		$total      = (int) ( $state['step_total']                 ?? count( MVD_WAI_CTRL_IDS ) );
		$current    = (int) ( $state['step_current']               ?? 0 );
		$done       = (int) ( $state['current_chunk_done']         ?? 0 );
		$tot_chunks = (int) ( $state['current_step_total_chunks']  ?? 0 );

		if ( 0 === $total ) {
			return 0;
		}

		// Aggiunge la frazione chunk-done/chunk-totali al progresso dello step corrente.
		// step_current è 1-based: il numero di step completati è (current - 1) + frazione intra-step.
		$frac = ( $tot_chunks > 0 ) ? min( 1.0, $done / $tot_chunks ) : 0.0;
		$pct  = ( ( $current - 1 ) + $frac ) / $total * 100;

		return (int) round( max( 0, min( 100, $pct ) ) );
	}

	/**
	 * Renderizza la tabella HTML dello storico esecuzioni.
	 *
	 * @param array<int, array<string, mixed>> $runs Array di run da MvdWaiCtrlLogger::getRecentRuns().
	 * @return void
	 */
	public static function renderRunsTable( array $runs ): void {
		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'Nessuna esecuzione registrata.', 'mvd-wai-ctrl' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped mvd-wai-ctrl-runs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Run ID', 'mvd-wai-ctrl' ); ?></th>
					<th><?php esc_html_e( 'Avviato il', 'mvd-wai-ctrl' ); ?></th>
					<th><?php esc_html_e( 'Esito', 'mvd-wai-ctrl' ); ?></th>
					<th><?php esc_html_e( 'Passi', 'mvd-wai-ctrl' ); ?></th>
					<th><?php esc_html_e( 'Dettaglio', 'mvd-wai-ctrl' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $runs as $entry ) :
					$run   = $entry['run'];
					$steps = $entry['steps'];
					$outcome_class = 'start' === $run['outcome'] ? 'mvd-outcome-running' : 'mvd-outcome-' . esc_attr( $run['outcome'] );
					?>
					<tr>
						<td><?php echo esc_html( (string) $run['run_id'] ); ?></td>
						<td><?php echo esc_html( $run['created_at'] ); ?></td>
						<td><span class="mvd-wai-ctrl-badge <?php echo esc_attr( $outcome_class ); ?>"><?php echo esc_html( $run['outcome'] ); ?></span></td>
						<td><?php echo esc_html( (string) count( $steps ) ); ?>/<?php echo esc_html( (string) count( MVD_WAI_CTRL_IDS ) ); ?></td>
						<td>
							<?php if ( ! empty( $steps ) ) : ?>
								<details data-run-id="<?php echo esc_attr( (string) $run['run_id'] ); ?>">
									<summary><?php esc_html_e( 'Visualizza', 'mvd-wai-ctrl' ); ?></summary>
									<table class="mvd-wai-ctrl-steps-table">
										<tr>
											<th><?php esc_html_e( 'Passo', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Import ID', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Esito', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Creati', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Aggiornati', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Saltati', 'mvd-wai-ctrl' ); ?></th>
											<th><?php esc_html_e( 'Durata (s)', 'mvd-wai-ctrl' ); ?></th>
										</tr>
										<?php foreach ( $steps as $step ) : ?>
											<tr>
												<td><?php echo esc_html( (string) ( (int) $step['step_index'] + 1 ) ); ?></td>
												<td><?php echo esc_html( (string) $step['import_id'] ); ?></td>
												<td><span class="mvd-wai-ctrl-badge mvd-outcome-<?php echo esc_attr( $step['outcome'] ); ?>"><?php echo esc_html( $step['outcome'] ); ?></span></td>
												<td><?php echo esc_html( (string) $step['created'] ); ?></td>
												<td><?php echo esc_html( (string) $step['updated'] ); ?></td>
												<td><?php echo esc_html( (string) $step['skipped'] ); ?></td>
												<td><?php echo esc_html( (string) $step['duration_sec'] ); ?></td>
											</tr>
											<?php if ( ! empty( $step['message'] ) ) : ?>
												<tr class="mvd-wai-ctrl-step-msg">
													<td colspan="7"><pre><?php echo esc_html( $step['message'] ); ?></pre></td>
												</tr>
											<?php endif; ?>
										<?php endforeach; ?>
									</table>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
