/**
 * Composer: write, backdate, pin and publish entries; edit mode; Q&A mode.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { createBlock, parse, serialize } from '@wordpress/blocks';
import { Button, CheckboxControl, Notice, SelectControl, TextControl } from '@wordpress/components';
import { useKeyboardShortcut } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { BlockInstance } from '../blocks';
import * as api from '../api';
import { config } from '../config';
import { isAnswer } from '../labels';
import { autosaveKey, fromLocalInput, greeting, readAutosave, toLocalInput, validate, writeAutosave, type ComposerDraft } from '../composer-utils';
import { store } from '../store';
import type { EntryInput, EntryStatus } from '../types';
import BodyEditor from './body-editor';
import LabelControl from './label-control';

const AUTOSAVE_INTERVAL = 5000;

interface FormState {
	title: string;
	blocks: BlockInstance[];
	customTime: boolean;
	timeValue: string;
	timeDirty: boolean;
	author: number;
	pinned: boolean;
	/** A preset slug, `custom:<id>` or ''. */
	label: string;
}

const emptyForm = ( author: number ): FormState => ( {
	title: '',
	blocks: [],
	customTime: false,
	timeValue: '',
	timeDirty: false,
	author,
	pinned: false,
	label: '',
} );

/**
 * Composer.
 *
 * @return Element.
 */
export default function Composer() {
	const { live, user, team, editing, answering } = useSelect( select => {
		const console = select( store );
		return {
			live: console.getLive(),
			user: console.getUser(),
			team: console.getTeam(),
			editing: console.getEditing(),
			answering: console.getAnswering(),
		};
	}, [] );
	const { receiveEntry, receiveLive, setEditing, setAnswering, load } = useDispatch( store );
	const [ form, setForm ] = useState< FormState >( () => emptyForm( config.userId ) );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ restorable, setRestorable ] = useState< ComposerDraft | null >( null );
	const firstFocus = useRef< number | null >( null );
	const storageKey = autosaveKey( config.liveId, config.userId );

	const update = ( patch: Partial< FormState > ) => setForm( previous => ( { ...previous, ...patch } ) );

	const reset = useCallback( () => {
		setForm( emptyForm( config.userId ) );
		firstFocus.current = null;
		writeAutosave( storageKey, null );
	}, [ storageKey ] );

	// Offer to restore a local autosave left by a previous session.
	useEffect( () => {
		const saved = readAutosave( storageKey );
		if ( saved && ( saved.title || saved.content ) ) {
			setRestorable( saved );
		}
	}, [ storageKey ] );

	// Load an entry into the composer (edit mode).
	useEffect( () => {
		if ( ! editing ) {
			return;
		}
		setForm( {
			title: editing.title,
			blocks: parse( editing.content ),
			customTime: editing.time > 0,
			timeValue: editing.time ? toLocalInput( editing.time ) : '',
			// The time is only sent when the user changes it (the input has minute precision).
			timeDirty: false,
			author: editing.author.id,
			pinned: editing.pinned,
			label: editing.label,
		} );
	}, [ editing?.id ] );

	// Q&A mode: prefill the greeting with the readers' names.
	useEffect( () => {
		if ( answering.length ) {
			setForm( {
				...emptyForm( config.userId ),
				blocks: [ createBlock( 'core/paragraph', { content: greeting( answering.map( question => question.name ) ) } ) ],
			} );
		}
	}, [ answering.map( question => question.id ).join( ',' ) ] );

	// Local autosave every 5 seconds (best effort).
	const formRef = useRef( form );
	formRef.current = form;
	const editingRef = useRef( editing );
	editingRef.current = editing;
	const answeringRef = useRef( answering.length > 0 );
	answeringRef.current = answering.length > 0;
	const busyRef = useRef( false );
	useEffect( () => {
		const timer = window.setInterval( () => {
			const current = formRef.current;
			const content = serialize( current.blocks );
			// Only new entries are autosaved: edits and answers can't be restored without their context.
			if ( editingRef.current || answeringRef.current || ( ! current.title && ! content ) ) {
				return;
			}
			writeAutosave( storageKey, {
				title: current.title,
				content,
				time: current.timeDirty ? fromLocalInput( current.timeValue ) : null,
				pinned: current.pinned,
				label: current.label,
				editingId: editing?.id ?? 0,
				questionIds: answering.map( question => question.id ),
				savedAt: Date.now(),
			} );
		}, AUTOSAVE_INTERVAL );
		return () => window.clearInterval( timer );
	}, [ storageKey, editing?.id, answering ] );

	const restore = () => {
		if ( ! restorable ) {
			return;
		}
		setForm( {
			...emptyForm( config.userId ),
			title: restorable.title,
			blocks: parse( restorable.content ),
			customTime: restorable.time !== null,
			timeValue: restorable.time ? toLocalInput( restorable.time ) : '',
			timeDirty: restorable.time !== null,
			pinned: restorable.pinned,
			// A custom label deleted since the autosave is dropped (a new entry can't take it).
			label:
				restorable.label && ( ! restorable.label.startsWith( 'custom:' ) || live?.labels?.some( item => item.value === restorable.label ) )
					? restorable.label
					: '',
		} );
		setRestorable( null );
	};

	const submit = async ( status: EntryStatus ) => {
		// A ref, not state: a repeated shortcut can fire before the next render.
		if ( ! live || ! user || busyRef.current ) {
			return;
		}
		const content = serialize( form.blocks );
		const time = form.timeDirty ? fromLocalInput( form.timeValue ) : null;
		const invalid = validate( { title: form.title, content, time } );
		if ( invalid ) {
			setError( invalid );
			speak( invalid, 'assertive' );
			return;
		}
		const data: EntryInput = {
			title: form.title,
			content,
			status,
		};
		if ( time !== null ) {
			data.time = time;
		}
		if ( form.pinned || editing?.pinned ) {
			data.pinned = form.pinned;
		}
		if ( user.can_edit_others && form.author ) {
			data.author = form.author;
		}
		if ( answering.length ) {
			data.question_ids = answering.map( question => question.id );
		} else if ( ! isAnswer( editing?.question_ids ?? [] ) ) {
			// Answers keep "Your questions": their label is never sent.
			data.label = form.label;
		}
		if ( status === 'publish' && firstFocus.current ) {
			data.compose_ms = Math.max( 0, Date.now() - firstFocus.current );
		}
		busyRef.current = true;
		setBusy( true );
		setError( '' );
		try {
			const response = editing ? await api.updateEntry( editing.id, data ) : await api.createEntry( live.id, data );
			receiveEntry( response.entry );
			// The label is in use from now on: no "Delete label" until the next refresh says otherwise.
			if ( data.label && live.labels?.some( item => item.value === data.label && ! item.used ) ) {
				receiveLive( { ...live, labels: live.labels.map( item => ( item.value === data.label ? { ...item, used: true } : item ) ) } );
			}
			const messages: Record< EntryStatus, string > = {
				publish: __( 'Entry published.', 'newspack-live' ),
				pending: __( 'Entry submitted for review.', 'newspack-live' ),
				draft: __( 'Draft saved.', 'newspack-live' ),
			};
			speak( messages[ status ] );
			setEditing( null );
			setAnswering( [] );
			reset();
			if ( data.question_ids ) {
				load( live.id );
			}
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? __( 'Something went wrong. Please try again.', 'newspack-live' );
			setError( message );
			speak( message, 'assertive' );
		} finally {
			busyRef.current = false;
			setBusy( false );
		}
	};

	const primaryStatus: EntryStatus = user?.can_publish ? 'publish' : 'pending';
	useKeyboardShortcut(
		'mod+enter',
		() => {
			// Not while a confirmation dialog is open.
			if ( ! document.querySelector( '.components-modal__frame' ) ) {
				submit( editing?.status === 'publish' ? 'publish' : primaryStatus );
			}
		},
		{ bindGlobal: true }
	);

	if ( ! live || ! user ) {
		return null;
	}

	const isPublished = editing?.status === 'publish';
	let primaryLabel: string = user.can_publish ? __( 'Publish', 'newspack-live' ) : __( 'Submit for review', 'newspack-live' );
	if ( isPublished ) {
		primaryLabel = __( 'Update', 'newspack-live' );
	}
	const authorOptions = team.map( member => ( { value: String( member.id ), label: member.name } ) );

	return (
		<section
			className="newspack-live-composer"
			aria-labelledby="newspack-live-composer-title"
			onFocusCapture={ () => {
				if ( firstFocus.current === null ) {
					firstFocus.current = Date.now();
				}
			} }
		>
			<h2 id="newspack-live-composer-title" className="newspack-live-composer__title">
				{ editing ? __( 'Edit entry', 'newspack-live' ) : __( 'New entry', 'newspack-live' ) }
			</h2>
			{ restorable && ! editing && (
				<Notice status="info" onRemove={ () => setRestorable( null ) } spokenMessage="">
					<Button variant="link" onClick={ restore }>
						{ __( 'Restore unsaved draft', 'newspack-live' ) }
					</Button>
				</Notice>
			) }
			{ !! answering.length && (
				<div className="newspack-live-composer__questions" aria-label={ __( 'Questions being answered', 'newspack-live' ) } role="group">
					{ answering.map( question => (
						<blockquote key={ question.id } className="newspack-live-composer__question">
							<p>{ question.text }</p>
							<cite>{ question.name }</cite>
						</blockquote>
					) ) }
				</div>
			) }
			<div className="newspack-live-composer__row">
				<div className="newspack-live-composer__time">
					{ form.customTime ? (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="datetime-local"
							label={ __( 'Time', 'newspack-live' ) }
							value={ form.timeValue }
							onChange={ value => update( { timeValue: value, timeDirty: true } ) }
						/>
					) : (
						<>
							<span className="newspack-live-composer__now">{ __( 'Time: now', 'newspack-live' ) }</span>
							<Button
								variant="link"
								onClick={ () =>
									update( { customTime: true, timeDirty: true, timeValue: toLocalInput( Math.floor( Date.now() / 1000 ) ) } )
								}
							>
								{ __( 'Change time', 'newspack-live' ) }
							</Button>
						</>
					) }
				</div>
			</div>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Title (optional)', 'newspack-live' ) }
				value={ form.title }
				onChange={ value => update( { title: value } ) }
			/>
			<LabelControl
				liveId={ live.id }
				value={ form.label }
				onChange={ label => update( { label } ) }
				custom={ live.labels ?? [] }
				onLabelsChange={ labels => receiveLive( { ...live, labels } ) }
				answer={ answering.length > 0 || isAnswer( editing?.question_ids ?? [] ) }
				canManage={ user.can_publish }
			/>
			<BodyEditor
				blocks={ form.blocks }
				onChange={ blocks => update( { blocks } ) }
				allowedBlocks={ config.allowedBlocks }
				label={ __( 'Entry body', 'newspack-live' ) }
				canUpload={ user.can_upload }
			/>
			<div className="newspack-live-composer__row">
				{ user.can_edit_others && authorOptions.length > 0 && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Author', 'newspack-live' ) }
						value={ String( form.author ) }
						options={
							authorOptions.some( option => option.value === String( form.author ) )
								? authorOptions
								: [ { value: String( form.author ), label: user.name }, ...authorOptions ]
						}
						onChange={ value => update( { author: Number( value ) } ) }
					/>
				) }
				{ user.can_publish && (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Pin to top', 'newspack-live' ) }
						checked={ form.pinned }
						onChange={ value => update( { pinned: value } ) }
					/>
				) }
			</div>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) } spokenMessage="">
					{ error }
				</Notice>
			) }
			<div className="newspack-live-composer__actions">
				<Button
					variant="primary"
					onClick={ () => submit( isPublished ? 'publish' : primaryStatus ) }
					isBusy={ busy }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ primaryLabel }
				</Button>
				{ ! isPublished && (
					<Button variant="secondary" onClick={ () => submit( 'draft' ) } disabled={ busy } __next40pxDefaultSize>
						{ __( 'Save draft', 'newspack-live' ) }
					</Button>
				) }
				{ ( editing || answering.length > 0 ) && (
					<Button
						variant="tertiary"
						onClick={ () => {
							setEditing( null );
							setAnswering( [] );
							reset();
						} }
						__next40pxDefaultSize
					>
						{ __( 'Cancel', 'newspack-live' ) }
					</Button>
				) }
				{ editing && (
					<Button variant="link" href={ `${ config.fullEditorBase }${ editing.id }` } target="_blank">
						{ __( 'Open in full editor', 'newspack-live' ) }
					</Button>
				) }
			</div>
			<p className="newspack-live-composer__shortcut">
				{ sprintf(
					/* translators: %s: keyboard shortcut. */
					__( 'Shortcut: %s', 'newspack-live' ),
					window.navigator.platform.includes( 'Mac' ) ? '⌘ + Enter' : 'Ctrl + Enter'
				) }
			</p>
		</section>
	);
}
