/**
 * Internal dependencies
 */
import { blockItems, contextItems, isEmptyContext, listHtml, type BlockInstance } from './context-html';

describe( 'Context box helpers', () => {
	it( 'stores the bullets as one list, without empty ones', () => {
		expect( listHtml( [ 'Polls close at <strong>8 pm</strong>.', '  ', '<br>', 'Mayor' ] ) ).toBe(
			'<ul><li>Polls close at <strong>8 pm</strong>.</li><li>Mayor</li></ul>'
		);
		expect( listHtml( [ '', '&nbsp;' ] ) ).toBe( '' );
		expect( isEmptyContext( '' ) ).toBe( true );
		expect( isEmptyContext( '<p>Text</p>' ) ).toBe( false );
	} );

	it( 'reads list items, nested ones and older paragraphs as bullets', () => {
		expect( contextItems( '<ul><li>One <em>a</em><ul><li>Nested</li></ul></li><li>Two</li></ul>' ) ).toEqual( [
			'One <em>a</em>',
			'Nested',
			'Two',
		] );
		expect( contextItems( '<p>First</p><p> </p><ol><li>Second</li></ol>' ) ).toEqual( [ 'First', 'Second' ] );
		expect( contextItems( 'Plain &amp; <strong>bold</strong>' ) ).toEqual( [ 'Plain &#38; <strong>bold</strong>' ] );
		expect( contextItems( '' ) ).toEqual( [] );
		// One bullet per item, like the server.
		expect( contextItems( '<ul><li>One item\n\nstill one</li></ul>' ) ).toEqual( [ 'One item\n\nstill one' ] );
	} );

	it( 'takes a bullet from each paragraph block and each list item that got in', () => {
		const blocks = [
			{ name: 'core/paragraph', attributes: { content: 'First' }, innerBlocks: [] },
			{
				name: 'core/list',
				attributes: { ordered: false },
				innerBlocks: [ { name: 'core/list-item', attributes: { content: 'Pasted' }, innerBlocks: [] } ],
			},
		] as unknown as BlockInstance[];
		expect( blockItems( blocks ) ).toEqual( [ 'First', 'Pasted' ] );
	} );
} );
