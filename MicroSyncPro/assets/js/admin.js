/* Micro Sync Pro admin script — dependency-free. */
( function () {
	'use strict';

	var cfg = window.MSP || { i18n: {} };
	var t = function ( key ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || key;
	};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok || ! json || ! json.success ) {
					var msg = json && json.data && json.data.message ? json.data.message : t( 'networkError' );
					throw new Error( msg );
				}
				return json.data;
			} );
		} );
	}

	function fmt( n ) {
		try {
			return new Intl.NumberFormat( document.documentElement.lang || undefined ).format( n );
		} catch ( e ) {
			return String( n );
		}
	}

	/* Live progress panels. */
	function Progress( el ) {
		this.el = el;
		this.id = el.getAttribute( 'data-msp-run' );
		this.reload = el.getAttribute( 'data-msp-reload' ) === '1';
		this.failures = 0;
		this.timer = null;
	}
	Progress.prototype.field = function ( name ) {
		return this.el.querySelector( '[data-msp-field="' + name + '"]' );
	};
	Progress.prototype.render = function ( run ) {
		var bar = this.el.querySelector( '.msp-bar' );
		var fill = this.el.querySelector( '.msp-bar__fill' );
		if ( fill ) {
			fill.style.width = run.percent + '%';
		}
		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', run.percent );
		}
		var status = this.field( 'status' );
		if ( status ) {
			status.innerHTML = '';
			var b = document.createElement( 'span' );
			b.className = 'msp-badge msp-badge--' + run.status;
			b.textContent = t( run.status );
			status.appendChild( b );
		}
		var phase = this.field( 'phase' );
		if ( phase ) {
			phase.textContent = run.active ? t( run.phase ) : '';
		}
		var counts = this.field( 'counts' );
		if ( counts ) {
			counts.textContent = fmt( run.processed ) + ' / ' + fmt( run.total );
		}
		[ 'updated', 'unchanged', 'skipped', 'failed' ].forEach( function ( k ) {
			var f = this.field( k );
			if ( f ) {
				f.textContent = fmt( run[ k ] );
			}
		}, this );
		this.el.setAttribute( 'data-msp-active', run.active ? '1' : '0' );
		var actions = this.el.querySelector( '.msp-progress__actions' );
		if ( actions && ! run.active ) {
			actions.hidden = true;
		}
	};
	Progress.prototype.tick = function () {
		var self = this;
		post( 'msp_run_tick', { run_id: self.id } )
			.then( function ( run ) {
				self.failures = 0;
				self.error( '' );
				self.render( run );
				if ( run.active ) {
					self.schedule( 1500 );
				} else if ( self.reload ) {
					self.error( '' );
					var phase = self.field( 'phase' );
					if ( phase ) {
						phase.textContent = t( 'reloading' );
					}
					window.setTimeout( function () {
						window.location.reload();
					}, 800 );
				}
			} )
			.catch( function ( err ) {
				self.failures++;
				self.error( err.message || t( 'networkError' ) );
				self.schedule( Math.min( 30000, 2000 * self.failures ) );
			} );
	};
	Progress.prototype.schedule = function ( ms ) {
		var self = this;
		window.clearTimeout( this.timer );
		this.timer = window.setTimeout( function () {
			self.tick();
		}, ms );
	};
	Progress.prototype.error = function ( msg ) {
		var e = this.field( 'error' );
		if ( ! e ) {
			return;
		}
		e.textContent = msg;
		e.hidden = ! msg;
	};

	function initProgress() {
		document.querySelectorAll( '.msp-progress[data-msp-active="1"]' ).forEach( function ( el ) {
			var p = new Progress( el );
			p.schedule( 300 );
			var cancel = el.querySelector( '[data-msp-cancel]' );
			if ( cancel ) {
				cancel.addEventListener( 'click', function () {
					if ( ! window.confirm( t( 'confirmCancel' ) ) ) {
						return;
					}
					cancel.disabled = true;
					post( 'msp_run_cancel', { run_id: p.id } )
						.then( function ( run ) {
							p.render( run );
						} )
						.catch( function ( err ) {
							p.error( err.message );
							cancel.disabled = false;
						} );
				} );
			}
		} );
	}

	/* Confirmation dialogs. */
	function initConfirm() {
		document.querySelectorAll( 'form[data-msp-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( ! window.confirm( form.getAttribute( 'data-msp-confirm' ) ) ) {
					e.preventDefault();
				}
			} );
		} );
		document.querySelectorAll( '[data-msp-confirm-click]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				if ( ! window.confirm( btn.getAttribute( 'data-msp-confirm-click' ) ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	/* Busy state for slow submits; prevents double submission. */
	function initBusy() {
		document.querySelectorAll( '.msp-wrap form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( e.defaultPrevented || form.method.toLowerCase() !== 'post' ) {
					return;
				}
				window.setTimeout( function () {
					form.querySelectorAll( 'button[type="submit"], input[type="submit"]' ).forEach( function ( b ) {
						b.disabled = true;
						b.setAttribute( 'aria-busy', 'true' );
						if ( b.hasAttribute( 'data-msp-busy' ) ) {
							b.textContent = b.getAttribute( 'data-msp-busy' );
						}
					} );
				}, 0 );
			} );
		} );
	}

	/* Source form: show fields relevant to the selected type / auth method. */
	function initSourceForm() {
		var form = document.querySelector( '[data-msp-source-form]' );
		if ( ! form ) {
			return;
		}
		var type = form.querySelector( '[data-msp-type]' );
		var auth = form.querySelector( '[data-msp-auth]' );
		function toggle( attr, value ) {
			form.querySelectorAll( '[' + attr + ']' ).forEach( function ( el ) {
				var list = el.getAttribute( attr ).split( ' ' );
				var show = list.indexOf( value ) !== -1;
				el.hidden = ! show;
				el.querySelectorAll( 'input, select, textarea' ).forEach( function ( i ) {
					i.disabled = ! show;
				} );
			} );
		}
		function update() {
			toggle( 'data-msp-show-for', type.value );
			if ( auth && ( type.value === 'csv_url' || type.value === 'json_url' ) ) {
				toggle( 'data-msp-auth-for', auth.value );
			}
		}
		type.addEventListener( 'change', update );
		if ( auth ) {
			auth.addEventListener( 'change', update );
		}
		update();
		// The disabled type select is not submitted on edit; that is intended (type is immutable).
	}

	/* Category rules: add/remove rows. */
	function initRules() {
		var table = document.querySelector( '[data-msp-rules]' );
		if ( ! table ) {
			return;
		}
		var tbody = table.querySelector( 'tbody' );
		var tpl = tbody.querySelector( '[data-msp-rule-template]' );
		var add = document.querySelector( '[data-msp-add-rule]' );
		var counter = tbody.children.length + 100;
		tbody.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-msp-remove-rule]' );
			if ( ! btn ) {
				return;
			}
			var row = btn.closest( 'tr' );
			if ( tbody.children.length > 1 ) {
				row.parentNode.removeChild( row );
			} else {
				row.querySelectorAll( 'input[type="text"]' ).forEach( function ( i ) {
					i.value = '';
				} );
				row.querySelector( 'select' ).value = '0';
			}
		} );
		if ( add && tpl ) {
			add.hidden = false;
			add.addEventListener( 'click', function () {
				var clone = tpl.cloneNode( true );
				clone.removeAttribute( 'data-msp-rule-template' );
				counter++;
				clone.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( /\[category_rules\]\[\d+\]/, '[category_rules][' + counter + ']' );
					if ( el.type === 'checkbox' ) {
						el.checked = true;
					} else {
						el.value = el.tagName === 'SELECT' ? '0' : '';
					}
				} );
				clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
					el.id = el.id + '-' + counter;
				} );
				clone.querySelectorAll( 'label[for]' ).forEach( function ( el ) {
					el.htmlFor = el.htmlFor + '-' + counter;
				} );
				tbody.appendChild( clone );
				var sel = clone.querySelector( 'select' );
				if ( sel ) {
					sel.focus();
				}
			} );
		}
	}

	function initAutosubmit() {
		document.querySelectorAll( '[data-msp-autosubmit]' ).forEach( function ( el ) {
			el.addEventListener( 'change', function () {
				el.form.submit();
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initProgress();
		initConfirm();
		initBusy();
		initSourceForm();
		initRules();
		initAutosubmit();
	} );
} )();
