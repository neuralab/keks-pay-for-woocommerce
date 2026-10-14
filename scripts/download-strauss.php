<?php
/**
 * Download Strauss phar used for prefixing vendor namespaces, run via "composer prefix-namespaces".
 */

$version = '0.19.4';
$target  = dirname( __DIR__ ) . '/bin/strauss.phar';

if ( file_exists( $target ) ) {
	exit( 0 );
}

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0755, true );
}

$url = 'https://github.com/BrianHenryIE/strauss/releases/download/' . $version . '/strauss.phar';

if ( ! copy( $url, $target ) ) {
	fwrite( STDERR, 'Failed to download Strauss from ' . $url . PHP_EOL );
	exit( 1 );
}

echo 'Downloaded Strauss ' . $version . PHP_EOL;
