// Swap visibility of test and live credential fields depending on test mode on/off.
( () => {
	const testMode = document.getElementById( 'woocommerce_erste-kekspay-woocommerce_in-test-mode' );

	if ( ! testMode ) {
		return;
	}

	const toggleCredentials = () => {
		document.querySelectorAll( '[id*="webshop"]' ).forEach( ( field ) => {
			const row = field.closest( 'tr' );

			if ( row ) {
				const visible = field.id.includes( 'test-webshop' ) ? testMode.checked : ! testMode.checked;

				row.style.display = visible ? '' : 'none';
			}
		} );
	};

	testMode.addEventListener( 'change', toggleCredentials );
	toggleCredentials();
} )();
