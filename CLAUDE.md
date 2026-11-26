# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scopo del plugin

Plugin WordPress che esegue **N importazioni WP All Import Pro in sequenza** (configurate in `MVD_WAI_CTRL_IDS`) con un singolo click dal pannello admin. Ogni chunk di ogni import è processato in una richiesta HTTP separata (loopback admin-ajax), con arresto al primo errore, barra di progresso live (polling AJAX ogni 3 sec) e log persistente su tabella custom + file rotante.

**Requisiti runtime:** WordPress >= 6.0, PHP >= 8.1, WP All Import Pro >= 5.0.

## Costanti di configurazione (file principale)

Definite in `mvd-wp-all-import-controller.php`:

| Costante | Valore/Scopo |
|---|---|
| `MVD_WAI_CTRL_IDS` | `[ 13, 2, 1, 14 ]` — ID delle importazioni nell'ordine di esecuzione |
| `MVD_WAI_CTRL_CRON_HOOK` | `mvd_wai_ctrl_run` — nome dell'evento cron (solo fallback) |
| `MVD_WAI_CTRL_STATE_OPTION` | `mvd_wai_ctrl_state` — chiave WP option per lo stato corrente (autoload off) |
| `MVD_WAI_CTRL_LOCK_KEY` | `mvd_wai_ctrl_running_lock` — prefisso del transient `{LOCK_KEY}_secret` (token del loopback). Dalla 1.6.0 l'esclusione reciproca usa il lock MySQL `GET_LOCK` (vedi Runner), non più un transient |
| `MVD_WAI_CTRL_CAPABILITY` | `manage_options` |
| `MVD_WAI_CTRL_WATCHDOG_THRESHOLD` | `180` — secondi senza attività, **a lock di esecuzione libero**, prima che il watchdog dichiari la catena interrotta |
| `MVD_WAI_CTRL_SLOW_THRESHOLD` | `600` — secondi senza record salvati, a blocco vivo, prima di segnalarlo come lento in UI (`stalled_sec`) |
| `MVD_WAI_CTRL_AUTO_RESUME_MAX` | `3` — riprese automatiche consecutive sulla stessa posizione (0 = disattivate). Sovrascrivibile in `wp-config.php` |
| `MVD_WAI_CTRL_AUTO_HALVE_RECORDS` | `true` — dimezza `records_per_request` dell'import a ogni ripresa automatica (come WP All Import nei lanci manuali) |
| `MVD_WAI_CTRL_LOG_DIR` | `mvd-wai-ctrl-logs` — sottocartella di `wp-content/uploads/` per i file log |
| `MVD_WAI_CTRL_LOG_RETENTION_DAYS` | `30` — giorni di conservazione dei file log |

## Architettura

### Classi e responsabilità

**`MvdWaiCtrlPlugin`** (`includes/class-plugin.php`)
Singleton che registra tutti gli hook WordPress. Handler AJAX registrati:
- `mvd_wai_ctrl_start` → `ajaxStart()`: verifica precondizioni (nonce, cap, PMXI attivo, lock di controllo, nessun blocco in esecuzione), crea il run_id nel log, imposta stato `running` con un nuovo `chain_id`, chiama `Runner::scheduleSelf()`.
- `mvd_wai_ctrl_status` → `ajaxStatus()`: restituisce stato corrente, storico run, `exec_alive`, `stalled_sec`, `can_resume`. Contiene il **watchdog**: marca il run `crashed` solo se il lock di esecuzione è libero (nessun processo vivo) **e** l'ultima attività (`State::lastActivity()` = max tra `updated_at` e heartbeat) è più vecchia di `WATCHDOG_THRESHOLD`.
- `mvd_wai_ctrl_reset` → `ajaxReset()`: sblocca manualmente uno stato bloccato (cancella transient e heartbeat, chiama `State::reset()`). Non può fermare un blocco vivo: quel blocco, al termine, si riconosce come orfano (chain_id azzerato) e non prosegue.
- `mvd_wai_ctrl_resume` → `ajaxResume()`: riprende da `current_index` senza resettare i counter PMXI. **Rifiutato (409) se il lock di esecuzione è occupato.** A lock libero chiama `Runner::forceStopPmxiCron()` per azzerare `triggered/processing/executing`, genera un nuovo `chain_id` (`markResumeRequested()`) e chiama `Runner::scheduleSelf()`.
- Start, resume e reset prendono prima il lock MySQL di controllo `{execLockName}_ctl` (`requireControlLock()`), così doppi click o più schede non eseguono l'azione due volte.
- `mvd_wai_ctrl_run_chain` (nopriv) → `ajaxRunChain()`: endpoint del loopback interno. Autentica il token monouso dal transient `{LOCK_KEY}_secret` (TTL 60 s), chiama `ignore_user_abort(true)` e poi `Runner::runStep( $chain_id )`.

**`MvdWaiCtrlRunner`** (`includes/class-runner.php`)
Processa **un singolo chunk** di un singolo import per invocazione. Logica di `runStep()`:
1. Acquisisce il lock MySQL `GET_LOCK( execLockName(), 0 )` (nome = hash di `DB_NAME` + prefisso). Se occupato esce (log `SKIP`); se GET_LOCK dà errore chiude il run in errore. Il lock è legato alla connessione: se il processo muore MySQL lo rilascia, quindi `isExecLockFree()` è la prova di vita del blocco. Registra uno shutdown handler PHP per intercettare fatal/OOM. Se il `chain_id` ricevuto non è quello dello stato, l'hop viene scartato (`isCurrentChain()`; uno stato senza chain_id, da versioni < 1.6.0, accetta qualsiasi hop).
2. Forza `pmxi_is_admin_dashboard_or_cron_import` → true (necessario in contesto cron dove `is_admin()=false`).
3. Se è il primo chunk di un import nuovo (`current_step_total_chunks == 0` e non resume): azzera i counter PMXI (`imported`, `created`, `updated`, `skipped`, `queue_chunk_number`, `processing`), fa cleanup history eccedenti `log_storage`, crea un record `PMXI_History_Record` (type `processing`).
4. Se resume (`state['resuming'] == true`): salta il reset counter, riusa o crea l'history record, poi imposta `resuming=false` (anche con resume a metà passo; `advanceToNextImport()` lo azzera comunque).
5. Chiama `$import->execute()` con output buffer (output HTML appendito al file log nativo WPAI). Durante il chunk un ticker su `pmxi_saved_post` aggiorna l'heartbeat (option `mvd_wai_ctrl_heartbeat`, ogni 20 s) e campiona PROCESSLIST (ogni 30 s). Dopo `execute()` verifica di possedere ancora lock e catena (`ownsExecLock()`), altrimenti l'hop è orfano: non scrive stato e non ri-schedula. Uno status PMXI ≠ 200 a import concluso diventa un passo `warning`; con chunk pendenti è un errore.
6. Se `queue_chunk_number > 0` → `updateChunk()` + `scheduleSelf()` (stesso import, chunk successivo).
7. Se `queue_chunk_number == 0` → `appendStep()` success + `advanceToNextImport()`:
   - Se ci sono altri import → `scheduleSelf()`.
   - Se è l'ultimo → `closeRun('success')` + `finishRun('completed')`.
8. In caso di eccezione → `appendStep()` error + `closeRun('error')` + `finishRun('error')`.
9. Il lock è rilasciato (`RELEASE_LOCK`) in un blocco post-try, sempre una sola volta e **prima** di `scheduleSelf()`.

`scheduleSelf( $chain_id )`: genera token monouso, tenta `wp_remote_post` non-bloccante (timeout 1 s) al loopback passando `chain_id`. Se fallisce (WP_Error, in pratica raro con cURL) → `wp_schedule_single_event( ..., [ $chain_id ] ) + spawn_cron` come fallback, loggato su file.

`forceStopPmxiCron(int $import_id)`: query diretta su `{prefix}pmxi_imports` per azzerare `triggered/processing/executing` prima di un resume.

**`MvdWaiCtrlWatchdog`** (`includes/class-watchdog.php`, dalla 1.7.0)
`check()`: se la catena è morta (stato `running`, lock di esecuzione libero, ultima attività oltre `WATCHDOG_THRESHOLD`) prende il lock di controllo, rilegge lo stato e la **riprende automaticamente** (`performResume()`), dimezzando i `records_per_request` dell'import (`Runner::halveRecordsPerRequest()`), fino a `MVD_WAI_CTRL_AUTO_RESUME_MAX` tentativi sulla stessa posizione `indice:record` (`State::nextAutoResumeAttempt()`); poi segna il run `crashed`. Gira dentro `ajaxStatus()` e da WP-Cron (`mvd_wai_ctrl_watchdog`, ogni minuto, pianificato da start/resume con `ensureScheduled()`, auto-rimosso a catena ferma). `performResume()` è condiviso con "Riprendi" manuale (che azzera il contatore con `State::resetAutoResume()`). Filtri: `mvd_wai_ctrl_auto_resume_max`, `mvd_wai_ctrl_auto_halve_records`.

**`MvdWaiCtrlVariationLinker`** (`includes/class-variation-linker.php`, dalla 1.8.0)
Ricollega in WPML le varianti importate. WPML All Import collega le varianti con il tipo `post_product` invece di `post_product_variation`, quindi le varianti tradotte restano "originali" scollegate.
- `onAfterImport()` è agganciato a `pmxi_after_xml_import` con priorità 50, dopo `mvd_variation_replace_run` del tema (10) e prima del restore (999). Esegue `linkImport()` sull'import appena concluso. Agisce solo se le opzioni `wpml_addon` hanno `lng`, `import` (import sorgente) e `matching_logic`.
- `buildLinkPlan()` è una funzione pura e testata. Abbina per `unique_key` di `pmxi_posts`, SKU uguale, SKU non duplicato nel padre, padri nella stessa trid `post_product` e sorgente senza traduzione nella lingua target.
- L'esecuzione:
  - elimina le righe spurie `post_product` sulle varianti;
  - chiama `wpml_set_element_language_details` con `post_product_variation`;
  - scrive `_wcml_duplicate_of_variation`.
- WP-CLI: `wp mvd-wai-ctrl link-variations --import=<id> [--dry-run] [--report=<file.csv>]`.

**`MvdWaiCtrlLogger`** (`includes/class-logger.php`)
Due sistemi di log paralleli:
- **Tabella DB** `{prefix}mvd_wai_ctrl_log`: `createRun()` → `run_id`, `appendStep()`, `closeRun()`, `markRunCrashed()`, `getRecentRuns($limit)`.
- **File rotante** in `wp-content/uploads/mvd-wai-ctrl-logs/YYYY-MM-DD.log`: `writeFile($level, $message, $context)`. La cartella è protetta con `.htaccess` deny-all. Pulizia file oltre `LOG_RETENTION_DAYS` giorni, eseguita opportunisticamente una volta al giorno via transient.

**`MvdWaiCtrlState`** (`includes/class-state.php`)
Wrapper sull'option `mvd_wai_ctrl_state`. Campi chiave dello stato:

| Campo | Tipo | Note |
|---|---|---|
| `status` | string | `idle`, `running`, `completed`, `error` |
| `run_id` | int | ID della riga header nel log |
| `current_index` | int | Indice 0-based dell'import corrente in `MVD_WAI_CTRL_IDS` |
| `current_chunk_done` | int | `queue_chunk_number` PMXI (record già processati) |
| `current_step_total_chunks` | int | `count` totale record PMXI (`0` = primo chunk non ancora eseguito) |
| `updated_at` | int | Unix timestamp UTC aggiornato ad ogni `save()`. Usato dal watchdog. Migrazione automatica da stringa MySQL (versioni < 1.5.1) in `State::get()`. |
| `resuming` | bool | `true` → il prossimo `runStep()` salta il reset counter PMXI |
| `crash_reason` | string\|null | Popolato da watchdog o shutdown handler |
| `chain_id` | string | Catena di hop valida, rigenerata a start/resume. `''` negli stati creati da versioni < 1.6.0 |
| `auto_resume_count` / `auto_resume_position` | int / string | Riprese automatiche consecutive e posizione `indice:record` a cui si riferiscono |
| `current_step_history_log_id` | int | ID del record `wp_pmxi_history` per lo step corrente |

**`MvdWaiCtrlAdminPage`** (`includes/class-admin-page.php`)
Render HTML del pannello admin: avvisi prerequisiti, pulsante avvio, barra di progresso, banner errore con pulsanti "Riprendi" / "Reset", storico ultimi 20 run con dettaglio step espandibile.

### Flusso di esecuzione (architettura 1-chunk-per-loopback)

```
Click "Avvia" (browser)
  → ajaxStart() — nonce + cap check, crea run_id, stato 'running'
     → Runner::scheduleSelf()
        → wp_remote_post loopback non-bloccante (timeout 0.01s)
           → wp_ajax_nopriv_mvd_wai_ctrl_run_chain → ajaxRunChain()
              → verifica token monouso (transient _secret, TTL 60s)
                 → Runner::runStep() — processa 1 chunk
                    → se chunk incompleto → scheduleSelf() [loop]
                    → se import completo + altri import → scheduleSelf() [avanza]
                    → se tutti completi → finishRun('completed')
        [fallback se loopback fallisce]
        → wp_schedule_single_event + spawn_cron → MVD_WAI_CTRL_CRON_HOOK
           → Runner::runStep()

Polling (admin.js ogni 3 sec)
  → ajaxStatus() → watchdog + State::get() + Logger::getRecentRuns(20)
     → aggiornamento barra progresso e tabella storico

Crash recovery
  → shutdown handler PHP → markRunCrashed() + writeFile('FATAL') + markCrashed()
  → watchdog in ajaxStatus() → markRunCrashed() + writeFile('ERROR') + markCrashed()
  → watchdog (ajaxStatus o WP-Cron ogni minuto) → Watchdog::check(): blocco morto → ripresa automatica (max 3 sulla stessa posizione, records_per_request dimezzati) → oltre il limite markCrashed()
  → utente clicca "Riprendi" (disponibile solo a lock libero) → ajaxResume() → Watchdog::performResume(): forceStopPmxiCron() + nuovo chain_id + scheduleSelf()
```

Il loopback è la via primaria perché garantisce `is_admin() = true`, necessario affinché WP All Import Pro carichi `PMXI_Import_Record` (classe admin-only). 1 richiesta HTTP per chunk risolve anche il bug del singleton `WPAI_WPML` che non resettava `$language_code` tra import consecutivi.

### Autoload

`MvdWaiCtrl{Suffix}` → `includes/class-{suffix-kebab}.php`. Definito in `mvd-wp-all-import-controller.php` tramite `spl_autoload_register`.

### Frontend

`assets/admin.js` — Vanilla JS, nessuna dipendenza jQuery. Usa `fetch()` per AJAX. `assets/admin.css` — stili pannello admin.

## Comandi di sviluppo

```bash
composer test               # PHPUnit (tests/Unit/)
composer phpstan            # PHPStan level 5 su includes/ e file principale
composer check              # PHPStan + PHPUnit in sequenza
composer build:readme       # Rigenera readme.txt da CHANGELOG.md (obbligatorio prima di taggare)
vendor/bin/phpunit --filter NomeTest   # singolo test
```

I test usano **Brain Monkey** (mock WP functions) e **Mockery**. Gli stub per `PMXI_Import_Record` e `$wpdb` sono in `tests/stubs/`. Il bootstrap dei test carica le classi direttamente senza il file principale del plugin.

## Release

1. Aggiorna `CHANGELOG.md`.
2. Bumpa `MVD_WAI_CTRL_VERSION` nel file principale (header + costante).
3. Esegui `composer build:readme` (rigenera `readme.txt`).
4. Commit di tutti e tre i file + tag `vX.Y.Z` + push tag.

Il workflow `.github/workflows/release.yml` verifica che `readme.txt` sia in sincrono con `CHANGELOG.md` (fallisce altrimenti), poi builda lo zip e crea la GitHub Release. `scripts/generate-readme.php` è lo script alla base di `composer build:readme`.

## Ambiente locale wp-env

Allineato a staging: WordPress 7.1.4, PHP 8.3, WooCommerce 11.2.1, WP All Import Pro 6.0.4 + add-on WooCommerce 4.0.8. Dati di test sintetici.

**File:**
- `.wp-env.json` (versionato): core, PHP, plugin pubblici, mapping di `dev/`.
- `.wp-env.override.json` (**non versionato**, locale): aggiunge WP All Import Pro e l'add-on WooCommerce dai percorsi della copia locale del sito (`C:/Users/Davide/Siti/www.endscuoio.com/public/plugins/...`). Sono plugin a licenza: non vanno nel repository.
- `dev/mu-plugins/mvd-wai-ctrl-dev.php`: mu-plugin di sviluppo. Riscrive i loopback verso `localhost:8888` sull'host interno del container (altrimenti `scheduleSelf()` e `spawn_cron()` falliscono in silenzio dentro Docker), simula record lenti e processi uccisi, aggiunge i comandi `wp mvd-dev`.
- Feed XML generati: `wp-content/uploads/mvd-dev-feeds/` nel container (WPAI accetta solo percorsi dentro uploads).
- `dev/`, `package.json`, `.wp-env*.json` sono esclusi dallo zip di release (`release.yml`).

**Primo avvio:**
```bash
npm install
npm run env:start          # http://localhost:8888/wp-admin  (admin / password)
```
Poi, una volta sola: in **All Import → Settings → Licenses** attivare la chiave di WP All Import Pro (senza licenza la Pro 6.x rifiuta di eseguire gli import).
```bash
npm run env:setup          # = wp mvd-dev setup: feed + import 13, 2, 1, 14 (40 prodotti, 4 per richiesta)
npx wp-env run cli wp mvd-dev setup --records=20 --per-request=2   # varianti
```

**Comandi `wp mvd-dev`** (via `npx wp-env run cli wp mvd-dev ...`):

| Comando | Effetto |
|---|---|
| `setup [--records=N] [--per-request=N] [--time-limit=S]` | Rigenera feed e import di test, imposta il limite per blocco di WPAI (default 20 s, come staging), spegne il simulatore |
| `start` | Avvia la catena come il pulsante "Avvia" (senza nonce) |
| `status [--lines=N]` | Stato del controller, lock, ultima attività, flag PMXI, coda del log su file |
| `poll` | Esegue una volta il polling del pannello (`ajaxStatus`, watchdog incluso) |
| `watch [--start] [--auto-resume] [--interval=S] [--duration=S]` | Polling in loop con un solo bootstrap (su Docker per Windows ogni comando WP-CLI impiega ~60 s solo per avviarsi) |
| `ajax start\|resume\|reset\|status [--wait-resumable]` | Chiama i veri handler AJAX con nonce e utente admin |
| `fake-crash` / `unfake-crash` | Porta lo stato in `error` (e ritorno) senza toccare il blocco in esecuzione: simula il falso crash del vecchio watchdog |
| `sim --record-delay=S` | Ogni record salvato attende S secondi (blocchi lunghi) |
| `sim --after-import-delay=S` | `pmxi_after_xml_import` attende S secondi (come la sostituzione varianti del tema) |
| `sim --kill-after-records=N` | Il processo dell'hop viene ucciso con SIGKILL dopo N record (monouso) |
| `sim --clear` | Spegne il simulatore |

**Scenari di verifica della 1.6.0:**
1. *Blocco lungo vivo*: `sim --record-delay=60`, avviare. Dopo 3 minuti il pannello non deve mostrare "Catena interrotta" né "Riprendi"; `status` mostra lock OCCUPATO. Oltre 10 minuti senza record compare l'avviso "blocco in esecuzione".
2. *Processo ucciso*: `sim --kill-after-records=2`, avviare. Il lock si libera subito; dopo 180 s il watchdog (pannello aperto) segna la catena interrotta; "Riprendi" riparte dal record dove si era fermato.
3. *Resume rifiutato*: con `--record-delay=60`, chiamare "Riprendi" o "Avvia" mentre un blocco gira → 409.
4. *Doppio click*: due "Riprendi" ravvicinati → una sola catena (log: un solo `runStep START` per volta).
5. *Fine import lenta*: `sim --after-import-delay=240` → nessun falso crash durante la fase finale.
6. *Esito WPAI ≠ 200*: svuotare un feed in `uploads/mvd-dev-feeds/` (nessun `<product>`) → passo `warning`, la catena prosegue.

Log: `wp-content/uploads/mvd-wai-ctrl-logs/` (righe START/END/SKIP con PID) e `npm run env:logs` (`debug.log`).

## Text domain e i18n

Text domain: `mvd-wai-ctrl`. Tutte le stringhe visibili usano `__()` / `esc_html__()` / `esc_html_e()` con questo domain.

## Database

Tabella: `{wp_prefix}mvd_wai_ctrl_log`

Colonne chiave: `run_id`, `is_run_header` (1 = riga di apertura run, 0 = step), `step_index`, `import_id`, `import_name`, `outcome` (`start`, `success`, `error`, `crashed`, `info`), `created`, `updated`, `skipped`, `duration_sec`, `message`, `started_at`, `created_at`.

Indici: PK `id`, `idx_run_id (run_id)`, `idx_run_header (is_run_header, id)`, `idx_created_at (created_at)`.

`createTable()` usa `dbDelta()` ed è invocata sia all'attivazione sia a `plugins_loaded` se `mvd_wai_ctrl_db_version` è diversa da `MVD_WAI_CTRL_DB_VERSION`. La tabella è rimossa all'uninstall tramite `uninstall.php`.
