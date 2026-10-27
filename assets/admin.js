/**
 * Content Powered > Text Us Widget page behaviour. Only loaded on that page.
 *
 * Tabs, rows that depend on a checkbox, the live preview, the phone number
 * hint, the Turnstile key test and the "delete all taps" button.
 * text_us_widget_admin.ajaxUrl, .nonce, .defaults, .icons and .i18n are
 * passed in from PHP (see Text_Us_Widget_Admin::enqueue_assets).
 */

/* global text_us_widget_admin, jQuery */
( function () {
	'use strict';

	var data = window.text_us_widget_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.tuw-form' );

		if ( ! form ) {
			return;
		}

		initTabs( form );
		initDepends( form );
		initPreview( form );
		initNumber( form );
		initTest( form );
		initReset();
	} );

	function field( form, key ) {
		return form.querySelector( '[name="text_us_widget_settings[' + key + ']"]' );
	}

	function checked( form, key ) {
		var box = form.querySelector( 'input[type="checkbox"][name="text_us_widget_settings[' + key + ']"]' );
		return !! box && box.checked;
	}

	function radio( form, key ) {
		var chosen = form.querySelector( 'input[type="radio"][name="text_us_widget_settings[' + key + ']"]:checked' );
		return chosen ? chosen.value : '';
	}

	function post( params ) {
		var body = new FormData();

		Object.keys( params ).forEach( function ( key ) {
			body.append( key, params[ key ] );
		} );
		body.append( 'nonce', data.nonce );

		return fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-tuw-tab]' );
		var panels = document.querySelectorAll( '[data-tuw-panel]' );
		var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-tuw-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-tuw-tab' ) );
				return;
			}

			// A box can belong to several tabs ("widget display").
			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-tuw-panel' ).split( ' ' ).indexOf( id ) < 0;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}

			document.dispatchEvent( new CustomEvent( 'tuw-tab', { detail: id } ) );
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-tuw-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rows that only apply while a checkbox is ticked (or unticked: "!key") ------

	function initDepends( form ) {
		var rows = form.querySelectorAll( '[data-tuw-depends]' );
		var shows = form.querySelectorAll( '[data-tuw-depends-show]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-tuw-depends' );
				var invert = key.charAt( 0 ) === '!';
				var on = checked( form, invert ? key.slice( 1 ) : key );

				row.classList.toggle( 'is-disabled', invert ? on : ! on );
			} );

			shows.forEach( function ( el ) {
				el.hidden = ! checked( form, el.getAttribute( 'data-tuw-depends-show' ) );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// Live preview -----------------------------------------------------------------

	function initPreview( form ) {
		var screen = document.querySelector( '[data-tuw-screen]' );
		var inner = document.querySelector( '[data-tuw-screen-inner]' );
		var root = screen ? screen.querySelector( '.tuw' ) : null;

		if ( ! root ) {
			return;
		}

		var device = 'iphone';
		var verified = false;
		var buttons = document.querySelectorAll( '[data-tuw-preview]' );
		var numberEl = root.querySelector( '.tuw-number' );
		var check = root.querySelector( '.tuw-check' );

		root.classList.add( 'tuw-on', 'tuw-shown' );

		function onAccent( hex ) {
			var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( hex || '' );

			if ( ! m ) {
				return '#fff';
			}

			var lum = [ m[ 1 ], m[ 2 ], m[ 3 ] ].map( function ( part, i ) {
				var c = parseInt( part, 16 ) / 255;
				c = c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
				return c * [ 0.2126, 0.7152, 0.0722 ][ i ];
			} ).reduce( function ( a, b ) {
				return a + b;
			}, 0 );

			return lum > 0.55 ? '#000' : '#fff';
		}

		function setAccent( color ) {
			if ( ! /^#[0-9a-f]{6}$/i.test( color || '' ) ) {
				color = '#0a84ff';
			}
			root.style.setProperty( '--tuw-accent', color );
			root.style.setProperty( '--tuw-on-accent', onAccent( color ) );
		}

		function size() {
			var wide = device === 'mac';
			var width = wide ? 460 : 390;
			var scale = screen.clientWidth / width;

			inner.style.width = width + 'px';
			inner.style.height = Math.ceil( screen.clientHeight / scale ) + 'px';
			inner.style.setProperty( '--tuw-s', scale );
		}

		function icons() {
			var os = device === 'android' ? 'g' : 'a';
			var src = radio( form, 'icon' ) === 'site' && data.icons.site ? data.icons.site : data.icons.app[ os ];

			root.querySelectorAll( '[data-tuw-icon]' ).forEach( function ( el ) {
				var img = el.querySelector( 'img' );

				if ( ! img ) {
					img = document.createElement( 'img' );
					img.alt = '';
					el.appendChild( img );
				}
				img.src = src;
			} );
		}

		function update() {
			var wide = device === 'mac';
			var turnstile = checked( form, 'turnstile' );
			var x = parseInt( ( field( form, 'offset_x' ) || {} ).value, 10 );
			var y = parseInt( ( field( form, 'offset_y' ) || {} ).value, 10 );
			var number = field( form, 'number_display' );

			screen.classList.toggle( 'is-wide', wide );
			screen.classList.toggle( 'is-phone', ! wide );
			root.classList.toggle( 'tuw-wide', wide );
			root.classList.toggle( 'tuw-phone', ! wide );
			root.classList.toggle( 'tuw-os-android', device === 'android' );
			root.classList.toggle( 'tuw-os-apple', device !== 'android' );

			[ 'dark', 'light', 'auto' ].forEach( function ( theme ) {
				root.classList.toggle( 'tuw-' + theme, radio( form, 'theme' ) === theme );
			} );
			root.classList.toggle( 'tuw-corner-left', radio( form, 'corner' ) === 'left' );
			root.classList.toggle( 'tuw-corner-right', radio( form, 'corner' ) !== 'left' );
			root.classList.toggle( 'tuw-pl-button', radio( form, 'phone_layout' ) === 'button' );
			root.classList.toggle( 'tuw-pl-bar', radio( form, 'phone_layout' ) !== 'button' );

			root.style.setProperty( '--tuw-x', ( isNaN( x ) ? 20 : Math.max( 0, Math.min( 200, x ) ) ) + 'px' );
			root.style.setProperty( '--tuw-y', ( isNaN( y ) ? 20 : Math.max( 0, Math.min( 200, y ) ) ) + 'px' );

			form.querySelectorAll( '[data-tuw-label]' ).forEach( function ( input ) {
				var key = input.getAttribute( 'data-tuw-key' );
				var target = root.querySelector( '[data-tuw-text="' + key + '"]' );

				if ( target ) {
					target.textContent = input.value.trim() || data.defaults[ key ] || '';
				}
			} );

			if ( check ) {
				check.hidden = ! turnstile;
			}
			root.classList.toggle( 'tuw-verified', ! turnstile || verified );

			if ( numberEl ) {
				numberEl.textContent = number ? number.value.trim() : '';
				numberEl.hidden = ! checked( form, 'show_number' ) || ! numberEl.textContent;
			}

			icons();
			size();
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				device = button.getAttribute( 'data-tuw-preview' );
				buttons.forEach( function ( b ) {
					var active = b === button;
					b.classList.toggle( 'is-active', active );
					b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
				update();
			} );
		} );

		root.addEventListener( 'click', function ( e ) {
			var t = e.target;

			if ( t.closest( '.tuw-cta' ) ) {
				e.preventDefault();
			} else if ( t.closest( '[data-tuw-dismiss]' ) ) {
				// Show what closing does, then bring it back.
				root.classList.remove( 'tuw-shown' );
				setTimeout( function () {
					root.classList.add( 'tuw-shown' );
				}, 1200 );
			} else if ( t.closest( '[data-tuw-close]' ) ) {
				root.classList.remove( 'tuw-open' );
				verified = false;
				update();
			} else if ( t.closest( '[data-tuw-open]' ) ) {
				root.classList.add( 'tuw-open' );
			} else if ( t.closest( '.tuw-preview-turnstile' ) ) {
				verified = true;
				update();
			}
		} );

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		window.addEventListener( 'resize', size );
		document.addEventListener( 'tuw-tab', size );

		if ( window.jQuery && jQuery.fn.wpColorPicker ) {
			jQuery( '.tuw-color' ).wpColorPicker( {
				change: function ( event, ui ) {
					setAccent( ui.color.toString() );
				},
				clear: function () {
					setAccent( '#0a84ff' );
				}
			} );
		}

		var accent = field( form, 'accent' );
		setAccent( accent ? accent.value : '' );
		update();
	}

	// Phone number hint ------------------------------------------------------------

	function initNumber( form ) {
		var input = field( form, 'number_display' );
		var hint = document.querySelector( '[data-tuw-number-hint]' );

		if ( ! input || ! hint ) {
			return;
		}

		var original = hint.textContent;

		function update() {
			var raw = input.value.trim();
			var digits = raw.replace( /\D+/g, '' );

			hint.classList.remove( 'is-warning', 'is-error' );

			if ( ! raw ) {
				hint.textContent = original;
			} else if ( ! /^\+?[0-9\s().\-/]+$/.test( raw ) || digits.length < 3 || digits.length > 15 ) {
				hint.textContent = data.i18n.badNumber;
				hint.classList.add( 'is-error' );
			} else if ( raw.charAt( 0 ) !== '+' ) {
				hint.textContent = data.i18n.noPlus;
				hint.classList.add( 'is-warning' );
			} else {
				hint.textContent = data.i18n.linkPreview.replace( '%s', 'sms:+' + digits );
			}
		}

		input.addEventListener( 'input', update );
		update();
	}

	// Turnstile key test ---------------------------------------------------------------

	function initTest( form ) {
		var button = document.querySelector( '[data-tuw-test]' );
		var area = document.querySelector( '[data-tuw-test-area]' );
		var mount = document.querySelector( '[data-tuw-test-widget]' );
		var result = document.querySelector( '[data-tuw-test-result]' );
		var widgetId = null;
		var run = 0;

		if ( ! button || ! area || ! mount || ! result ) {
			return;
		}

		function say( text, type ) {
			result.textContent = text;
			result.className = 'tuw-test-result' + ( type ? ' is-' + type : '' );
		}

		function load() {
			if ( window.turnstile ) {
				return Promise.resolve( window.turnstile );
			}
			return new Promise( function ( resolve, reject ) {
				window.tuwAdminTurnstileReady = function () {
					resolve( window.turnstile );
				};
				var s = document.createElement( 'script' );
				s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=tuwAdminTurnstileReady';
				s.async = true;
				s.onerror = reject;
				document.head.appendChild( s );
			} );
		}

		function clientError( code ) {
			code = String( code || '' );
			if ( /^1101[01]/.test( code ) || code === '400020' ) {
				return data.i18n.badSiteKey;
			}
			if ( code === '110200' ) {
				return data.i18n.badDomain;
			}
			return data.i18n.clientError.replace( '%s', code || '?' );
		}

		button.addEventListener( 'click', function () {
			var siteKey = document.getElementById( 'tuw-site-key' );
			var secret = document.getElementById( 'tuw-secret-key' );
			var key = siteKey ? siteKey.value.trim() : '';

			if ( ! key ) {
				say( data.i18n.noSiteKey, 'error' );
				return;
			}

			// Only the latest test may report: an older answer could arrive later.
			var current = ++run;

			say( data.i18n.testing );
			area.hidden = false;

			load().then( function ( ts ) {
				if ( widgetId !== null ) {
					ts.remove( widgetId );
				}
				say( '' );
				widgetId = ts.render( mount, {
					sitekey: key,
					action: 'text_us_widget',
					callback: function ( token ) {
						if ( current !== run ) {
							return;
						}
						say( data.i18n.verifying );
						post( {
							action: 'text_us_widget_test_turnstile',
							token: token,
							secret: secret ? secret.value.trim() : ''
						} ).then( function ( res ) {
							if ( current !== run ) {
								return;
							}
							var message = res && res.data && res.data.message ? res.data.message : data.i18n.failed;
							if ( res && ! res.success && res.data && res.data.codes && res.data.codes.length ) {
								message += ' (' + res.data.codes.join( ', ' ) + ')';
							}
							say( message, res && res.success ? 'ok' : 'error' );
						} ).catch( function () {
							say( data.i18n.failed, 'error' );
						} );
					},
					'error-callback': function ( code ) {
						say( clientError( code ), 'error' );
						return true;
					}
				} );
			} ).catch( function () {
				say( data.i18n.noLoad, 'error' );
			} );
		} );
	}

	// Delete all taps ----------------------------------------------------------------------

	function initReset() {
		var button = document.querySelector( '[data-tuw-reset]' );
		var result = document.querySelector( '[data-tuw-reset-result]' );

		if ( ! button || ! result ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( data.i18n.confirm ) ) {
				return;
			}

			button.disabled = true;
			post( { action: 'text_us_widget_reset_stats' } ).then( function ( res ) {
				result.textContent = res && res.success ? data.i18n.reset : data.i18n.failed;
				result.className = 'tuw-test-result ' + ( res && res.success ? 'is-ok' : 'is-error' );
			} ).catch( function () {
				result.textContent = data.i18n.failed;
				result.className = 'tuw-test-result is-error';
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}
}() );
