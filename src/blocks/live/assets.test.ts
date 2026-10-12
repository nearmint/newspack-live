/**
 * Internal dependencies
 */
import { loadAssets, type Asset } from './assets';

const ids = () => Array.from( document.head.querySelectorAll( '[id]' ) ).map( element => element.id );

describe( 'loadAssets', () => {
	beforeEach( () => {
		document.head.innerHTML = '';
		// Scripts never load in jsdom: resolve them as loaded.
		jest.spyOn( document.head, 'append' ).mockImplementation( function ( this: HTMLElement, ...nodes ) {
			nodes.forEach( node => {
				HTMLElement.prototype.append.call( this, node );
				if ( node instanceof HTMLScriptElement && node.src ) {
					node.onload?.( new Event( 'load' ) );
				}
			} );
		} );
	} );

	afterEach( () => jest.restoreAllMocks() );

	it( 'adds inline data around scripts and styles, in WordPress print order, once', async () => {
		const assets: Asset[] = [
			{ handle: 'probe-style', type: 'style', src: 'https://example.test/probe.css', after: '.probe{}' },
			{
				handle: 'probe',
				type: 'script',
				src: 'https://example.test/probe.js',
				data: 'var probe = {};',
				before: 'window.a = 1;',
				after: 'window.b = 2;',
			},
			{ handle: 'inline-only', type: 'script', src: '', after: 'window.c = 3;' },
		];
		await loadAssets( assets );
		expect( ids() ).toEqual( [
			'probe-style-css',
			'probe-style-inline-css',
			'probe-js-extra',
			'probe-js-before',
			'probe-js',
			'probe-js-after',
			'inline-only-js-after',
		] );
		expect( document.getElementById( 'probe-js-extra' )?.textContent ).toBe( 'var probe = {};' );
		expect( document.getElementById( 'probe-style-inline-css' )?.textContent ).toBe( '.probe{}' );

		await loadAssets( assets );
		expect( ids() ).toHaveLength( 7 );
	} );

	it( 'skips what the page already printed, but runs inline code that differs', async () => {
		document.head.innerHTML = '<script id="probe-js"></script><script id="probe-js-after">window.b = 2;</script>';
		await loadAssets( [ { handle: 'probe', type: 'script', src: 'https://example.test/probe.js', after: 'window.b = 2;' } ] );
		expect( ids() ).toEqual( [ 'probe-js', 'probe-js-after' ] );

		// Another entry's per-instance initialization: same handle, different code.
		await loadAssets( [ { handle: 'probe', type: 'script', src: 'https://example.test/probe.js', after: 'window.two = 2;' } ] );
		expect( ids() ).toEqual( [ 'probe-js', 'probe-js-after', 'probe-js-after-2' ] );
		await loadAssets( [ { handle: 'probe', type: 'script', src: 'https://example.test/probe.js', after: 'window.two = 2;' } ] );
		expect( ids() ).toHaveLength( 3 );
	} );

	it( 'adds translations between the localized data and the "before" code', async () => {
		await loadAssets( [
			{ handle: 'i18n', type: 'script', src: '', data: 'var a = 1;', translations: 'window.translated = true;', before: 'var b = 2;' },
		] );
		expect( ids() ).toEqual( [ 'i18n-js-extra', 'i18n-js-translations', 'i18n-js-before' ] );
	} );

	it( 'recognizes code WordPress printed with its sourceURL comment or CDATA wrapper', async () => {
		document.head.innerHTML =
			'<script id="probe-js-extra">/* <![CDATA[ */\nvar probe = {};\n/* ]]> */\n//# sourceURL=probe-js-extra</script>' +
			'<script id="probe-js"></script>' +
			'<script id="probe-js-after">\nwindow.b = 2;\n//# sourceURL=probe-js-after</script>' +
			'<style id="probe-style-inline-css">.probe{}\n/*# sourceURL=probe-style-inline-css */</style>';
		await loadAssets( [
			{ handle: 'probe-style', type: 'style', src: '', after: '.probe{}' },
			{ handle: 'probe', type: 'script', src: 'https://example.test/probe.js', data: 'var probe = {};', after: 'window.b = 2;' },
		] );
		expect( ids() ).toEqual( [ 'probe-js-extra', 'probe-js', 'probe-js-after', 'probe-style-inline-css' ] );
	} );

	it( 'ignores non-http sources', async () => {
		await loadAssets( [ { handle: 'bad', type: 'script', src: 'javascript:alert(1)' } ] );
		expect( ids() ).toEqual( [] );
	} );
} );
