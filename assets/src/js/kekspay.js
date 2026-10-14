// Check KEKS Pay payment status periodically and redirect to confirmation page when payment completes.
( () => {
	const config = window.kekspayClientScript;

	if ( ! config || ! config.order_id ) {
		return;
	}

	const refreshRate    = parseInt( config.ipn_refresh, 10 ) || 5000;
	const requestTimeout = 10000;
	const startedAt      = Date.now();
	const body           = new URLSearchParams( {
		order_id: config.order_id,
		order_key: config.order_key,
	} );

	let timer       = null;
	let inFlight    = false;
	let stopped     = false;
	let lastCheckAt = 0;

	const stop = () => {
		stopped = true;
		clearTimeout( timer );
		timer = null;
	};

	// Check every refresh rate (5s by default), slow down after 5min and stop after 1h to prevent infinite requests.
	const schedule = () => {
		const elapsed = Date.now() - startedAt;

		if ( stopped || document.hidden ) {
			return;
		}

		if ( elapsed > 3600000 ) {
			stop();
			return;
		}

		timer = setTimeout( check, elapsed > 300000 ? refreshRate * 3 : refreshRate );
	};

	const check = async () => {
		timer       = null;
		inFlight    = true;
		lastCheckAt = Date.now();

		// Abort hanging requests so polling continues.
		const controller = new AbortController();
		const abortTimer = setTimeout( () => controller.abort(), requestTimeout );

		try {
			const response = await fetch( config.endpoint, { method: 'POST', body, signal: controller.signal } );

			// Invalid order, retrying won't help.
			if ( response.status >= 400 && response.status < 500 ) {
				stop();
				return;
			}

			const data = response.ok ? await response.json() : null;

			if ( data && 'pending' !== data.status ) {
				stop();

				if ( data.redirect ) {
					window.location.assign( data.redirect );
				}

				return;
			}
		} catch ( error ) {
			// Network error, timeout or invalid response, try again with the next check.
		} finally {
			clearTimeout( abortTimer );
			inFlight = false;
		}

		schedule();
	};

	// Check right away instead of waiting for the next scheduled check, customer may return after paying on the phone.
	const checkNow = () => {
		if ( stopped || inFlight || document.hidden ) {
			return;
		}

		clearTimeout( timer );
		timer = null;

		// Avoid flooding when focus changes quickly, just resume regular checks.
		if ( Date.now() - lastCheckAt < 1000 ) {
			schedule();
			return;
		}

		check();
	};

	// Pause checking while the tab is hidden.
	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			clearTimeout( timer );
			timer = null;
		} else {
			checkNow();
		}
	} );

	window.addEventListener( 'focus', checkNow );

	check();
} )();
