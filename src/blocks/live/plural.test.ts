/**
 * Internal dependencies
 */
import { pluralIndex, pluralize } from './plural';

/**
 * Plural table of a rule, as the server builds it (counts 0–199).
 *
 * @param rule Plural rule.
 * @return Table.
 */
const table = ( rule: ( n: number ) => number ) => Array.from( { length: 200 }, ( _, n ) => rule( n ) );

const english = table( n => ( n === 1 ? 0 : 1 ) );
const french = table( n => ( n > 1 ? 1 : 0 ) );
const polish = table( n => {
	if ( n === 1 ) {
		return 0;
	}
	return n % 10 >= 2 && n % 10 <= 4 && ( n % 100 < 10 || n % 100 >= 20 ) ? 1 : 2;
} );
const arabic = table( n => {
	if ( n <= 2 ) {
		return n;
	}
	if ( n % 100 >= 3 && n % 100 <= 10 ) {
		return 3;
	}
	return n % 100 >= 11 ? 4 : 5;
} );

describe( 'plural forms', () => {
	it( 'selects the form of the language rule, including counts beyond the table', () => {
		expect( [ 1, 2, 5 ].map( n => pluralIndex( english, n ) ) ).toEqual( [ 0, 1, 1 ] );
		expect( [ 0, 1, 2 ].map( n => pluralIndex( french, n ) ) ).toEqual( [ 0, 0, 1 ] );
		expect( [ 1, 2, 5, 12, 22, 25, 112, 1022 ].map( n => pluralIndex( polish, n ) ) ).toEqual( [ 0, 1, 2, 2, 1, 2, 2, 1 ] );
		expect( [ 0, 1, 2, 3, 11, 100, 103, 1011 ].map( n => pluralIndex( arabic, n ) ) ).toEqual( [ 0, 1, 2, 3, 4, 5, 3, 4 ] );
	} );

	it( 'fills the count into the selected form, with a safe fallback', () => {
		const forms = [ '%d nowa aktualizacja', '%d nowe aktualizacje', '%d nowych aktualizacji' ];
		expect( pluralize( forms, polish, 22 ) ).toBe( '22 nowe aktualizacje' );
		expect( pluralize( forms, polish, 5 ) ).toBe( '5 nowych aktualizacji' );
		expect( pluralize( [ '%d update', '%d updates' ], arabic, 11 ) ).toBe( '11 updates' );
		expect( pluralize( [], english, 3 ) ).toBe( '3' );
	} );
} );
