#!/usr/bin/env node
/**
 * Budget check: gzip sizes of the plugin's front assets on a real live page (view module ≤ 30 KB,
 * CSS ≤ 12 KB, and no more than the baseline); no plugin script or stylesheet on any other page
 * (single post, archive, homepage with live teasers: the badges are self-styled). Creates a
 * temporary e2e live and deletes it afterwards.
 *
 * Usage: node bin/check-budgets.mjs
 */

/**
 * External dependencies
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const KB = 1024;
const BUDGETS = { js: 30 * KB, css: 12 * KB };
// Baseline gzip sizes (level 9) of the view module, and of the live and article card stylesheets: they may not grow.
const BASELINE = { js: 8185, css: 3523 + 409 };

const wp = ( ...cli ) => execFileSync( path.join( ROOT, 'bin/wp.local.sh' ), cli, { encoding: 'utf8' } ).trim();
const gzipSize = file => gzipSync( readFileSync( path.join( ROOT, file ) ), { level: 9 } ).length;
const kb = bytes => `${ ( bytes / KB ).toFixed( 1 ) } KB`;

/**
 * Plugin asset URLs (scripts, modules, stylesheets) referenced by a page.
 *
 * @param {string} html Page HTML.
 * @return {{scripts: string[], styles: string[]}} Plugin-relative paths.
 */
function pluginAssets( html ) {
	const urls = [ ...html.matchAll( /(?:src|href)=["']([^"']*\/plugins\/newspack-live\/[^"'?]+)/g ) ].map( match =>
		match[ 1 ].replace( /^.*\/plugins\/newspack-live\//, '' )
	);
	// Script modules may also be listed in the import map.
	const importMap = html.match( /<script type="importmap"[^>]*>([\s\S]*?)<\/script>/ );
	if ( importMap ) {
		urls.push( ...[ ...importMap[ 1 ].matchAll( /\/plugins\/newspack-live\/([^"?]+)/g ) ].map( match => match[ 1 ] ) );
	}
	const unique = [ ...new Set( urls ) ];
	// WordPress inlines small block stylesheets: `<style id="{handle}-inline-css">`.
	const inline = [ ...html.matchAll( /<style id=["'](newspack-live[^"']*)-inline-css["'][^>]*>([\s\S]*?)<\/style>/g ) ].map( match => ( {
		handle: match[ 1 ],
		css: match[ 2 ],
	} ) );
	return { scripts: unique.filter( url => url.endsWith( '.js' ) ), styles: unique.filter( url => url.endsWith( '.css' ) ), inline };
}

const failures = [];
const check = ( label, size, budget ) => {
	const ok = size <= budget;
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ label }: ${ kb( size ) } gzip (budget ${ kb( budget ) })` );
	if ( ! ok ) {
		failures.push( label );
	}
};

/**
 * Checks that a page loads no plugin script and no plugin stylesheet.
 *
 * @param {string}  label       Label.
 * @param {string}  url         Page URL.
 * @param {boolean} expectBadge Whether the page must show a (self-styled) live badge.
 * @return {Promise<void>}
 */
async function checkNonLivePage( label, url, expectBadge ) {
	const html = await ( await fetch( url ) ).text();
	const assets = pluginAssets( html );
	const extra = [ ...assets.scripts, ...assets.styles, ...assets.inline.map( style => `${ style.handle } (inline)` ) ];
	const badgeOk = ! expectBadge || /newspack-live-badge--live"[^>]* style="/.test( html );
	const ok = ! extra.length && badgeOk;
	console.log(
		`${ ok ? 'ok  ' : 'FAIL' } ${ label } (${ url }): ${ ok ? `no plugin script or stylesheet${ expectBadge ? ', self-styled badge' : '' }` : [ ...extra, badgeOk ? '' : 'badge missing' ].filter( Boolean ).join( ', ' ) }`
	);
	if ( ! ok ) {
		failures.push( `${ label } loads plugin assets` );
	}
}

// Only the content created here is deleted: a Playwright run may be using other e2e fixtures.
const deleteLive = id => {
	const children = wp( 'post', 'list', '--post_type=newspack_live_entry', `--post_parent=${ id }`, '--post_status=any', '--format=ids' );
	wp( 'post', 'delete', id, ...children.split( /\s+/ ).filter( Boolean ), '--force' );
};

const story = wp(
	'eval',
	"$ids = get_posts( [ 'post_type' => 'post', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_newspack_live_enabled', 'compare' => 'NOT EXISTS' ] ] ] ); echo $ids ? $ids[0] : '';"
);
if ( ! story ) {
	throw new Error( 'No published non-live post found.' );
}
let teaserPage = '';
const live = wp( 'newspack-live', 'create-live', '--e2e', '--porcelain', '--title=Budget check', '--state=live', '--category=politics' );
try {
	wp( 'newspack-live', 'add-entry', live, '--title=First update', '--pinned' );
	// Worst case: every front stylesheet, including the article card's.
	wp( 'newspack-live', 'add-entry', live, '--title=Read more', `--content=<!-- wp:newspack-live/article-card {"postId":${ story }} /-->` );
	const liveUrl = wp( 'post', 'list', '--post_type=post', `--post__in=${ live }`, '--post_status=publish', '--field=url' );
	const onLive = pluginAssets( await ( await fetch( liveUrl ) ).text() );
	if ( ! onLive.scripts.some( url => url.endsWith( 'build/blocks/live/view.js' ) ) ) {
		failures.push( 'view module not found on the live page' );
	}
	const measuredLive =
		onLive.styles.some( file => file.startsWith( 'build/blocks/live/' ) ) ||
		onLive.inline.some( style => style.handle === 'newspack-live-live-style' );
	const measuredCard =
		onLive.styles.some( file => file.startsWith( 'build/blocks/article-card/' ) ) ||
		onLive.inline.some( style => style.handle === 'newspack-live-article-card-style' );
	if ( ! measuredLive || ! measuredCard ) {
		failures.push( 'live or article card stylesheet not found on the live page (nothing measured)' );
	}
	const js = onLive.scripts.reduce( ( sum, file ) => sum + gzipSize( file ), 0 );
	check( 'JS on a live page', js, BUDGETS.js );
	check( 'JS on a live page, compared with the baseline', js, BASELINE.js );
	// Each inline stylesheet is measured on its own, like the files.
	const css =
		onLive.styles.reduce( ( sum, file ) => sum + gzipSize( file ), 0 ) +
		onLive.inline.reduce( ( sum, style ) => sum + gzipSync( style.css, { level: 9 } ).length, 0 );
	check( 'CSS on a live page', css, BUDGETS.css );
	check( 'CSS on a live page, compared with the baseline', css, BASELINE.css );
	console.log(
		`     files: ${ [ ...onLive.scripts, ...onLive.styles, ...onLive.inline.map( style => `${ style.handle } (inline)` ) ].join( ', ' ) }`
	);

	// Archive (the live is listed with its badge) and a page with a live teaser (created with the e2e marker).
	await checkNonLivePage( 'category archive', wp( 'term', 'get', 'category', 'politics', '--by=slug', '--field=url' ), true );
	teaserPage = wp(
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=Budget teasers',
		'--post_content=<!-- wp:newspack-blocks/homepage-articles {"specificMode":true,"specificPosts":[' + live + ']} /-->',
		'--meta_input={"_newspack_live_e2e":1}',
		'--porcelain'
	);
	await checkNonLivePage( 'page with a live teaser', wp( 'post', 'url', teaserPage ), true );
	await checkNonLivePage( 'non-live post', wp( 'post', 'list', '--post_type=post', `--post__in=${ story }`, '--field=url' ), false );
	await checkNonLivePage( 'homepage', wp( 'option', 'get', 'home' ) + '/', false );
} finally {
	deleteLive( live );
	if ( teaserPage ) {
		wp( 'post', 'delete', teaserPage, '--force' );
	}
}

if ( failures.length ) {
	console.error( `Budget check failed: ${ failures.join( '; ' ) }` );
	process.exit( 1 );
}
console.log( 'Budgets met.' );
