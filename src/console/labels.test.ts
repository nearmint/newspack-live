/**
 * Internal dependencies
 */
import type { LabelConfig } from './config';
import {
	CREATE,
	YOUR_QUESTIONS,
	canDeleteLabel,
	cleanLabelText,
	createCustomLabel,
	isAnswer,
	labelOptions,
	selectValue,
	validateCustomLabel,
} from './labels';
import type { CustomLabel } from './types';

const config: LabelConfig = {
	presets: [
		{ value: 'breaking', name: 'Breaking' },
		{ value: 'photos', name: 'Photos' },
		{ value: 'video', name: 'Video' },
		{ value: 'key-point', name: 'Key point' },
	],
	// English names and their translations (here French), lowercased by the server.
	taken: [ 'breaking', 'dernière minute', 'photos', 'video', 'vidéo', 'key point', 'your questions', 'vos questions' ],
	your_questions: 'Your questions',
	max_length: 24,
};

const custom: CustomLabel[] = [
	{ id: 'ab12cd34', value: 'custom:ab12cd34', text: 'Turnout', used: true },
	{ id: 'zz99zz99', value: 'custom:zz99zz99', text: 'Weather', used: false },
];

describe( 'entry labels', () => {
	it( 'offers none, the presets, the custom labels, then creation; never Your questions', () => {
		const options = labelOptions( config, custom, true );
		expect( options.map( option => option.value ) ).toEqual( [
			'',
			'breaking',
			'photos',
			'video',
			'key-point',
			'custom:ab12cd34',
			'custom:zz99zz99',
			CREATE,
		] );
		expect( options.map( option => option.label ) ).toEqual( [
			'None',
			'Breaking',
			'Photos',
			'Video',
			'Key point',
			'Turnout',
			'Weather',
			'+ Create custom label…',
		] );
		expect( options.some( option => option.value === YOUR_QUESTIONS ) ).toBe( false );

		// A preset list that would contain it still doesn't offer it.
		const withYours = { ...config, presets: [ ...config.presets, { value: YOUR_QUESTIONS, name: 'Your questions' } ] };
		expect( labelOptions( withYours, [], false ).some( option => option.value === YOUR_QUESTIONS ) ).toBe( false );
	} );

	it( 'offers creation only to users who can create labels', () => {
		expect( labelOptions( config, custom, false ).some( option => option.value === CREATE ) ).toBe( false );
	} );

	it( 'shows a custom value the live does not list as "Unknown label", so it can be changed', () => {
		const options = labelOptions( config, custom, true, 'custom:gone0000' );
		expect( options.slice( -2 ) ).toEqual( [
			{ value: 'custom:gone0000', label: 'Unknown label' },
			{ value: CREATE, label: '+ Create custom label…' },
		] );
		expect( selectValue( 'custom:gone0000', options ) ).toBe( 'custom:gone0000' );
		expect( labelOptions( config, custom, true, 'custom:ab12cd34' ) ).toHaveLength( 8 );
	} );

	it( 'shows unknown values (a deleted custom label) as none', () => {
		const options = labelOptions( config, custom, true );
		expect( selectValue( 'custom:ab12cd34', options ) ).toBe( 'custom:ab12cd34' );
		expect( selectValue( 'custom:gone0000', options ) ).toBe( '' );
		expect( selectValue( CREATE, options ) ).toBe( '' );
		expect( selectValue( YOUR_QUESTIONS, options ) ).toBe( '' );
	} );

	it( 'locks answers', () => {
		expect( isAnswer( [] ) ).toBe( false );
		expect( isAnswer( [ 4 ] ) ).toBe( true );
	} );

	it( 'validates custom label texts', () => {
		expect( cleanLabelText( '  Polls \n  closing  ' ) ).toBe( 'Polls closing' );
		expect( validateCustomLabel( '   ', config, custom ) ).toBe( 'Enter a label.' );
		expect( validateCustomLabel( 'a'.repeat( 25 ), config, custom ) ).toBe( 'Use 24 characters or fewer.' );
		expect( validateCustomLabel( `  ${ 'é'.repeat( 24 ) }  `, config, custom ) ).toBe( '' );
		// Characters, not UTF-16 units: 24 emoji fit.
		expect( validateCustomLabel( '🗳'.repeat( 24 ), config, custom ) ).toBe( '' );
		expect( validateCustomLabel( 'BREAKING', config, custom ) ).toBe( 'This live already has this label.' );
		expect( validateCustomLabel( 'Dernière Minute', config, custom ) ).toBe( 'This live already has this label.' );
		expect( validateCustomLabel( 'your questions', config, custom ) ).toBe( 'This live already has this label.' );
		expect( validateCustomLabel( ' turnout ', config, custom ) ).toBe( 'This live already has this label.' );
		expect( validateCustomLabel( 'Recount', config, custom ) ).toBe( '' );
	} );

	it( 'deletes only unused custom labels', () => {
		expect( canDeleteLabel( 'custom:zz99zz99', custom ) ).toBe( true );
		expect( canDeleteLabel( 'custom:ab12cd34', custom ) ).toBe( false );
		expect( canDeleteLabel( 'breaking', custom ) ).toBe( false );
		expect( canDeleteLabel( '', custom ) ).toBe( false );
	} );

	it( 'creates a label after the local checks, with the cleaned text', async () => {
		const create = jest.fn( async ( text: string ) => ( {
			label: { id: 'new00000', value: 'custom:new00000', text, used: false },
			labels: [ ...custom, { id: 'new00000', value: 'custom:new00000', text, used: false } ],
		} ) );
		const result = await createCustomLabel( '  Recount  ', config, custom, create );
		expect( create ).toHaveBeenCalledWith( 'Recount' );
		expect( result ).toEqual( {
			ok: true,
			value: 'custom:new00000',
			labels: expect.arrayContaining( [ expect.objectContaining( { text: 'Recount' } ) ] ),
		} );
	} );

	it( 'never calls the server for an invalid label', async () => {
		const create = jest.fn();
		expect( await createCustomLabel( 'Turnout', config, custom, create ) ).toEqual( { ok: false, error: 'This live already has this label.' } );
		expect( await createCustomLabel( '', config, custom, create ) ).toEqual( { ok: false, error: 'Enter a label.' } );
		expect( create ).not.toHaveBeenCalled();
	} );

	it( 'returns the server error message', async () => {
		const create = jest.fn( async () => {
			throw { code: 'newspack_live_label_exists', message: 'This live already has this label.' };
		} );
		expect( await createCustomLabel( 'Recount', config, custom, create ) ).toEqual( { ok: false, error: 'This live already has this label.' } );
	} );
} );
