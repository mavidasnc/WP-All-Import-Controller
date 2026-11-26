# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.
Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/).
Versionamento basato su [SemVer](https://semver.org/lang/it/).

## [Unreleased]

## [1.8.1] - 2026-11-26

### Changed
- **Import con file senza record: ora è un passo riuscito, non un avviso.** WP All Import restituisce status 500 con "#ID No matching elements found for Root element and XPath expression specified" quando il file non contiene elementi per l'import, ad esempio un feed vuoto o nessun prodotto semplice nel feed. Il passo veniva segnato `warning` con "WP All Import non ha elaborato record: status 500…" e sembrava un errore. Ora è `success` con il messaggio "Nessun record da importare". Gli altri status diversi da 200 restano avvisi. Nuovo metodo `MvdWaiCtrlRunner::isEmptyFeedResult()`.

## [1.8.0] - 2026-11-26

### Fixed
- **Varianti EN/IT scollegate in WPML dopo l'import.** WPML All Import collega ogni record alla sua traduzione con il tipo dell'import (`post_product`) anche quando il record è una variante. WPML scrive allora una riga spuria `post_product` nella trid della variante originale, e la riga reale `post_product_variation` resta da sola: per WPML la variante IT diventa un contenuto "originale" in italiano. Su staging4 erano 4.322 coppie. Conseguenze: il selettore di lingua non portava alla variante corrispondente e WCML non sincronizzava le varianti tra le lingue.

### Added
- Nuova classe `MvdWaiCtrlVariationLinker`. Su `pmxi_after_xml_import` (priorità 50, dopo la sostituzione varianti del tema) ricollega le varianti dell'import appena concluso alle varianti dell'import sorgente indicato nelle impostazioni WPML All Import. Le regole di abbinamento sono:
  - stessa `unique_key` in `pmxi_posts`;
  - stesso SKU;
  - padri già tradotti l'uno dell'altro;
  - nessuna traduzione già presente sulla variante sorgente.

  La classe aggiorna anche il meta WCML `_wcml_duplicate_of_variation` e rimuove le righe spurie `post_product` sulle varianti. Agisce solo sugli import collegati a un import sorgente; ignora le anteprime.
- Comando WP-CLI `wp mvd-wai-ctrl link-variations --import=<id> [--dry-run] [--report=<file.csv>]` per la riparazione una tantum. Il report CSV elenca le varianti non collegate con il motivo: `no_twin`, `sku_mismatch`, `duplicate_sku`, `parent_not_linked`, `source_already_translated`, `missing_translation` e altri.

## [1.7.0] - 2026-11-25

### Added
- **Ripresa automatica dei blocchi morti.** Quando un blocco viene terminato (su SiteGround le richieste HTTP vengono chiuse a ~120 s senza errori PHP), il watchdog rileva la catena ferma — lock di esecuzione libero e nessuna attività da `MVD_WAI_CTRL_WATCHDOG_THRESHOLD` secondi — e la riprende da solo, senza click su "Riprendi". Massimo `MVD_WAI_CTRL_AUTO_RESUME_MAX` (3) tentativi consecutivi sulla stessa posizione; se la catena avanza nel frattempo il conteggio riparte. Esauriti i tentativi il run va in errore e resta disponibile la ripresa manuale.
- **Dimezzamento dei record per richiesta** dell'import a ogni ripresa automatica (minimo 1), salvato nelle opzioni dell'import: è lo stesso comportamento di WP All Import nei lanci manuali dal suo pannello (`failures` → `records_per_request` dimezzato). Disattivabile con `MVD_WAI_CTRL_AUTO_HALVE_RECORDS` o con il filtro `mvd_wai_ctrl_auto_halve_records`.
- **Watchdog anche da WP-Cron**: evento `mvd_wai_ctrl_watchdog` ogni minuto durante un run (si cancella da solo a catena ferma), così la catena si ripara anche senza la pagina admin aperta.
- Nuova classe `MvdWaiCtrlWatchdog` (controllo, ripresa automatica, ripresa condivisa con "Riprendi"); filtri `mvd_wai_ctrl_auto_resume_max` e `mvd_wai_ctrl_auto_halve_records`; costanti `MVD_WAI_CTRL_AUTO_RESUME_MAX` e `MVD_WAI_CTRL_AUTO_HALVE_RECORDS`, sovrascrivibili in `wp-config.php`.

### Changed
- `ajaxStatus()` e `ajaxResume()` usano la logica comune di `MvdWaiCtrlWatchdog`; la ripresa manuale azzera il contatore delle riprese automatiche.
- La disattivazione e la disinstallazione del plugin rimuovono anche l'evento WP-Cron del watchdog.

## [1.6.0] - 2026-11-25

### Changed
- **Esclusione reciproca con lock MySQL `GET_LOCK()`** al posto del transient con TTL di 120 s. Un solo blocco alla volta può eseguire `runStep()`; il lock appartiene alla connessione MySQL del processo, quindi viene rilasciato automaticamente se il processo PHP muore. Il transient poteva scadere durante blocchi lunghi (≈ 110 s con 4 record a ~28 s l'uno), non era atomico e veniva cancellato anche da chi non lo possedeva. Nome del lock: hash di `DB_NAME` + prefisso tabelle, per non collidere con altri siti sullo stesso server MySQL. Se `GET_LOCK` dà errore il run si chiude in errore invece di proseguire senza protezione.
- **Watchdog**: dichiara la catena interrotta solo se il lock di esecuzione è libero (nessun processo vivo) **e** non c'è attività da più di `MVD_WAI_CTRL_WATCHDOG_THRESHOLD` secondi. Un blocco lungo ma vivo non viene più segnato "crashed". Se il blocco è vivo ma non salva record da più di `MVD_WAI_CTRL_SLOW_THRESHOLD` (600 s) la UI lo segnala come lento, senza proporre azioni.
- **"Riprendi" sicuro**: rifiutato (409) finché il blocco interrotto detiene il lock; `forceStopPmxiCron()` viene eseguito solo a lock libero. Rimossa l'attesa fissa di 3 secondi.
- **Start, Riprendi e Reset serializzati** con un secondo lock MySQL di controllo: doppi click o più schede aperte non eseguono l'azione due volte. Start rifiutato se un blocco di un run precedente è ancora in esecuzione.
- `scheduleSelf()` usa un timeout esplicito di 1 s (Requests lo imponeva comunque come minimo con cURL).

### Added
- **`chain_id`** nello stato, rigenerato a ogni avvio e ripresa, passato nel loopback e nell'evento cron di fallback. Gli hop di una catena superata vengono scartati; un blocco che a fine esecuzione non possiede più lock o catena (riconnessione MySQL, Reset nel frattempo) non scrive lo stato e non ri-schedula. Gli stati creati da versioni precedenti (senza `chain_id`) accettano qualsiasi hop, così l'aggiornamento non interrompe un run.
- **Heartbeat durante il chunk**: option `mvd_wai_ctrl_heartbeat` aggiornata su `pmxi_saved_post` al massimo ogni 20 secondi; il watchdog usa la più recente tra questa e `updated_at`.
- Nuova costante `MVD_WAI_CTRL_SLOW_THRESHOLD` (600 s); `ajaxStatus()` restituisce anche `exec_alive` e `stalled_sec`.

### Fixed
- Il pulsante "Riprendi" poteva essere ripremuto mentre la richiesta di ripresa era ancora in corso.
- Con un blocco ancora vivo dopo un errore, il polling si fermava e "Riprendi" non compariva più quando il blocco terminava: ora il polling continua finché il blocco è in esecuzione.

## [1.5.4] - 2026-11-25

### Fixed
- **Sonda PROCESSLIST**: filtrata sul database corrente (`DB = DATABASE()`). L'utente MySQL è condiviso con altri siti e la 1.5.3 avrebbe registrato nel log anche le loro query.

## [1.5.3] - 2026-11-25

### Fixed
- **Import saltato dopo un "Riprendi" a metà passo**: il flag `resuming` veniva azzerato solo quando il resume avveniva al primo chunk di un import. Con un resume a metà passo restava `true` e si trascinava all'import successivo, che saltava l'azzeramento dei counter PMXI e si chiudeva "success" in pochi secondi senza elaborare nessun record (riscontrato su staging: import IT del run 928). Ora il flag viene azzerato dopo il primo hop di ogni resume e anche in `MvdWaiCtrlState::advanceToNextImport()`.
- **Esito di WP All Import ignorato**: il valore restituito da `PMXI_Import_Record::execute()` non veniva controllato, quindi un import che non elaborava nulla ("No matching elements", upload del feed fallito) risultava "success" con contatori a zero. Ora uno status diverso da 200 a import concluso registra il passo come `warning` con il messaggio di WP All Import e la catena prosegue; se invece restano chunk pendenti (es. rifiuto licenza) la catena si ferma con errore, per non ciclare all'infinito.
- **Durata dei passi sbagliata di un'ora** (o due in ora legale): `duration_sec` confrontava un orario locale del sito con `time()` UTC. Nuovo metodo `MvdWaiCtrlRunner::secondsSinceLocal()` che usa `wp_timezone()`.
- **Blocchi interrotti senza traccia**: il loopback `mvd_wai_ctrl_run_chain` chiama ora `ignore_user_abort( true )` (come `wp-cron.php`), così la chiusura della connessione da parte del client non interrompe lo script a metà di un chunk.

### Added
- **Log diagnostico per hop** nel file `mvd-wai-ctrl-logs/YYYY-MM-DD.log`: righe `runStep START` / `runStep END` con PID, import, `queue_chunk_number` prima/dopo, durata e memoria di picco; riga `runStep SKIP` quando un hop trova il lock occupato; riga `WARNING` quando `scheduleSelf()` ripiega su wp-cron. Servono a verificare che due blocchi non girino mai in contemporanea.
- **Sonda PROCESSLIST**: durante l'esecuzione di un chunk, al massimo ogni 30 secondi, vengono scritte nel file log le altre query attive dello stesso utente DB (`information_schema.PROCESSLIST`), per identificare il processo concorrente in caso di deadlock.
- Badge `warning` e `info` nello storico dei run.


## [1.5.2] - 2026-05-19

### Added
- `readme.txt` in formato WordPress.org generato da `scripts/generate-readme.php` (`composer build:readme`). Risolve il tab "Changelog" vuoto nel modal "Visualizza dettagli" di WordPress e rimuove l'avviso "plugin non testato con la tua versione corrente" impostando `Tested up to: 6.9.4`.
- Verifica di sincronizzazione `readme.txt` ↔ `CHANGELOG.md` nel workflow `release.yml`: la release fallisce se `readme.txt` non è stato rigenerato prima del tag.

## [1.5.1] - 2026-05-18

### Fixed
- **Watchdog 180s non scattava**: `MvdWaiCtrlState::save()` usava `current_time('mysql')` per `updated_at`, che restituisce l'orario nel fuso del sito WordPress. Il watchdog in `ajaxStatus()` confrontava il valore con `time()` (sempre UTC) tramite `strtotime()`, che lo interpretava nel fuso PHP del processo. Su hosting con `date.timezone=UTC` e WordPress impostato su `Europe/Rome`, il delta risultava negativo (fino a −7200 s in DST), rendendo la condizione `> 180` sempre falsa. Soluzione: `updated_at` è ora salvato come **unix timestamp intero UTC** (`time()`), eliminando qualsiasi conversione di fuso. Aggiunta migrazione in `State::get()` che converte il vecchio formato stringa MySQL al volo.

## [1.5.0] - 2026-05-18

### Fixed
- **Resume con cron PMXI ancora attivo**: al click di "Riprendi", prima di lanciare il loopback, vengono ora resettati i flag runtime PMXI (`triggered`, `processing`, `executing`) sull'import corrente e si attende 3 secondi. Questo evita che un cron PMXI residuo blocchi la ripartenza, che prima richiedeva un reset manuale dalla UI di WP All Import Pro. `queue_chunk_number` e i counter restano intatti, così l'import riprende dal record esatto in cui si era interrotto.

### Added
- **History nativa WP All Import Pro**: ogni import della chain genera ora un record `wp_pmxi_history` (type `processing`) e il relativo file HTML in `uploads/wpallimport/logs/{history_id}/`. Il log è appendito chunk-per-chunk, compatibile con import multi-chunk. I record eccedenti la soglia `log_storage` vengono rimossi prima di ogni nuova esecuzione. In caso di resume dopo un crash, il record history esistente viene riutilizzato.

## [1.4.0] - 2026-05-18

### Added
- **Shutdown handler PHP**: `register_shutdown_function` nel runner intercetta fatal error / OOM e scrive una riga `crashed` nel DB log e nel file di log prima che il processo muoia.
- **Watchdog AJAX**: `ajaxStatus()` rileva cron morti — se `updated_at` è più vecchio di 180 s e il lock transient è assente, marca automaticamente il run come `crashed`.
- **Log su file rotante**: nuovo metodo `MvdWaiCtrlLogger::writeFile()` scrive in `wp-content/uploads/mvd-wai-ctrl-logs/YYYY-MM-DD.log`. La cartella è protetta con `.htaccess` deny-all. I file vengono rimossi dopo 30 giorni. Nuovo metodo `markRunCrashed()` per chiudere run interrotti.
- **Banner errore persistente**: sopra la progressbar compare un div rosso con messaggio di errore, passo interrotto e pulsanti "Riprendi" / "Reset". Resta visibile fino all'azione dell'utente.
- **Ripresa import interrotto**: nuovo handler AJAX `mvd_wai_ctrl_resume` che riprende la catena da `current_index` senza resettare i counter PMXI, sfruttando `queue_chunk_number` persistente nel record PMXI. `MvdWaiCtrlState::markResumeRequested()` imposta `resuming=true` per istruire il runner.
- **Resilienza polling JS**: dopo 3 fallimenti AJAX consecutivi il polling si ferma e mostra il banner con messaggio "Impossibile contattare il server".
- Nuove costanti: `MVD_WAI_CTRL_WATCHDOG_THRESHOLD` (180s), `MVD_WAI_CTRL_LOG_DIR`, `MVD_WAI_CTRL_LOG_RETENTION_DAYS` (30 giorni).
- Nuovo outcome `crashed` nel log (distinto da `error` per crash inattesi).
- `uninstall.php` rimuove la cartella log all'eliminazione del plugin.

### Changed
- `MvdWaiCtrlState::defaultState()` aggiunge `resuming` (bool) e `crash_reason` (string|null).
- Il blocco reset counter PMXI in `runStep()` viene saltato quando `resuming=true`.

## [1.3.3] - 2026-05-15

### Fixed
- Indicatore "Chunk X / Y" mostrava valori negativi su import multi-chunk. `queue_chunk_number` di PMXI è una posizione crescente (record già processati), non un countdown: `updateChunk()` ora usa il valore direttamente come "fatto" e `count` come totale. Il testo è aggiornato in "Record X / Y" per riflettere la semantica corretta.

## [1.3.2] - 2026-05-15

### Fixed
- `MvdWaiCtrlRunner::runStep()` non resettava i counter PMXI (`imported`, `created`, `updated`, `skipped`, `queue_chunk_number`, `processing`) prima di chiamare `execute()`. Al primo chunk di ogni step il runner chiama ora `$import->set([...])->update()` con i counter azzerati, replicando il pattern della CLI di WP All Import Pro. Senza questo reset, una seconda esecuzione della catena usciva silenziosamente da `execute()` (condizione `imported >= count` o `processing == 1` già soddisfatta) e loggava erroneamente "Success" senza aver importato nulla.

## [1.3.1] - 2026-05-15

### Added
- Costante `MVD_WAI_CTRL_DB_VERSION` per versionare lo schema DB indipendentemente dal plugin.
- Aggiornamento automatico dello schema DB a `plugins_loaded`: se `mvd_wai_ctrl_db_version` in DB è diversa dalla costante, `createTable()` viene rieseguita senza bisogno di disattivare/riattivare il plugin.

## [1.3.0] - 2026-05-15

### Added
- Elemento `Chunk X / Y` nella sezione di progresso: mostra il numero di chunk completati e il totale in modo visibile (non più solo nel messaggio di log).
- Colonne `import_name`, `is_run_header` e `started_at` nella tabella `{prefix}mvd_wai_ctrl_log`. `import_name` conserva il nome amichevole dell'importazione al momento dell'esecuzione; `is_run_header` sostituisce il vecchio sentinel `step_index = -1`; `started_at` registra l'inizio reale di ogni step.
- Metodo statico `MvdWaiCtrlRunner::getImportDisplayName()` per risolvere `friendly_name` / `name` di un'importazione PMXI con fallback su "Import ID N".
- Metodo `MvdWaiCtrlState::markStepStart()` per registrare il timestamp di inizio dello step e calcolarne la durata totale anche su import multi-chunk lunghi.

### Changed
- **Nomi importazioni nella UI**: la sezione "Sequenza configurata" e la barra di progresso live mostrano ora il nome amichevole dell'importazione (campo `friendly_name` di WP All Import Pro) invece del filename XML/CSV.
- **Barra di progresso corretta**: la formula di calcolo percentuale usa ora `chunk_done / total_chunks` (entrambe grandezze omogenee) invece di `queue_chunk_number / record_count`. La barra cresce monotonicamente.
- **`duration_sec` corretto**: riflette la durata totale dello step (dalla prima esecuzione del primo chunk all'ultimo), non solo l'ultimo chunk. Il valore è ora coerente con la differenza `created_at − started_at` visibile in DB.
- Label `last_message` durante l'elaborazione dei chunk semplificato a "Creati: X | Aggiornati: Y | Saltati: Z" (le info chunk sono nel nuovo elemento dedicato).
- Storico: query `getRecentRuns()` usa `WHERE is_run_header = 1` invece di `WHERE step_index = -1`.

### Fixed
- I `<details>` dello storico run si chiudevano a ogni ciclo di polling (3 s) perché `refreshRunsTable` riscriveva `innerHTML` distruggendo il DOM. Ora i run aperti vengono salvati prima del re-render e ripristinati dopo.

## [1.2.0] - 2026-05-15

### Removed
- Form admin per il token GitHub (sezione "Impostazioni aggiornamenti"), costante `MVD_WAI_CTRL_GH_TOKEN` e option `mvd_wai_ctrl_gh_token`. Il repository è ora pubblico: gli aggiornamenti automatici funzionano in modo anonimo senza necessità di autenticazione.

### Changed
- Repository GitHub passato da privato a pubblico: rimossa la dipendenza da Personal Access Token per scaricare le release.

### Fixed
- Pulizia automatica dell'option `mvd_wai_ctrl_gh_token` residua nei siti che avevano già salvato un token (eseguita una volta a `plugins_loaded` priority 1).

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
