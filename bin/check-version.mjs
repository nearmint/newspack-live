#!/usr/bin/env node
/**
 * Fails when the plugin version differs between the plugin header, NEWSPACK_LIVE_VERSION,
 * uninstall.php and package.json.
 *
 * Usage: node bin/check-version.mjs
 */

/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const read = file => readFileSync( path.join( ROOT, file ), 'utf8' );
const plugin = read( 'newspack-live.php' );

const versions = {
	header: plugin.match( /^\s*\*\s*Version:\s*(\S+)/m )?.[ 1 ],
	constant: plugin.match( /define\(\s*'NEWSPACK_LIVE_VERSION',\s*'([^']+)'/ )?.[ 1 ],
	uninstall: read( 'uninstall.php' ).match( /define\(\s*'NEWSPACK_LIVE_VERSION',\s*'([^']+)'/ )?.[ 1 ],
	'package.json': JSON.parse( read( 'package.json' ) ).version,
};
const unique = new Set( Object.values( versions ) );
if ( unique.size !== 1 || unique.has( undefined ) ) {
	console.error( 'Version mismatch:', versions );
	process.exit( 1 );
}
console.log( `Version ${ [ ...unique ][ 0 ] } everywhere.` );
