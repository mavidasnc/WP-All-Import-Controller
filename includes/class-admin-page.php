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

		// Recupera i nomi reali degli import se WP All Import è attivo.
		$import_names = [];
		if ( $pmxi_active ) {
			foreach ( $import_ids as $id ) {
				$rec = new PMXI_Import_Record();
				$rec->getById( $id );
				$import_names[ $id ] = $rec->isEmpty()
					? sprintf( __( '[Import ID %d — non trovato]', 'mvd-wai-ctrl' ), $id )
					: ( $rec->name ?: sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $id ) );
			}
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
							<span class="mvd-wai-ctrl-import-name">
								<?php
								if ( $pmxi_active && isset( $import_names[ $id ] ) ) {
									echo esc_html( $import_names[ $id ] );
								} else {
									echo esc_html( sprintf( __( 'Import ID %d', 'mvd-wai-ctrl' ), $id ) );
								}
								?>
							</span>
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

			<div id="mvd-wai-ctrl-progress" class="mvd-wai-ctrl-card" style="<?php echo $is_running ? '' : 'display:none;'; ?>">
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
				<pre id="mvd-wai-ctrl-last-msg" class="mvd-wai-ctrl-last-msg"><?php echo esc_html( $state['last_message'] ); ?></pre>
			</div>

			<div id="mvd-wai-ctrl-toast" class="mvd-wai-ctrl-toast" style="display:none;"></div>

			<div class="mvd-wai-ctrl-card">
				<h2><?php esc_html_e( 'Storico esecuzioni', 'mvd-wai-ctrl' ); ?></h2>
				<div id="mvd-wai-ctrl-log-table">
					<?php self::renderRunsTable( $runs ); ?>
				</div>
			</div>

			<?php self::renderSettingsForm(); ?>
		</div>
		<?php
	}

	/**
	 * Renderizza il form di impostazioni (token GitHub per gli aggiornamenti automatici).
	 *
	 * @return void
	 */
	private static function renderSettingsForm(): void {
		$token_saved    = (bool) get_option( MVD_WAI_CTRL_TOKEN_OPTION, '' );
		$token_constant = defined( 'MVD_WAI_CTRL_GH_TOKEN' ) && MVD_WAI_CTRL_GH_TOKEN;
		$updated        = isset( $_GET['settings-updated'] ) && '1' === $_GET['settings-updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="mvd-wai-ctrl-card">
			<h2><?php esc_html_e( 'Impostazioni aggiornamenti', 'mvd-wai-ctrl' ); ?></h2>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Impostazioni salvate.', 'mvd-wai-ctrl' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $token_constant ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Il token GitHub è definito tramite la costante MVD_WAI_CTRL_GH_TOKEN in wp-config.php e ha la precedenza sul valore salvato qui sotto.', 'mvd-wai-ctrl' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mvd_wai_ctrl_save_settings">
				<?php wp_nonce_field( 'mvd_wai_ctrl_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mvd_wai_ctrl_gh_token">
								<?php esc_html_e( 'Token GitHub (Personal Access Token)', 'mvd-wai-ctrl' ); ?>
							</label>
						</th>
						<td>
							<input
								type="password"
								id="mvd_wai_ctrl_gh_token"
								name="mvd_wai_ctrl_gh_token"
								class="regular-text"
								value=""
								placeholder="<?php echo $token_saved ? esc_attr__( '(token salvato — lascia vuoto per non modificarlo)', 'mvd-wai-ctrl' ) : 'ghp_…'; ?>"
								autocomplete="new-password"
							>
							<p class="description">
								<?php esc_html_e( 'Necessario per scaricare gli aggiornamenti dal repository GitHub privato. Genera un token con scope "repo" (read-only) dal tuo account GitHub.', 'mvd-wai-ctrl' ); ?>
								<?php if ( $token_saved ) : ?>
									<br><strong><?php esc_html_e( 'Un token è già salvato. Lascia vuoto per cancellarlo, oppure inserisci un nuovo valore per sostituirlo.', 'mvd-wai-ctrl' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Salva token', 'mvd-wai-ctrl' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Calcola la percentuale di avanzamento in base allo stato corrente.
	 *
	 * @param array<string, mixed> $state Stato corrente.
	 * @return int Percentuale 0-100.
	 */
	private static function progressPercent( array $state ): int {
		$total   = (int) ( $state['step_total']   ?? count( MVD_WAI_CTRL_IDS ) );
		$current = (int) ( $state['step_current'] ?? 0 );
		if ( 0 === $total ) {
			return 0;
		}
		return (int) round( ( $current / $total ) * 100 );
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
								<details>
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
