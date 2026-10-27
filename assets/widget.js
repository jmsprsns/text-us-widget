/**
 * Text Us Widget: everything that runs in the visitor's browser.
 *
 * Printed inline in the footer (as widget.min.js, built by bin/build.sh)
 * after an inert <template id="tuw-template"> holding the markup. PHP swaps
 * __TUW_CONFIG__ for the settings and __TUW_CSS__ for widget.min.css.
 *
 * 1. Works out the device. On a device the widget isn't switched on for,
 *    or after the visitor closed it (tuw_hide cookie), it stops here:
 *    nothing is added to the page.
 * 2. "Load on first interaction": waits for a scroll, mouse move, tap or
 *    key press before adding anything, so speed tests (which never
 *    interact) don't see the widget. Once it has loaded, the tuw_seen
 *    cookie makes later page views load it straight away.
 * 3. Adds the styles and the markup, then slides the launcher in after the
 *    first scroll or the delay (straight away on later page views).
 * 4. With Turnstile, the number isn't in the page: the panel runs the check
 *    and asks the server for it with the token.
 *
 * Config keys: u hit URL, r reveal URL (Turnstile), k site key, n number
 * (no Turnstile), s number as shown, m pre-filled message, dv devices,
 * i icons {a: Apple, g: Android, o: other}, p page ID, w delay in seconds,
 * h days hidden after closing, ia load on interaction, x skip the panel,
 * th Turnstile theme, ga send events to Google Analytics, l wording.
 */

( function () {
	'use strict';

	var C = __TUW_CONFIG__;
	var CSS = __TUW_CSS__;
	var doc = document;
	var html = doc.documentElement;
	var nav = navigator;
	var tpl = doc.getElementById( 'tuw-template' );

	if ( ! C || ! tpl || ! tpl.content || window.textUsWidget ) {
		return;
	}

	// 1. Device ---------------------------------------------------------------

	function device() {
		var ua = nav.userAgent || '';
		var platform = nav.platform || '';
		var data = nav.userAgentData;
		var touch = nav.maxTouchPoints || 0;
		var mac = /Macintosh|Mac OS X/.test( ua ) || /^Mac/.test( platform );

		if ( /iPhone|iPod/.test( ua ) ) {
			return 'iphone';
		}

		// iPadOS (and iPhones asking for the desktop site) say they're a Mac;
		// a Mac has no touch screen.
		if ( /iPad/.test( ua ) || ( mac && touch > 1 ) ) {
			return Math.min( screen.width, screen.height ) < 600 ? 'iphone' : 'ipad';
		}

		if ( /Android/i.test( ua ) ) {
			return /Mobile/.test( ua ) || ( data && data.mobile ) ? 'android' : 'atab';
		}

		// Before the Mac check: the user agent wins over navigator.platform.
		if ( /Windows/.test( ua ) || ( data && data.platform === 'Windows' ) ) {
			return 'win';
		}

		if ( mac || ( data && data.platform === 'macOS' ) ) {
			return 'mac';
		}

		return 'other';
	}

	var dev = device();

	if ( C.dv.indexOf( dev ) < 0 ) {
		return;
	}

	var os = /^(mac|iphone|ipad)$/.test( dev ) ? 'apple' : ( /^(android|atab)$/.test( dev ) ? 'android' : 'other' );

	function cookie( name ) {
		var match = doc.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
		return match ? match[ 1 ] : '';
	}

	function setCookie( name, days ) {
		doc.cookie = name + '=1; path=/' + ( days ? '; max-age=' + Math.round( days * 86400 ) : '' ) +
			'; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	// Closed recently: stay out of the way.
	if ( cookie( 'tuw_hide' ) ) {
		return;
	}

	var seen = cookie( 'tuw_seen' ) === '1';

	// 2. Wait for the first interaction -----------------------------------------

	var EVENTS = [ 'scroll', 'wheel', 'mousemove', 'mousedown', 'pointerdown', 'touchstart', 'keydown' ];
	var listen = { passive: true, capture: true };

	if ( C.ia && ! seen ) {
		var onFirst = function () {
			EVENTS.forEach( function ( type ) {
				window.removeEventListener( type, onFirst, listen );
			} );
			mount( false );
		};

		EVENTS.forEach( function ( type ) {
			window.addEventListener( type, onFirst, listen );
		} );
	} else {
		mount( seen );
	}

	// 3. Add the widget -------------------------------------------------------------

	function mount( instant ) {
		if ( window.textUsWidget ) {
			return;
		}

		window.textUsWidget = { open: function () {} };
		setCookie( 'tuw_seen', 30 );

		var style = doc.createElement( 'style' );
		style.id = 'text-us-widget-css';
		style.textContent = CSS;
		doc.head.appendChild( style );

		doc.body.appendChild( tpl.content.cloneNode( true ) );

		var root = doc.getElementById( 'tuw' );

		if ( root ) {
			init( root, instant );
		}
	}

	function init( root, instant ) {
		var bar = root.querySelector( '.tuw-bar-main' );
		var sheet = root.querySelector( '.tuw-sheet' );
		var cta = root.querySelector( '.tuw-cta' );
		var numberEl = root.querySelector( '.tuw-number' );
		var tsEl = root.querySelector( '.tuw-turnstile' );
		var statusEl = root.querySelector( '.tuw-status' );
		var narrow = window.matchMedia( '(max-width: 767px)' );
		var lastOpener = null;
		var widgetId = null;
		var scriptPromise = null;
		var failures = 0;
		var L = C.l || {};

		root.classList.add( 'tuw-on', 'tuw-os-' + os, 'tuw-dev-' + dev );

		// Icons go in now rather than in the template, so lazy-load plugins
		// can't rewrite them into images that never load.
		var icon = C.i[ os === 'apple' ? 'a' : ( os === 'android' ? 'g' : 'o' ) ];
		Array.prototype.forEach.call( root.querySelectorAll( '[data-tuw-icon]' ), function ( el ) {
			var img = doc.createElement( 'img' );
			img.src = icon;
			img.alt = '';
			img.width = img.height = 64;
			img.decoding = 'async';
			el.appendChild( img );
		} );

		function layout() {
			root.classList.toggle( 'tuw-phone', narrow.matches );
			root.classList.toggle( 'tuw-wide', ! narrow.matches );
		}

		function relayout() {
			layout();
			tuck();
		}

		if ( narrow.addEventListener ) {
			narrow.addEventListener( 'change', relayout );
		} else if ( narrow.addListener ) {
			narrow.addListener( relayout );
		}

		// Without Turnstile the number is in the config: the link is ready.
		if ( C.n ) {
			unlock( C.n, C.s );
		}

		// Counting -------------------------------------------------------------

		function hit( event ) {
			var body = JSON.stringify( { e: event, d: dev, p: C.p } );

			try {
				if ( nav.sendBeacon && nav.sendBeacon( C.u, new Blob( [ body ], { type: 'text/plain' } ) ) ) {
					return;
				}
			} catch ( e ) {}

			try {
				fetch( C.u, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } } );
			} catch ( e ) {}
		}

		function announce( event ) {
			var name = { o: 'open', t: 'text', d: 'text' }[ event ];

			try {
				doc.dispatchEvent( new CustomEvent( 'text-us-widget', { detail: { action: name, device: dev } } ) );
			} catch ( e ) {}

			if ( C.ga ) {
				var params = { device_type: dev };
				if ( typeof window.gtag === 'function' ) {
					window.gtag( 'event', 'text_us_' + name, params );
				} else if ( window.dataLayer && window.dataLayer.push ) {
					window.dataLayer.push( { event: 'text_us_' + name, device_type: dev } );
				}
			}
		}

		function track( event ) {
			hit( event );
			announce( event );
		}

		// The link -----------------------------------------------------------------

		// iPhone, iPad and Mac take the message after "&body=", others after "?body=".
		function smsHref( number ) {
			var href = 'sms:' + number;
			if ( C.m ) {
				href += ( os === 'apple' ? '&' : '?' ) + 'body=' + encodeURIComponent( C.m );
			}
			return href;
		}

		function unlock( number, shown ) {
			cta.href = smsHref( number );
			root.classList.add( 'tuw-verified' );
			if ( numberEl && shown ) {
				numberEl.textContent = shown;
				numberEl.hidden = false;
			}
		}

		// 4. Turnstile ----------------------------------------------------------------

		function setStatus( text, type ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.className = 'tuw-status' + ( type === 'error' ? ' tuw-status-error' : '' );
			statusEl.textContent = '';
			if ( type === 'busy' ) {
				var spin = doc.createElement( 'span' );
				spin.className = 'tuw-spinner';
				spin.setAttribute( 'aria-hidden', 'true' );
				statusEl.appendChild( spin );
			}
			statusEl.appendChild( doc.createTextNode( text || '' ) );
		}

		function loadTurnstile() {
			if ( window.turnstile ) {
				return Promise.resolve( window.turnstile );
			}
			if ( scriptPromise ) {
				return scriptPromise;
			}
			scriptPromise = new Promise( function ( resolve, reject ) {
				window.tuwTurnstileReady = function () {
					resolve( window.turnstile );
				};
				var s = doc.createElement( 'script' );
				s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=tuwTurnstileReady';
				s.async = true;
				s.onerror = function () {
					scriptPromise = null;
					reject();
				};
				doc.head.appendChild( s );
			} );
			return scriptPromise;
		}

		function removeCheck() {
			if ( window.turnstile && widgetId !== null ) {
				window.turnstile.remove( widgetId );
			}
			widgetId = null;
		}

		function retry( message ) {
			setStatus( message, 'error' );
			if ( window.turnstile && widgetId !== null ) {
				window.turnstile.reset( widgetId );
			}
		}

		// Turnstile solves again by itself after a reset, so a server that
		// keeps refusing would loop forever: stop after two refusals.
		function fail( message, codes ) {
			failures++;
			if ( failures < 2 ) {
				retry( message );
				return;
			}
			removeCheck();
			setStatus( L.blocked, 'error' );
			if ( codes && codes.length ) {
				var small = doc.createElement( 'small' );
				small.textContent = L.code + ' ' + codes.join( ', ' );
				statusEl.appendChild( small );
			}
		}

		function onToken( token ) {
			setStatus( L.verifying, 'busy' );
			fetch( C.r, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { token: token, d: dev, p: C.p } )
			} )
				.then( function ( r ) {
					return r.json().catch( function () {
						return {};
					} );
				} )
				.then( function ( res ) {
					if ( res && typeof res.n === 'string' && /^\+?[0-9]{3,15}$/.test( res.n ) ) {
						removeCheck();
						setStatus( '' );
						root.classList.add( 'tuw-checked' );
						unlock( res.n, typeof res.s === 'string' ? res.s : '' );
						cta.focus( { preventScroll: true } );
					} else {
						fail( L.failed, res && res.codes );
					}
				} )
				.catch( function () {
					fail( L.error );
				} );
		}

		function renderCheck() {
			if ( ! C.r || widgetId !== null || root.classList.contains( 'tuw-verified' ) || failures >= 2 ) {
				return;
			}
			setStatus( L.loading, 'busy' );
			loadTurnstile().then( function ( ts ) {
				if ( widgetId !== null || ! root.classList.contains( 'tuw-open' ) ) {
					setStatus( '' );
					return;
				}
				setStatus( '' );
				widgetId = ts.render( tsEl, {
					sitekey: C.k,
					action: 'text_us_widget',
					theme: C.th,
					size: tsEl.clientWidth < 300 ? 'compact' : 'flexible',
					callback: onToken,
					'error-callback': function () {
						setStatus( L.snag, 'error' );
					},
					'expired-callback': function () {
						retry( L.expired );
					},
					'timeout-callback': function () {
						retry( L.timeout );
					}
				} );
			} ).catch( function () {
				setStatus( L.noload, 'error' );
			} );
		}

		// Open, close, dismiss ------------------------------------------------------------

		function open() {
			root.classList.add( 'tuw-open' );
			if ( narrow.matches ) {
				html.classList.add( 'tuw-lock' );
			}
			sheet.setAttribute( 'aria-hidden', 'false' );
			bar.setAttribute( 'aria-expanded', 'true' );
			sheet.focus( { preventScroll: true } );
			renderCheck();
		}

		function close() {
			if ( ! root.classList.contains( 'tuw-open' ) ) {
				return;
			}
			root.classList.remove( 'tuw-open' );
			html.classList.remove( 'tuw-lock' );
			sheet.setAttribute( 'aria-hidden', 'true' );
			bar.setAttribute( 'aria-expanded', 'false' );
			// Back to whatever opened it: the launcher, or a link elsewhere.
			var back = lastOpener && ( ! bar.offsetParent || ! root.classList.contains( 'tuw-shown' ) ) ? lastOpener : bar;
			back.focus( { preventScroll: true } );
			lastOpener = null;
		}

		function dismiss() {
			close();
			root.classList.add( 'tuw-dismissed' );
			setCookie( 'tuw_hide', C.h );
		}

		function activate() {
			// No Turnstile and "skip the panel": straight to Messages.
			if ( C.x && C.n ) {
				track( 'd' );
				window.location.href = smsHref( C.n );
				return;
			}
			track( 'o' );
			open();
		}

		window.textUsWidget = { open: activate, close: close };

		root.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t.closest( '[data-tuw-dismiss]' ) ) {
				dismiss();
			} else if ( t.closest( '[data-tuw-close]' ) ) {
				close();
			} else if ( t.closest( '[data-tuw-open]' ) ) {
				activate();
			} else if ( t.closest( '.tuw-cta' ) ) {
				if ( cta.getAttribute( 'href' ) === '#' ) {
					e.preventDefault();
				} else {
					track( 't' );
				}
			}
		} );

		// Your own buttons and menu links: href="#text-us" or data-tuw-open.
		doc.addEventListener( 'click', function ( e ) {
			var opener = e.target.closest && e.target.closest( '[data-tuw-open], a[href$="#text-us"]' );
			if ( opener && ! root.contains( opener ) ) {
				e.preventDefault();
				lastOpener = opener;
				activate();
			}
		} );

		doc.addEventListener( 'keydown', function ( e ) {
			if ( ! root.classList.contains( 'tuw-open' ) ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				close();
			} else if ( e.key === 'Tab' ) {
				// Keep focus inside the panel while it's open.
				var items = Array.prototype.filter.call(
					sheet.querySelectorAll( 'button, a[href], iframe, [tabindex]:not([tabindex="-1"])' ),
					function ( el ) {
						return el.offsetParent !== null;
					}
				);
				if ( ! items.length ) {
					return;
				}
				var first = items[ 0 ];
				var last = items[ items.length - 1 ];
				if ( e.shiftKey && ( doc.activeElement === first || doc.activeElement === sheet ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && doc.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		// Swipe the sheet down to close it (phones; the wide panel isn't a sheet).
		var startY = null;
		var dragY = 0;
		sheet.addEventListener( 'touchstart', function ( e ) {
			if ( sheet.scrollTop > 0 || ! narrow.matches ) {
				return;
			}
			startY = e.touches[ 0 ].clientY;
			dragY = 0;
			sheet.style.transition = 'none';
		}, { passive: true } );
		sheet.addEventListener( 'touchmove', function ( e ) {
			if ( startY === null ) {
				return;
			}
			dragY = Math.max( 0, e.touches[ 0 ].clientY - startY );
			sheet.style.transform = 'translateY(' + dragY + 'px)';
		}, { passive: true } );
		sheet.addEventListener( 'touchend', function () {
			if ( startY === null ) {
				return;
			}
			startY = null;
			sheet.style.transition = '';
			sheet.style.transform = '';
			if ( dragY > 90 ) {
				close();
			}
		} );

		// Phones: at the very bottom of the page the bar steps aside, so it
		// never covers the footer.
		var tuckQueued = false;
		function tuck() {
			tuckQueued = false;
			if ( ! narrow.matches && ! root.classList.contains( 'tuw-tucked' ) ) {
				return;
			}
			var room = html.scrollHeight - window.innerHeight;
			var tucked = narrow.matches && room > 200 && window.pageYOffset >= room - 60;
			root.classList.toggle( 'tuw-tucked', tucked );
		}
		window.addEventListener( 'scroll', function () {
			if ( ! tuckQueued ) {
				tuckQueued = true;
				window.requestAnimationFrame( tuck );
			}
		}, { passive: true } );

		layout();

		// Show the launcher ---------------------------------------------------------------

		if ( instant ) {
			root.classList.add( 'tuw-instant', 'tuw-shown' );
			// Restore the transitions once the first frame has painted.
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					root.classList.remove( 'tuw-instant' );
				} );
			} );
			return;
		}

		var timer = null;
		function show() {
			clearTimeout( timer );
			window.removeEventListener( 'scroll', onScroll );
			// A frame later, so the slide-in plays.
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					root.classList.add( 'tuw-shown' );
				} );
			} );
		}
		function onScroll() {
			if ( window.pageYOffset > 150 ) {
				show();
			}
		}

		if ( ! C.w ) {
			show();
			return;
		}

		timer = setTimeout( show, C.w * 1000 );
		window.addEventListener( 'scroll', onScroll, { passive: true } );

		// Already scrolled down (a reload mid-page)? Checked once the page has
		// loaded: reading the scroll position while it's still parsing forces
		// a layout.
		if ( doc.readyState === 'complete' ) {
			onScroll();
		} else {
			window.addEventListener( 'load', onScroll, { once: true } );
		}
	}
}() );
