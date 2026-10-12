/**
 * Entry label select: presets, the live's custom labels, inline creation, deletion of
 * unused custom labels. Locked to "Your questions" on answers.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import * as api from '../api';
import { config } from '../config';
import { CREATE, YOUR_QUESTIONS, canDeleteLabel, createCustomLabel, labelOptions, selectValue } from '../labels';
import type { CustomLabel } from '../types';

interface Props {
	liveId: number;
	value: string;
	onChange: ( value: string ) => void;
	/** The live's custom labels. */
	custom: CustomLabel[];
	/** Called with the live's labels after a creation or deletion. */
	onLabelsChange: ( labels: CustomLabel[] ) => void;
	/** Whether the entry answers reader questions (label locked). */
	answer: boolean;
	/** Whether the user can create and delete custom labels (publishing right). */
	canManage: boolean;
}

/**
 * Label control.
 *
 * @param props Props.
 * @return Element.
 */
export default function LabelControl( props: Props ) {
	const { liveId, value, onChange, custom, onLabelsChange, answer, canManage } = props;
	const [ creating, setCreating ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const selectRef = useRef< HTMLSelectElement >( null );
	const inputRef = useRef< HTMLDivElement >( null );
	const focusSelect = () => window.setTimeout( () => selectRef.current?.focus(), 0 );

	// A new entry or an answer closes the creation field.
	useEffect( () => {
		if ( answer ) {
			setCreating( false );
		}
	}, [ answer ] );

	if ( answer ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className="newspack-live-composer__label"
				label={ __( 'Label', 'newspack-live' ) }
				value={ YOUR_QUESTIONS }
				options={ [ { value: YOUR_QUESTIONS, label: config.labels.your_questions } ] }
				onChange={ () => undefined }
				disabled
				help={ __( 'Set automatically on answers to reader questions.', 'newspack-live' ) }
			/>
		);
	}

	const options = labelOptions( config.labels, custom, canManage, value );

	const cancel = () => {
		setCreating( false );
		setText( '' );
		setError( '' );
		focusSelect();
	};

	const submit = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		const result = await createCustomLabel( text, config.labels, custom, clean => api.createLabel( liveId, clean ) );
		setBusy( false );
		if ( ! result.ok ) {
			setError( result.error );
			speak( result.error, 'assertive' );
			inputRef.current?.querySelector( 'input' )?.focus();
			return;
		}
		onLabelsChange( result.labels );
		onChange( result.value );
		setCreating( false );
		setText( '' );
		setError( '' );
		speak( __( 'Label created and selected.', 'newspack-live' ) );
		focusSelect();
	};

	const remove = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		try {
			const response = await api.deleteLabel( liveId, value.replace( /^custom:/, '' ) );
			onLabelsChange( response.labels );
			onChange( '' );
			speak( __( 'Label deleted.', 'newspack-live' ) );
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? __( 'Something went wrong. Please try again.', 'newspack-live' );
			speak( message, 'assertive' );
			setError( message );
		} finally {
			setBusy( false );
			focusSelect();
		}
	};

	return (
		<div className="newspack-live-composer__label">
			<div className="newspack-live-composer__label-row">
				<SelectControl
					ref={ selectRef }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Label (optional)', 'newspack-live' ) }
					value={ creating ? CREATE : selectValue( value, options ) }
					options={ options }
					onChange={ next => {
						setError( '' );
						if ( next === CREATE ) {
							// The field shows right after the select; the focus stays put, so arrowing through
							// the options never jumps away.
							setCreating( true );
							return;
						}
						setCreating( false );
						onChange( next );
					} }
				/>
				{ ! creating && canManage && canDeleteLabel( value, custom ) && (
					<Button variant="link" isDestructive onClick={ remove } disabled={ busy } __next40pxDefaultSize>
						{ __( 'Delete label', 'newspack-live' ) }
					</Button>
				) }
			</div>
			{ ! creating && error && (
				// Announced by speak() when it occurs.
				<p className="newspack-live-composer__label-error">{ error }</p>
			) }
			{ creating && (
				<div
					className="newspack-live-composer__label-create"
					role="group"
					aria-label={ __( 'Create a custom label', 'newspack-live' ) }
					ref={ inputRef }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className={ error ? 'has-error' : undefined }
						label={ __( 'New label', 'newspack-live' ) }
						value={ text }
						aria-invalid={ error ? true : undefined }
						// The error replaces the hint, so the field's description announces it.
						help={
							error ||
							sprintf(
								/* translators: %d: maximum number of characters. */
								__( 'Up to %d characters. Available to every entry of this live.', 'newspack-live' ),
								config.labels.max_length
							)
						}
						onChange={ next => {
							setText( next );
							setError( '' );
						} }
						onKeyDown={ ( event: { key: string; preventDefault: () => void } ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								submit();
							} else if ( event.key === 'Escape' ) {
								event.preventDefault();
								cancel();
							}
						} }
					/>
					<div className="newspack-live-composer__label-actions">
						<Button variant="secondary" onClick={ submit } isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
							{ __( 'Add label', 'newspack-live' ) }
						</Button>
						<Button variant="tertiary" onClick={ cancel } __next40pxDefaultSize>
							{ __( 'Cancel', 'newspack-live' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
