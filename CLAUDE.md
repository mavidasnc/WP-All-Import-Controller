# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scopo del plugin

Plugin WordPress che esegue **4 importazioni WP All Import Pro in sequenza** con un singolo click dal pannello admin. Le importazioni vengono lanciate tramite WordPress cron in background, con arresto al primo errore, barra di progresso live (polling AJAX ogni 3 sec) e log persistente su tabella custom.

**Requisiti runtime:** WordPress >= 6.0, PHP >= 8.1, WP All Import Pro >= 5.0.

## Costanti di configurazione (file principale)

Definite in `mvd-wp-all-import-controller.php`:

| Costante | Valore/Scopo |
|---|---|
| `MVD_WAI_CTRL_IDS` | `[ 13, 2, 1, 14 ]` — ID delle importazioni nell'ordine di esecuzione |
| `MVD_WAI_CTRL_CRON_HOOK` | `mvd_wai_ctrl_run` — nome dell'evento cron |
| `MVD_WAI_CTRL_STATE_OPTION` | `mvd_wai_ctrl_state` — chiave WP option per lo stato corrente |
| `MVD_WAI_CTRL_LOCK_KEY` | `mvd_wai_ctrl_running_lock` — chiave transient anti-doppio-avvio (TTL 600 sec) |
| `MVD_WAI_CTRL_CAPABILITY` | `manage_options` |

## Architettura

### Classi e responsabilità

**`MvdWaiCtrlPlugin`** (`includes/class-plugin.php`)
Punto di ingresso. Registra tutti gli hook WordPress: menu admin, enqueue assets, handler AJAX (`mvd_wai_ctrl_start` e `mvd_wai_ctrl_status`), hook cron. `ajaxStart()` verifica precondizioni (nonce, capability, WP All Import attivo, assenza di run in corso), crea la riga di log, imposta lo stato `running` e schedula il cron.

**`MvdWaiCtrlRunner`** (`includes/class-runner.php`)
Eseguito dall'hook cron. Acquisisce il lock transient, itera su `MVD_WAI_CTRL_IDS`, per ogni ID istanzia `PMXI_Import_Record` e chiama `execute()`. Al primo errore interrompe la catena. Aggiorna `MvdWaiCtrlState` a ogni passo e chiude il run nel logger.

**`MvdWaiCtrlLogger`** (`includes/class-logger.php`)
CRUD sulla tabella `{prefix}mvd_wai_ctrl_log`. Metodi chiave: `createRun()` → `run_id`, `appendStep()`, `closeRun()`, `getRecentRuns( $limit )`. La tabella è creata all'attivazione del plugin con `dbDelta()`.

**`MvdWaiCtrlState`** (`includes/class-state.php`)
Wrapper sull'option `mvd_wai_ctrl_state` (autoload `no`). Espone `isRunning()`, `startRun()`, `updateStep()`, `finishRun()`, `get()`, `save()`.

**`MvdWaiCtrlAdminPage`** (`includes/class-admin-page.php`)
Render HTML del pannello admin: avvisi prerequisiti, pulsante avvio, barra di progresso, storico ultimi 20 run con dettaglio step.

### Flusso di esecuzione

```
Click "Avvia" (browser)
  → ajaxStart() — nonce + cap check, crea run_id, stato 'running', schedula cron
     → spawn_cron() → MvdWaiCtrlRunner::runChain()
        → per ogni import_id: updateStep → PMXI execute → appendStep
        → closeRun + finishRun (completed | error)

Polling (admin.js ogni 3 sec)
  → ajaxStatus() → MvdWaiCtrlState::get() + Logger::getRecentRuns()
     → aggiornamento barra progresso e tabella storico
```

### Autoload

Mappa PSR-4-like semplice definita nel file principale: `MvdWaiCtrl{Suffix}` → `includes/class-{suffix-kebab}.php`.

### Frontend

`assets/admin.js` — Vanilla JS, nessuna dipendenza jQuery. Usa `fetch()` per AJAX. `assets/admin.css` — stili pannello admin.

## Comandi di sviluppo

Il progetto non ha `composer.json` né `package.json`. Non ci sono test automatizzati né PHPCS configurato.

Per avviare l'ambiente locale con `@wordpress/env` (Docker):

```bash
npx wp-env start    # dev su :8888
npx wp-env stop
npx wp-env run cli wp plugin list
```

Assicurarsi che nel `.wp-env.json` siano mappati anche WP All Import Pro e le sue dipendenze come plugin aggiuntivi.

## Text domain e i18n

Text domain: `mvd-wai-ctrl`. Tutte le stringhe visibili usano `__()` / `esc_html__()` / `esc_html_e()` con questo domain.

## Database

Tabella: `{wp_prefix}mvd_wai_ctrl_log`  
Indici: PK `id`, `run_id`, `created_at`.  
Rimossa al plugin uninstall tramite `uninstall.php`.
