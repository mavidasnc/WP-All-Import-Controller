/* global mvdWaiCtrl */
( function () {
	'use strict';

	var cfg              = mvdWaiCtrl;
	var pollTimer        = null;
	var pollFailures     = 0;   // contatore errori AJAX consecutivi del polling
	var startBtn         = null;
	var resetBtn         = null;
	var progressDiv      = null;
	var progressBar      = null;
	var progressLbl      = null;
	var chunkInfo        = null;
	var lastMsg          = null;
	var toast            = null;
	var errorBanner      = null;
	var errorMessage     = null;
	var errorStep        = null;
	var resumeBtn        = null;
	var resetBannerBtn   = null;

	document.addEventListener( 'DOMContentLoaded', function () {
		startBtn       = document.getElementById( 'mvd-wai-ctrl-start-btn' );
		resetBtn       = document.getElementById( 'mvd-wai-ctrl-reset-btn' );
		progressDiv    = document.getElementById( 'mvd-wai-ctrl-progress' );
		progressBar    = document.getElementById( 'mvd-wai-ctrl-progress-bar' );
		progressLbl    = document.getElementById( 'mvd-wai-ctrl-progress-label' );
		chunkInfo      = document.getElementById( 'mvd-wai-ctrl-chunk-info' );
		lastMsg        = document.getElementById( 'mvd-wai-ctrl-last-msg' );
		toast          = document.getElementById( 'mvd-wai-ctrl-toast' );
		errorBanner    = document.getElementById( 'mvd-wai-ctrl-error-banner' );
		resumeBtn      = document.getElementById( 'mvd-wai-ctrl-resume-btn' );
		resetBannerBtn = document.getElementById( 'mvd-wai-ctrl-reset-banner-btn' );
		errorMessage   = errorBanner ? errorBanner.querySelector( '.mvd-wai-ctrl-error-message' ) : null;
		errorStep      = errorBanner ? errorBanner.querySelector( '.mvd-wai-ctrl-error-step' )    : null;

		if ( startBtn ) {
			startBtn.addEventListener( 'click', onStartClick );
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', onResetClick );
		}

		if ( resumeBtn ) {
			resumeBtn.addEventListener( 'click', onResumeClick );
		}

		if ( resetBannerBtn ) {
			resetBannerBtn.addEventListener( 'click', onResetClick );
		}

		// Se al caricamento la pagina mostra il blocco progresso (status=running o error),
		// riavvia il polling automaticamente.
		if ( progressDiv && progressDiv.style.display !== 'none' ) {
			startPolling();
		}
		// Se al caricamento c'è già un banner di errore, avvia comunque il polling
		// per sincronizzare lo stato (es. ricaricamento pagina dopo crash).
		if ( errorBanner && errorBanner.style.display !== 'none' ) {
			startPolling();
		}
	} );

	/**
	 * Gestisce il click sul pulsante di avvio.
	 */
	function onStartClick() {
		if ( ! window.confirm( cfg.i18n.confirmRun ) ) {
			return;
		}

		startBtn.disabled    = true;
		startBtn.textContent = cfg.i18n.starting;

		ajaxPost(
			'mvd_wai_ctrl_start',
			cfg.nonceStart,
			function ( data ) {
				showProgress( true );
				startPolling();
			},
			function ( err ) {
				startBtn.disabled    = false;
				startBtn.textContent = refreshButtonLabel();
				showToast( err || cfg.i18n.error, 'error' );
			}
		);
	}

	/**
	 * Gestisce il click sul pulsante "Riprendi" nel banner di errore.
	 */
	function onResumeClick() {
		if ( resumeBtn ) {
			resumeBtn.disabled    = true;
			resumeBtn.textContent = cfg.i18n.resuming;
		}

		ajaxPost(
			'mvd_wai_ctrl_resume',
			cfg.nonceResume,
			function () {
				hideErrorBanner();
				showProgress( true );
				pollFailures = 0;
				startPolling();
			},
			function ( err ) {
				if ( resumeBtn ) {
					resumeBtn.disabled    = false;
					resumeBtn.textContent = 'Riprendi';
				}
				showToast( err || cfg.i18n.error, 'error' );
			}
		);
	}

	/**
	 * Gestisce il click sul pulsante di reset stato bloccato.
	 */
	function onResetClick() {
		if ( ! window.confirm( 'Sbloccare lo stato e resettare l\'importazione in corso? Usare solo se il processo si è bloccato.' ) ) {
			return;
		}

		ajaxPost(
			'mvd_wai_ctrl_reset',
			cfg.nonceReset,
			function () {
				stopPolling();
				pollFailures = 0;
				hideErrorBanner();
				showProgress( false );
				if ( startBtn ) {
					startBtn.disabled    = false;
					startBtn.textContent = refreshButtonLabel();
				}
				if ( resetBtn ) {
					resetBtn.style.display = 'none';
				}
				showToast( 'Stato resettato. Puoi avviare una nuova importazione.', 'success' );
			},
			function ( err ) {
				showToast( err || 'Errore durante il reset.', 'error' );
			}
		);
	}

	/**
	 * Avvia il polling dello stato ogni cfg.pollInterval ms.
	 */
	function startPolling() {
		if ( pollTimer ) {
			return;
		}
		pollTimer = setInterval( pollStatus, cfg.pollInterval );
		pollStatus(); // Prima chiamata immediata.
	}

	/**
	 * Ferma il polling.
	 */
	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	/**
	 * Richiede lo stato corrente al server.
	 */
	function pollStatus() {
		ajaxPost(
			'mvd_wai_ctrl_status',
			cfg.nonceStatus,
			function ( data ) {
				pollFailures = 0;
				updateUI( data );
			},
			function () {
				pollFailures++;
				// Dopo 3 fallimenti consecutivi mostra il banner di errore di rete.
				if ( pollFailures >= 3 ) {
					stopPolling();
					showErrorBanner( cfg.i18n.networkError, '', false );
				}
			}
		);
	}

	/**
	 * Aggiorna l'intera UI in base ai dati di stato ricevuti.
	 *
	 * @param {Object} data Risposta AJAX: { state, runs }.
	 */
	function updateUI( data ) {
		var state = data.state || {};

		var status     = state.status      || 'idle';
		var current    = parseInt( state.step_current,              10 ) || 0;
		var total      = parseInt( state.step_total,                10 ) || 4;
		var chunkDone  = parseInt( state.current_chunk_done,        10 ) || 0;
		var totChunks  = parseInt( state.current_step_total_chunks, 10 ) || 0;

		// Percentuale: step completati (current è 1-based → base = current-1) + frazione intra-step.
		var frac = totChunks > 0 ? Math.min( 1, chunkDone / totChunks ) : 0;
		var pct  = total > 0 ? Math.round( ( ( current - 1 + frac ) / total ) * 100 ) : 0;
		pct = Math.max( 0, Math.min( 100, pct ) );

		// Aggiorna barra progresso.
		if ( progressBar ) {
			progressBar.style.width = pct + '%';
			progressBar.classList.remove( 'is-error', 'is-completed' );
			if ( 'error' === status ) {
				progressBar.classList.add( 'is-error' );
			} else if ( 'completed' === status ) {
				progressBar.classList.add( 'is-completed' );
				progressBar.style.width = '100%';
			}
		}

		if ( progressLbl ) {
			progressLbl.textContent = state.step_label || '';
		}

		if ( chunkInfo ) {
			chunkInfo.textContent = totChunks > 0
				? 'Record ' + chunkDone + ' / ' + totChunks
				: '';
		}

		if ( lastMsg ) {
			lastMsg.textContent = state.last_message || '';
		}

		// Aggiorna stato pulsanti.
		if ( startBtn ) {
			var isRunning = 'running' === status;
			startBtn.disabled    = isRunning;
			startBtn.textContent = isRunning ? cfg.i18n.running : refreshButtonLabel();
		}
		if ( resetBtn ) {
			resetBtn.style.display = ( 'running' === status ) ? '' : 'none';
		}

		// Aggiorna tabella storico.
		if ( data.runs ) {
			refreshRunsTable( data.runs );
		}

		// Banner errore: mostra/nascondi in base allo status.
		if ( 'error' === status ) {
			var stepNum   = ( parseInt( state.current_index, 10 ) || 0 ) + 1;
			var stepTotal = parseInt( state.step_total, 10 ) || 4;
			var stepText  = 'Interrotto al passo ' + stepNum + ' di ' + stepTotal + '.';
			showErrorBanner( state.crash_reason || state.last_message || cfg.i18n.error, stepText, !! data.can_resume );
		} else {
			hideErrorBanner();
		}

		// Gestione stati terminali.
		if ( 'completed' === status ) {
			stopPolling();
			showProgress( false );
			showToast( cfg.i18n.completed, 'success' );
		} else if ( 'error' === status ) {
			stopPolling();
		}
	}

	/**
	 * Mostra o nasconde il blocco di progresso.
	 *
	 * @param {boolean} show
	 */
	function showProgress( show ) {
		if ( progressDiv ) {
			progressDiv.style.display = show ? '' : 'none';
		}
	}

	/**
	 * Mostra il banner di errore persistente sopra la progressbar.
	 *
	 * @param {string}  message     Messaggio di errore.
	 * @param {string}  stepText    Informazione sul passo interrotto.
	 * @param {boolean} canResume   Se true, mostra il pulsante Riprendi.
	 */
	function showErrorBanner( message, stepText, canResume ) {
		if ( ! errorBanner ) {
			return;
		}
		if ( errorMessage ) {
			errorMessage.textContent = message || '';
		}
		if ( errorStep ) {
			errorStep.textContent = stepText || '';
		}
		if ( resumeBtn ) {
			resumeBtn.style.display  = canResume ? '' : 'none';
			resumeBtn.disabled       = false;
			resumeBtn.textContent    = 'Riprendi';
		}
		errorBanner.style.display = '';
		showProgress( true );
	}

	/**
	 * Nasconde il banner di errore.
	 */
	function hideErrorBanner() {
		if ( errorBanner ) {
			errorBanner.style.display = 'none';
		}
	}

	/**
	 * Mostra un toast temporaneo.
	 *
	 * @param {string} message  Testo del messaggio.
	 * @param {string} type     'success' o 'error'.
	 */
	function showToast( message, type ) {
		if ( ! toast ) {
			return;
		}
		toast.textContent = message;
		toast.className   = 'mvd-wai-ctrl-toast is-' + type;
		toast.style.display = 'block';
		toast.style.opacity = '1';

		setTimeout( function () {
			toast.style.opacity = '0';
			setTimeout( function () {
				toast.style.display = 'none';
			}, 300 );
		}, 5000 );
	}

	/**
	 * Aggiorna la tabella dello storico con i dati ricevuti dal server.
	 *
	 * @param {Array} runs Array di run objects.
	 */
	function refreshRunsTable( runs ) {
		var container = document.getElementById( 'mvd-wai-ctrl-log-table' );
		if ( ! container ) {
			return;
		}
		if ( ! runs.length ) {
			container.innerHTML = '<p>' + escHtml( 'Nessuna esecuzione registrata.' ) + '</p>';
			return;
		}

		// Salva i run_id dei <details> aperti prima di sovrascrivere il DOM.
		var openRunIds = new Set(
			Array.from( container.querySelectorAll( 'details[data-run-id][open]' ) )
				.map( function ( el ) { return el.dataset.runId; } )
		);

		var html = '<table class="widefat striped mvd-wai-ctrl-runs-table">'
			+ '<thead><tr>'
			+ '<th>Run ID</th><th>Avviato il</th><th>Esito</th><th>Passi</th><th>Dettaglio</th>'
			+ '</tr></thead><tbody>';

		runs.forEach( function ( entry ) {
			var run   = entry.run   || {};
			var steps = entry.steps || [];
			var oc    = run.outcome || 'start';
			var ocClass = 'start' === oc ? 'mvd-outcome-running' : 'mvd-outcome-' + oc;

			html += '<tr>'
				+ '<td>' + escHtml( String( run.run_id || '' ) ) + '</td>'
				+ '<td>' + escHtml( run.created_at || '' ) + '</td>'
				+ '<td><span class="mvd-wai-ctrl-badge ' + escHtml( ocClass ) + '">' + escHtml( oc ) + '</span></td>'
				+ '<td>' + escHtml( String( steps.length ) ) + '/4</td>'
				+ '<td>';

			if ( steps.length ) {
				html += '<details data-run-id="' + escHtml( String( run.run_id || '' ) ) + '"><summary>Visualizza</summary>'
					+ '<table class="mvd-wai-ctrl-steps-table">'
					+ '<tr><th>Passo</th><th>Import ID</th><th>Esito</th><th>Creati</th><th>Aggiornati</th><th>Saltati</th><th>Durata (s)</th></tr>';
				steps.forEach( function ( step ) {
					var sOc = step.outcome || '';
					html += '<tr>'
						+ '<td>' + escHtml( String( ( parseInt( step.step_index, 10 ) || 0 ) + 1 ) ) + '</td>'
						+ '<td>' + escHtml( String( step.import_id || '' ) ) + '</td>'
						+ '<td><span class="mvd-wai-ctrl-badge mvd-outcome-' + escHtml( sOc ) + '">' + escHtml( sOc ) + '</span></td>'
						+ '<td>' + escHtml( String( step.created   || 0 ) ) + '</td>'
						+ '<td>' + escHtml( String( step.updated   || 0 ) ) + '</td>'
						+ '<td>' + escHtml( String( step.skipped   || 0 ) ) + '</td>'
						+ '<td>' + escHtml( String( step.duration_sec || 0 ) ) + '</td>'
						+ '</tr>';
					if ( step.message ) {
						html += '<tr class="mvd-wai-ctrl-step-msg"><td colspan="7"><pre>' + escHtml( step.message ) + '</pre></td></tr>';
					}
				} );
				html += '</table></details>';
			}

			html += '</td></tr>';
		} );

		html += '</tbody></table>';
		container.innerHTML = html;

		// Ripristina lo stato aperto dei <details> che erano aperti prima del re-render.
		openRunIds.forEach( function ( runId ) {
			var el = container.querySelector( 'details[data-run-id="' + runId.replace( /"/g, '\\"' ) + '"]' );
			if ( el ) {
				el.open = true;
			}
		} );
	}

	/**
	 * Esegue una chiamata POST all'endpoint AJAX di WordPress.
	 *
	 * @param {string}   action    Nome dell'action AJAX.
	 * @param {string}   nonce     Nonce di sicurezza.
	 * @param {Function} onSuccess Callback invocata con data.data in caso di successo.
	 * @param {Function} onError   Callback invocata con il messaggio di errore.
	 */
	function ajaxPost( action, nonce, onSuccess, onError ) {
		var body = new URLSearchParams();
		body.append( 'action',  action );
		body.append( '_ajax_nonce', nonce );

		fetch( cfg.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		} )
		.then( function ( res ) {
			return res.json();
		} )
		.then( function ( json ) {
			if ( json.success ) {
				onSuccess( json.data );
			} else {
				onError( ( json.data && json.data.message ) || '' );
			}
		} )
		.catch( function () {
			onError( '' );
		} );
	}

	/**
	 * Testo del pulsante quando non è in corso un'esecuzione.
	 *
	 * @returns {string}
	 */
	function refreshButtonLabel() {
		return 'Avvia importazione sequenziale';
	}

	/**
	 * Effettua l'escape HTML di una stringa.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}
}() );
