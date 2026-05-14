# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.
Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/).
Versionamento basato su [SemVer](https://semver.org/lang/it/).

## [1.1.0] - 2026-05-14

### Changed
- **Architettura runner: da "4 import in 1 loopback" a "1 chunk per loopback".**  
  `MvdWaiCtrlRunner::runChain()` è stato sostituito da `runStep()` + `scheduleSelf()`. Ogni richiesta loopback admin-ajax (o hit cron di fallback) processa un solo chunk PMXI e poi ri-schedula sé stessa, finché l'import non è completo. Quando l'import è completo, avanza al successivo. Questo risolve due problemi strutturali.
- Lock anti-doppio-avvio ridotto da 600 s a 120 s (copre un singolo chunk PMXI ≈ 59 s con margine).
- `MvdWaiCtrlState` esteso con `current_index`, `current_chunk`, `current_total_chunks` per tracciare l'avanzamento intra-import. Aggiunto `advanceToNextImport()` e `updateChunk()`.
- La barra di progresso (PHP e JS) calcola ora una percentuale frazionaria basata sui chunk, risultando più fluida su import grandi.
- La logica di schedulazione loopback è estratta in `MvdWaiCtrlRunner::scheduleSelf()` (DRY): usata sia dall'avvio AJAX sia dall'auto-ri-schedulazione nel runner.

### Fixed
- **Import grandi troncati silenziosamente.** In precedenza, se `execute()` superava il `cron_processing_time_limit` di PMXI (≈ 59 s), l'import veniva considerato completato anche se `queue_chunk_number > 0`. Ora il runner ri-invoca `execute()` finché `queue_chunk_number == 0` (segnale di completamento PMXI).
- **Bug WPML language_code sticky in catena.** Il singleton `WPAI_WPML` (add-on WPML All Import v2.3.2) non resettava `$language_code` tra import consecutivi nella stessa richiesta PHP. Con 1 richiesta loopback per import, il singleton si ricrea ad ogni richiesta HTTP e il bug non si manifesta.
- **Fallback `wp_schedule_single_event` non funzionante.** In contesto WP-Cron `is_admin()=false` e `PMXI_Plugin::isAdminDashboardOrCronImport()` restituiva false, impedendo il caricamento di `PMXI_Import_Record`. Il runner ora aggiunge `add_filter('pmxi_is_admin_dashboard_or_cron_import', '__return_true')` prima di toccare PMXI, rendendo il fallback cron effettivamente operativo.

## [1.0.1] - 2026-05-14

### Added
- Aggiornamenti automatici direttamente dalla schermata Plugin di WordPress tramite **Plugin Update Checker** (YahnisElsts v5). Il plugin controlla le GitHub Releases del repository privato `mavidasnc/WP-All-Import-Controller`.
- Campo **Token GitHub** nella pagina admin del plugin (sezione "Impostazioni aggiornamenti"): permette di salvare il Personal Access Token direttamente dall'interfaccia WordPress senza toccare `wp-config.php`. Il token è salvato come WP option (autoload off) e rimosso alla disinstallazione del plugin.
- Supporto opzionale alla costante `MVD_WAI_CTRL_GH_TOKEN` in `wp-config.php` (ha precedenza sull'option salvata, utile in ambienti dev).
- Workflow GitHub Actions (`.github/workflows/release.yml`) che a ogni tag `v*` builda uno `.zip` pulito (solo dipendenze di produzione, senza `tests/`, `vendor/bin`, file di configurazione dev) e lo allega come asset alla GitHub Release.

## [1.0.0] - 2026-05-14

### Added
- Pannello admin dedicato con menu top-level "Import Sequenziale".
- Esecuzione sequenziale di 4 importazioni WP All Import Pro con un solo click.
- Esecuzione asincrona in background tramite `wp_schedule_single_event` (no timeout browser).
- Stop automatico alla prima importazione fallita con messaggio di errore.
- Barra di progresso con polling AJAX ogni 3 secondi.
- Toast di notifica a completamento/errore.
- Log persistente in tabella custom `{prefix}mvd_wai_ctrl_log` con storico ultime 20 esecuzioni.
- Dettaglio per-step: record creati, aggiornati, saltati, durata, messaggi di log.
- Lock anti-doppio-avvio tramite transient WordPress.
- Routine di disinstallazione (`uninstall.php`) che rimuove tabella e option.
- Compatibilità WordPress >= 6.0, PHP >= 8.1, WP All Import Pro >= 5.0.
