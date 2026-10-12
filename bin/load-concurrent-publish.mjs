#!/usr/bin/env node
/**
 * Load check: 10 parallel authenticated clients post 10 entries each to one live through
 * the private API (cookie + REST nonce), then asserts 100 published entries with unique
 * revisions and no errors.
 *
 * Usage: node bin/load-concurrent-publish.mjs [--clients=10] [--entries=10] [--keep]
 */

/**
 * External dependencies
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { request } from '@playwright/test';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const args = Object.fromEntries(
	process.argv.slice( 2 ).map( arg => {
		const [ key, value ] = arg.replace( /^--/, '' ).split( '=' );
		return [ key, value ?? true ];
	} )
);
const CLIENTS = Number( args.clients ?? 10 );
const ENTRIES = Number( args.entries ?? 10 );

const env = Object.fromEntries(
	readFileSync( path.join( ROOT, '.env.e2e' ), 'utf8' )
		.split( '\n' )
		.map( line => line.match( /^([A-Z0-9_]+)=(.*)$/ ) )
		.filter( Boolean )
		.map( match => [ match[ 1 ], match[ 2 ].trim() ] )
);
const BASE = env.WP_BASE_URL;

const wp = ( ...cli ) => execFileSync( path.join( ROOT, 'bin/wp.local.sh' ), cli, { encoding: 'utf8' } ).trim();

/**
 * Logs a user in and returns a request context sending its REST nonce.
 *
 * @param {string} user Login.
 * @param {string} pass Password.
 * @return {Promise<import('@playwright/test').APIRequestContext>} Context.
 */
async function login( user, pass ) {
	const context = await request.newContext( { baseURL: BASE } );
	await context.get( '/wp-login.php' );
	const response = await context.post( '/wp-login.php', {
		form: { log: user, pwd: pass, 'wp-submit': 'Log In', testcookie: '1', redirect_to: `${ BASE }/wp-admin/` },
		headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
		maxRedirects: 0,
	} );
	if ( response.status() !== 302 ) {
		throw new Error( `Login failed for ${ user } (${ response.status() })` );
	}
	const nonce = ( await ( await context.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	const state = await context.storageState();
	await context.dispose();
	return request.newContext( { baseURL: BASE, storageState: state, extraHTTPHeaders: { 'X-WP-Nonce': nonce } } );
}

const live = Number( wp( 'newspack-live', 'create-live', '--title=Load test', '--state=live', '--e2e', '--porcelain' ) );
const authorId = wp( 'user', 'get', env.WP_E2E_AUTHOR_USER, '--field=ID' );
wp( 'newspack-live', 'set-meta', String( live ), '_newspack_live_team', `[${ authorId }]` );
console.log( `Live ${ live }: ${ CLIENTS } clients × ${ ENTRIES } entries` );

const users = [
	[ env.WP_E2E_EDITOR_USER, env.WP_E2E_EDITOR_PASS ],
	[ env.WP_E2E_AUTHOR_USER, env.WP_E2E_AUTHOR_PASS ],
];
const contexts = await Promise.all( Array.from( { length: CLIENTS }, ( _, i ) => login( ...users[ i % users.length ] ) ) );

const errors = [];
const started = Date.now();
await Promise.all(
	contexts.map( async ( context, client ) => {
		for ( let n = 0; n < ENTRIES; n++ ) {
			const response = await context.post( `/wp-json/newspack-live/v1/lives/${ live }/entries`, {
				data: {
					title: `Client ${ client + 1 } entry ${ n + 1 }`,
					content: `<!-- wp:paragraph --><p>Load ${ client }-${ n }</p><!-- /wp:paragraph -->`,
				},
			} );
			if ( response.status() !== 201 ) {
				errors.push( `client ${ client + 1 } entry ${ n + 1 }: ${ response.status() } ${ ( await response.text() ).slice( 0, 120 ) }` );
			}
		}
	} )
);
const seconds = ( ( Date.now() - started ) / 1000 ).toFixed( 1 );
await Promise.all( contexts.map( context => context.dispose() ) );

const report = JSON.parse( wp( 'newspack-live', 'doctor', String( live ), '--format=json' ) );
const ids = wp(
	'post',
	'list',
	'--post_type=newspack_live_entry',
	`--post_parent=${ live }`,
	'--post_status=publish',
	'--posts_per_page=500',
	'--format=ids'
)
	.split( /\s+/ )
	.filter( Boolean );
const revs = new Set( ids.map( id => wp( 'post', 'meta', 'get', id, '_newspack_live_rev' ) ) );
const expected = CLIENTS * ENTRIES;

console.log(
	`Published: ${ ids.length }/${ expected } · unique revisions: ${ revs.size } · live rev: ${ report.rev } · consistent: ${ report.consistent } · errors: ${ errors.length } · ${ seconds } s`
);
errors.slice( 0, 10 ).forEach( error => console.log( `  ${ error }` ) );

if ( ! args.keep ) {
	wp( 'newspack-live', 'clean-e2e' );
}
const ok = ids.length === expected && revs.size === expected && errors.length === 0 && report.consistent === 'yes';
console.log( ok ? 'PASS' : 'FAIL' );
process.exit( ok ? 0 : 1 );
