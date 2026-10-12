/**
 * Playwright global setup.
 *
 * - creates or verifies the e2e users
 * - logs each role in through wp-login.php and saves its storage state
 * - enables the NEWSPACK_LIVE_E2E constant
 */

/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { chromium, request, type FullConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import { BASE_URL, ROLES, credentials, storageStatePath, type Role } from './utils/env';
import { wp, wpTry } from './utils/wp-cli';

const WP_ROLES: Record< Role, string > = {
	admin: 'administrator',
	editor: 'editor',
	author: 'author',
	contributor: 'contributor',
	subscriber: 'subscriber',
};

/**
 * Creates the e2e user of a role when missing, and syncs its password with .env.e2e.
 *
 * @param role Role key.
 */
function ensureUser( role: Role ): void {
	const { user, pass } = credentials( role );
	const id = wpTry( 'user', 'get', user, '--field=ID' );
	if ( id ) {
		wp( 'user', 'update', user, `--user_pass=${ pass }`, '--skip-email' );
	} else {
		wp( 'user', 'create', user, `${ user }@example.test`, `--role=${ WP_ROLES[ role ] }`, `--user_pass=${ pass }`, '--porcelain' );
	}
	wp( 'user', 'meta', 'update', user, '_newspack_live_e2e', '1' );
}

export default async function globalSetup( config: FullConfig ): Promise< void > {
	const baseURL = config.projects[ 0 ]?.use?.baseURL || BASE_URL;
	fs.mkdirSync( path.dirname( storageStatePath( 'admin' ) ), { recursive: true } );

	ROLES.forEach( ensureUser );

	const browser = await chromium.launch();
	try {
		for ( const role of ROLES ) {
			const { user, pass } = credentials( role );
			const context = await browser.newContext( { baseURL } );
			const page = await context.newPage();
			// Wait for the login scripts: a click before they settle is sometimes ignored.
			await page.goto( '/wp-login.php', { waitUntil: 'networkidle' } );
			await page.getByLabel( 'Username or Email Address' ).fill( user );
			await page.getByLabel( 'Password', { exact: true } ).fill( pass );
			await Promise.all( [
				page.waitForURL( url => ! url.pathname.endsWith( 'wp-login.php' ) ),
				page.getByRole( 'button', { name: 'Log In' } ).click(),
			] );
			await context.storageState( { path: storageStatePath( role ) } );
			await context.close();
		}
	} finally {
		await browser.close();
	}

	wp( 'config', 'set', 'NEWSPACK_LIVE_E2E', 'true', '--raw' );
	await waitForE2EMode();
}

/**
 * Waits until web requests run with the constant (PHP's OPcache revalidates `wp-config.php` only
 * every few seconds): the E2E class then sends `X-Newspack-Live-E2E`.
 */
async function waitForE2EMode(): Promise< void > {
	const context = await request.newContext( { baseURL: BASE_URL } );
	try {
		for ( let attempt = 0; attempt < 30; attempt++ ) {
			const response = await context.get( '/', { headers: { 'Cache-Control': 'no-cache' } } );
			if ( response.headers()[ 'x-newspack-live-e2e' ] === '1' ) {
				return;
			}
			await new Promise( resolve => setTimeout( resolve, 500 ) );
		}
		throw new Error( 'The site never served the e2e mode (NEWSPACK_LIVE_E2E).' );
	} finally {
		await context.dispose();
	}
}
