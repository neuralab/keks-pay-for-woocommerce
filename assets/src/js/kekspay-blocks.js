const { createElement, Fragment } = window.wp.element;
const { decodeEntities }          = window.wp.htmlEntities;

const settings = window.wc.wcSettings.getSetting( kekspayBlocksData.kekspayID + '_data', {} );
const title    = decodeEntities( settings.title || '' );

const Label = () => {
	return createElement(
		'span',
		{ className: 'kekspay-block-label' },
		title,
		settings.icon ? createElement( 'img', { src: settings.icon, alt: '', className: 'kekspay-logo' } ) : null
	);
};

const Content = () => {
	return createElement(
		Fragment,
		null,
		createElement( 'p', null, decodeEntities( settings.description || '' ) ),
		settings.testModeNotice ? createElement( 'p', null, createElement( 'b', null, decodeEntities( settings.testModeNotice ) ) ) : null
	);
};

const Kekspay_Gateway = {
	name: kekspayBlocksData.kekspayID,
	label: createElement( Label, null ),
	content: createElement( Content, null ),
	edit: createElement( Content, null ),
	canMakePayment: () => true,
	ariaLabel: title,
	supports: {
		features: settings.supports,
	},
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Kekspay_Gateway );
