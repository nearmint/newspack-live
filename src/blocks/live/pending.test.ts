/**
 * Internal dependencies
 */
import { PENDING_CAP, isCapped, pendingText, titleWithCount } from './pending';

const plural = { forms: [ '%d new update', '%d new updates' ], table: Array.from( { length: 200 }, ( _, n ) => ( n === 1 ? 0 : 1 ) ) };

describe( 'waiting updates', () => {
	it( 'caps at 20', () => {
		expect( PENDING_CAP ).toBe( 20 );
		expect( isCapped( 20 ) ).toBe( false );
		expect( isCapped( 21 ) ).toBe( true );
	} );

	it( 'labels the button with the plural form, then the capped text', () => {
		expect( pendingText( 0, plural, '20+ new updates' ) ).toBe( '' );
		expect( pendingText( 1, plural, '20+ new updates' ) ).toBe( '1 new update' );
		expect( pendingText( 3, plural, '20+ new updates' ) ).toBe( '3 new updates' );
		expect( pendingText( 20, plural, '20+ new updates' ) ).toBe( '20 new updates' );
		expect( pendingText( 21, plural, '20+ new updates' ) ).toBe( '20+ new updates' );
		expect( pendingText( 2, undefined, '20+' ) ).toBe( '2' );
	} );

	it( 'prefixes the tab title with the count', () => {
		expect( titleWithCount( 'Election night', 0 ) ).toBe( 'Election night' );
		expect( titleWithCount( 'Election night', 3 ) ).toBe( '(3) Election night' );
		expect( titleWithCount( 'Election night', 21 ) ).toBe( '(20+) Election night' );
	} );
} );
