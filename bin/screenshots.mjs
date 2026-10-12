#!/usr/bin/env node
/**
 * Release screenshots at 1280 and 375 px on a few themes, saved to the gitignored
 * `artifacts/{version}/`. Always restores newspack-theme.
 *
 * Usage: node bin/screenshots.mjs <version> [name=target ...]
 *   target: a post ID with an optional query string (`46`, `46?live-entry=12`), a public site path
 *   starting with `/` (`/`), `edit:{post ID}` for the post editor (signed in as the e2e admin, the
 *   Context box in view), or `file:{path}` for a local HTML file (shot once, not per theme).
 *   An optional `#mode` after a page target picks what is shot (default: the full page):
 *   `#top` (the first screen), `#scrolled` (a screen further down the page: the sticky aside),
 *   `#card` (the first update's card), `#live-coverage` (the homepage's "Live coverage" section).
 *   Default: `live-46=46`.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const [ version, ...targets ] = process.argv.slice( 2 );
if ( ! version ) {
	console.error( 'Usage: node bin/screenshots.mjs <version> [name=target ...]' );
	process.exit( 1 );
}

const THEMES = [ 'newspack-theme', 'newspack-joseph', 'newspack-block-theme' ];
const DEFAULT_THEME = 'newspack-theme';
const WIDTHS = [ 1280, 375 ];
// Signed-in state saved by the e2e global setup (`npm run test:e2e` once before).
const ADMIN_STATE = path.join( root, 'tests/e2e/.auth/admin.json' );

const wp = ( ...args ) => execFileSync( path.join( root, 'bin/wp.local.sh' ), args, { cwd: root, encoding: 'utf8' } ).trim();

const out = path.join( root, 'artifacts', version );
fs.mkdirSync( out, { recursive: true } );
const home = wp( 'option', 'get', 'home' );
const pages = ( targets.length ? targets : [ 'live-46=46' ] ).map( target => {
	const [ name, rest ] = target.split( '=' );
	const [ spec, mode = 'full' ] = rest.split( '#' );
	if ( spec.startsWith( '/' ) ) {
		return { name, mode, url: home + spec };
	}
	if ( spec.startsWith( 'file:' ) ) {
		return { name, mode, url: 'file://' + path.resolve( root, spec.slice( 5 ) ), file: true };
	}
	if ( spec.startsWith( 'edit:' ) ) {
		return { name, mode: 'editor', url: `${ home }/wp-admin/post.php?post=${ spec.slice( 5 ) }&action=edit`, signedIn: true };
	}
	const [ id, query ] = spec.split( '?' );
	return { name, mode, url: wp( 'post', 'url', id ) + ( query ? `?${ query }` : '' ) };
} );

/**
 * Takes one screenshot.
 *
 * @param {import('@playwright/test').Page} page Page, loaded.
 * @param {string}                          mode What to shoot.
 * @param {string}                          file Output file.
 * @return {Promise<void>}
 */
async function shoot( page, mode, file ) {
	switch ( mode ) {
		case 'top':
			await page.screenshot( { path: file } );
			return;
		case 'scrolled':
			// Past the header and the first updates: the sticky aside stays in view on wide screens.
			await page.evaluate( () => window.scrollTo( 0, Math.min( 2400, document.documentElement.scrollHeight * 0.5 ) ) );
			await page.waitForTimeout( 300 );
			await page.screenshot( { path: file } );
			return;
		case 'card':
			await page.locator( '.newspack-live__entries > .newspack-live-entry' ).first().screenshot( { path: file } );
			return;
		case 'live-coverage': {
			const section = page.locator( '.wp-block-newspack-blocks-homepage-articles' ).filter( { hasText: 'Live coverage' } ).first();
			await section.scrollIntoViewIfNeeded();
			await section.screenshot( { path: file } );
			return;
		}
		case 'editor': {
			const box = page.locator( '#newspack-live-context' );
			await box.waitFor( { state: 'attached', timeout: 30_000 } );
			// The editor's meta box pane remembers per user whether it is collapsed; by keyboard (the
			// resize handle covers the toggle's center).
			if ( ! ( await box.isVisible() ) ) {
				await page.getByRole( 'button', { name: 'Meta Boxes', exact: true } ).focus();
				await page.keyboard.press( 'Enter' );
			}
			await box.waitFor( { state: 'visible', timeout: 30_000 } );
			await box.scrollIntoViewIfNeeded();
			await page.screenshot( { path: file } );
			return;
		}
		default:
			await page.screenshot( { path: file, fullPage: true } );
	}
}

const browser = await chromium.launch();
try {
	for ( const theme of THEMES ) {
		wp( 'theme', 'activate', theme );
		for ( const { name, mode, url, file: isFile, signedIn } of pages ) {
			if ( isFile && theme !== THEMES[ 0 ] ) {
				continue;
			}
			for ( const width of WIDTHS ) {
				const context = await browser.newContext( {
					viewport: { width, height: 900 },
					...( signedIn ? { storageState: ADMIN_STATE } : {} ),
				} );
				const page = await context.newPage();
				await page.goto( url, { waitUntil: 'networkidle' } );
				if ( signedIn ) {
					// The block editor's welcome guide, if it shows.
					await page.keyboard.press( 'Escape' );
				}
				const file = path.join( out, isFile ? `${ name }-${ width }.png` : `${ name }-${ theme }-${ width }.png` );
				await shoot( page, mode, file );
				console.log( path.relative( root, file ) );
				await context.close();
			}
		}
	}
} finally {
	await browser.close();
	wp( 'theme', 'activate', DEFAULT_THEME );
}
