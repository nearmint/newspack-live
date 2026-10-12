/* eslint-disable @typescript-eslint/no-explicit-any -- prop stand-ins of mocked components. */
/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import * as api from '../api';
import { config } from '../config';
import type { CustomLabel } from '../types';
import LabelControl from './label-control';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
// @wordpress/components doesn't load under this Jest setup (ESM dependencies): native stand-ins with
// the same props. The real controls' ARIA wiring is checked in the e2e spec (labels.spec.ts).
jest.mock( '@wordpress/components', () => {
	const { forwardRef } = jest.requireActual( 'react' );
	return {
		SelectControl: forwardRef( ( { label, value, options, onChange, disabled, help }: any, ref: any ) => (
			<>
				<label htmlFor="select-control">{ label }</label>
				<select id="select-control" ref={ ref } value={ value } disabled={ disabled } onChange={ event => onChange( event.target.value ) }>
					{ options.map( ( option: { value: string; label: string } ) => (
						<option key={ option.value } value={ option.value }>
							{ option.label }
						</option>
					) ) }
				</select>
				{ help && <span>{ help }</span> }
			</>
		) ),
		TextControl: ( { label, value, onChange, help, onKeyDown, ...rest }: any ) => (
			<>
				<label htmlFor="text-control">{ label }</label>
				<input
					id="text-control"
					value={ value }
					aria-describedby="text-control__help"
					aria-invalid={ rest[ 'aria-invalid' ] }
					onChange={ event => onChange( event.target.value ) }
					onKeyDown={ onKeyDown }
				/>
				<p id="text-control__help">{ help }</p>
			</>
		),
		Button: ( { children, onClick, disabled }: any ) => (
			<button type="button" onClick={ onClick } disabled={ disabled }>
				{ children }
			</button>
		),
	};
} );
jest.mock( '../api', () => ( { createLabel: jest.fn(), deleteLabel: jest.fn() } ) );

const custom: CustomLabel[] = [
	{ id: 'ab12cd34', value: 'custom:ab12cd34', text: 'Turnout', used: true },
	{ id: 'zz99zz99', value: 'custom:zz99zz99', text: 'Weather', used: false },
];

beforeAll( () => {
	config.labels = {
		presets: [
			{ value: 'breaking', name: 'Breaking' },
			{ value: 'photos', name: 'Photos' },
			{ value: 'video', name: 'Video' },
			{ value: 'key-point', name: 'Key point' },
		],
		taken: [ 'breaking', 'photos', 'video', 'key point', 'your questions' ],
		your_questions: 'Your questions',
		max_length: 24,
	};
} );

const setup = ( props: Partial< Parameters< typeof LabelControl >[ 0 ] > = {} ) => {
	const onChange = jest.fn();
	const onLabelsChange = jest.fn();
	render(
		<LabelControl
			liveId={ 5 }
			value=""
			onChange={ onChange }
			custom={ custom }
			onLabelsChange={ onLabelsChange }
			answer={ false }
			canManage
			{ ...props }
		/>
	);
	return { onChange, onLabelsChange };
};

describe( 'LabelControl', () => {
	it( 'is disabled and shows Your questions on answers', () => {
		setup( { answer: true, value: 'your-questions' } );
		const select = screen.getByRole( 'combobox', { name: 'Label' } ) as HTMLSelectElement;
		expect( select.disabled ).toBe( true );
		expect( select.value ).toBe( 'your-questions' );
		expect( screen.getAllByRole( 'option' ).map( option => option.textContent ) ).toEqual( [ 'Your questions' ] );
		expect( screen.queryByRole( 'button', { name: 'Delete label' } ) ).toBeNull();
	} );

	it( 'never offers Your questions on other entries', () => {
		setup();
		expect( screen.getAllByRole( 'option' ).map( option => option.textContent ) ).not.toContain( 'Your questions' );
	} );

	it( 'offers Delete label for an unused custom label only', () => {
		setup( { value: 'custom:zz99zz99' } );
		expect( screen.getByRole( 'button', { name: 'Delete label' } ) ).toBeTruthy();
	} );

	it( 'hides Delete label for a used custom label', () => {
		setup( { value: 'custom:ab12cd34' } );
		expect( screen.queryByRole( 'button', { name: 'Delete label' } ) ).toBeNull();
	} );

	it( 'creates a label from the inline field with Enter, and shows errors on the field', async () => {
		const { onChange, onLabelsChange } = setup();
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Label (optional)' } ), { target: { value: '__create__' } } );
		const input = screen.getByRole( 'textbox', { name: 'New label' } );

		// A duplicate: the error describes the field, nothing is sent.
		fireEvent.change( input, { target: { value: 'turnout' } } );
		await act( async () => {
			fireEvent.keyDown( input, { key: 'Enter' } );
		} );
		expect( api.createLabel ).not.toHaveBeenCalled();
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		const describedBy = input.getAttribute( 'aria-describedby' ) ?? '';
		expect( document.getElementById( describedBy )?.textContent ).toBe( 'This live already has this label.' );

		( api.createLabel as jest.Mock ).mockResolvedValue( {
			label: { id: 'new00000', value: 'custom:new00000', text: 'Recount', used: false },
			labels: [ ...custom, { id: 'new00000', value: 'custom:new00000', text: 'Recount', used: false } ],
		} );
		fireEvent.change( input, { target: { value: 'Recount' } } );
		await act( async () => {
			fireEvent.keyDown( input, { key: 'Enter' } );
		} );
		expect( api.createLabel ).toHaveBeenCalledWith( 5, 'Recount' );
		expect( onLabelsChange ).toHaveBeenCalled();
		expect( onChange ).toHaveBeenCalledWith( 'custom:new00000' );
		expect( screen.queryByRole( 'textbox', { name: 'New label' } ) ).toBeNull();
	} );

	it( 'closes the field with Escape without changing the label', () => {
		const { onChange } = setup( { value: 'breaking' } );
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Label (optional)' } ), { target: { value: '__create__' } } );
		fireEvent.keyDown( screen.getByRole( 'textbox', { name: 'New label' } ), { key: 'Escape' } );
		expect( screen.queryByRole( 'textbox', { name: 'New label' } ) ).toBeNull();
		expect( ( screen.getByRole( 'combobox', { name: 'Label (optional)' } ) as HTMLSelectElement ).value ).toBe( 'breaking' );
		expect( onChange ).not.toHaveBeenCalled();
	} );
} );
