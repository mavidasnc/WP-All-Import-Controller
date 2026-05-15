=== MVD WP All Import Controller ===
Contributors: mavida
Tags: wp all import, import, automation, sequential
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Esegue 4 importazioni WP All Import Pro in sequenza con un solo click dal pannello admin.

== Description ==

Il plugin aggiunge un menu dedicato "Import Sequenziale" nell'admin di WordPress con un pulsante
che avvia le 4 importazioni configurate in ordine sequenziale (Passo 1 → 4).

Caratteristiche principali:
* Esecuzione asincrona in background (non blocca il browser)
* Stop automatico alla prima importazione fallita
* Barra di progresso con aggiornamento in tempo reale tramite polling AJAX
* Log persistente con storico delle ultime 20 esecuzioni e dettaglio per ogni passo
* Lock anti-doppio-avvio
* Compatibile con WP All Import Pro 5.x

== Requisiti ==

* WP All Import Pro >= 5.0 attivo e configurato
* WordPress >= 6.0
* PHP >= 8.1

== Installazione ==

1. Caricare la cartella `mvd-wp-all-import-controller` in `wp-content/plugins/`.
2. Aprire il file `mvd-wp-all-import-controller.php` e impostare gli ID reali
   delle 4 importazioni nella costante `MVD_WAI_CTRL_IDS`.
3. Attivare il plugin da Admin → Plugin.
4. Verificare la voce "Import Sequenziale" nel menu laterale.

Gli ID degli import si trovano in WP All Import → Manage Imports, colonna ID.

== Changelog ==

= 1.0.0 =
* Rilascio iniziale.
