# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.
Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/).
Versionamento basato su [SemVer](https://semver.org/lang/it/).

## [Unreleased]

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
