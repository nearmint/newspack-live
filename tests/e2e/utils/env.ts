/**
 * Reads the gitignored .env.e2e file (base URL and e2e user credentials).
 */

/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';

export const PLUGIN_DIR = path.resolve( __dirname, '../../..' );

export type Role = 'admin' | 'editor' | 'author' | 'contributor' | 'subscriber';

export const ROLES: Role[] = [ 'admin', 'editor', 'author', 'contributor', 'subscriber' ];

let cache: Record< string, string > | null = null;

/**
 * Parses .env.e2e once.
 *
 * @return Key/value pairs.
 */
export function readEnv(): Record< string, string > {
	if ( cache ) {
		return cache;
	}
	const file = path.join( PLUGIN_DIR, '.env.e2e' );
	const values: Record< string, string > = {};
	if ( fs.existsSync( file ) ) {
		for ( const line of fs.readFileSync( file, 'utf8' ).split( '\n' ) ) {
			const match = line.match( /^([A-Z0-9_]+)=(.*)$/ );
			if ( match ) {
				values[ match[ 1 ] ] = match[ 2 ].trim();
			}
		}
	}
	cache = values;
	return values;
}

export const BASE_URL = process.env.WP_BASE_URL || readEnv().WP_BASE_URL || 'http://newspack.local';

/**
 * Credentials of an e2e user.
 *
 * @param role Role key.
 * @return Login and password.
 */
export function credentials( role: Role ): { user: string; pass: string } {
	const env = readEnv();
	const key = role.toUpperCase();
	const user = env[ `WP_E2E_${ key }_USER` ];
	const pass = env[ `WP_E2E_${ key }_PASS` ];
	if ( ! user || ! pass ) {
		throw new Error( `Missing WP_E2E_${ key }_USER / _PASS in .env.e2e` );
	}
	return { user, pass };
}

/**
 * Path of the saved storage state of a role.
 *
 * @param role Role key.
 * @return Absolute path.
 */
export function storageStatePath( role: Role ): string {
	return path.join( PLUGIN_DIR, 'tests/e2e/.auth', `${ role }.json` );
}
