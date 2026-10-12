/**
 * Internal dependencies
 */
import { autosaveKey, greeting, hasBody, readAutosave, validate, writeAutosave } from './composer-utils';

describe( 'composer utils', () => {
	it( 'detects empty and non-empty bodies', () => {
		expect( hasBody( '' ) ).toBe( false );
		expect( hasBody( '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' ) ).toBe( false );
		expect( hasBody( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) ).toBe( true );
		expect( hasBody( '<!-- wp:image --><figure><img src="a.jpg" alt=""/></figure><!-- /wp:image -->' ) ).toBe( true );
		expect( hasBody( '<!-- wp:newspack-live/article-card {"postId":3} /-->' ) ).toBe( true );
	} );

	it( 'validates title/body and future times', () => {
		expect( validate( { title: '', content: '', time: null } ) ).toBe( 'Add a title or some content before publishing.' );
		expect( validate( { title: 'Hi', content: '', time: null } ) ).toBe( '' );
		expect( validate( { title: 'Hi', content: '', time: 1060 }, 1000 ) ).toBe( '' );
		expect( validate( { title: 'Hi', content: '', time: 1061 }, 1000 ) ).toBe( "The time can't be in the future." );
		expect( validate( { title: 'Hi', content: '', time: 10 }, 1000 ) ).toBe( '' );
	} );

	it( 'greets readers by name', () => {
		expect( greeting( [ 'Ana' ] ) ).toBe( 'Hi Ana,' );
		expect( greeting( [ 'Ana', 'Ben' ] ) ).toBe( 'Hi Ana and Ben,' );
		expect( greeting( [ 'Ana', 'Ben', 'Cy', 'Ana' ] ) ).toBe( 'Hi Ana, Ben and Cy,' );
	} );

	it( 'autosaves to localStorage and survives a broken storage', () => {
		const key = autosaveKey( 3, 7 );
		expect( key ).toBe( 'newspack-live:3:7' );
		const draft = { title: 'T', content: '', time: null, pinned: false, editingId: 0, questionIds: [], savedAt: 1 };
		writeAutosave( key, draft );
		expect( readAutosave( key ) ).toEqual( draft );
		writeAutosave( key, null );
		expect( readAutosave( key ) ).toBeNull();

		const spy = jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		expect( readAutosave( key ) ).toBeNull();
		spy.mockRestore();
	} );
} );
