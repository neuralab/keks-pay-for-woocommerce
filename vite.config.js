import { defineConfig } from 'vite';

export default defineConfig( ( { mode } ) => ( {
	// Files in assets/src/static are copied to assets/dist as they are.
	publicDir: 'assets/src/static',
	build: {
		// Support older browsers than Vite's default, checkout pages are visited from older phones too.
		target: [ 'chrome87', 'edge88', 'firefox78', 'safari14' ],
		outDir: 'assets/dist',
		emptyOutDir: true,
		sourcemap: 'development' === mode ? 'inline' : false,
		rolldownOptions: {
			input: {
				kekspay: 'assets/src/js/kekspay.js',
				'kekspay-admin': 'assets/src/js/kekspay-admin.js',
				'kekspay-blocks': 'assets/src/js/kekspay-blocks.js',
				'kekspay-style': 'assets/src/scss/kekspay.scss',
			},
			output: {
				// Fixed file names, cache busting is done by WordPress using plugin version.
				entryFileNames: 'js/[name].js',
				assetFileNames: 'css/kekspay[extname]',
			},
		},
	},
} ) );
