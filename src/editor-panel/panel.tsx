/**
 * "Live coverage" panel of the post editor and the auto-end notice.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	DateTimePicker,
	Dropdown,
	FormTokenField,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis -- core layout primitive; @wordpress/ui isn't registered on this WordPress.
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { dateI18n, getDate, getSettings } from '@wordpress/date';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { fetchStatus, fetchTeamCandidates, postState, type LiveStatus, type TeamCandidate } from '../shared/api';

declare global {
	interface Window {
		newspackLiveEditor?: {
			consoleUrl: string;
			canManage: boolean;
			hasAds: boolean;
			postTypes: string[];
			/** Published Newspack Collections, empty while the module is inactive. */
			collections?: Array< { id: number; title: string } >;
			/** The "Context" box is on this screen. */
			contextBox?: boolean;
		};
	}
}

type Meta = Record< string, unknown >;

const NOTICE_ID = 'newspack-live-auto-end';

// Stable empty result, so useSelect doesn't return a new array on every call.
const EMPTY: never[] = [];

/**
 * How often the panel refreshes the state while the editor is open, in milliseconds.
 */
const STATUS_REFRESH = 60_000;

/**
 * Select options with plain string values.
 *
 * @param list [ value, label ] pairs.
 * @return Options.
 */
const options = ( list: Array< [ string, string ] > ): Array< { value: string; label: string } > =>
	list.map( ( [ value, label ] ) => ( { value, label } ) );

const STATE_LABELS: Record< string, string > = {
	draft: __( 'Draft', 'newspack-live' ),
	upcoming: __( 'Upcoming', 'newspack-live' ),
	live: __( 'Live', 'newspack-live' ),
	ended: __( 'Ended', 'newspack-live' ),
};

/**
 * Converts a picker value (site timezone, no offset) to a UTC timestamp.
 *
 * @param value Picker value.
 * @return Seconds.
 */
const pickerToTimestamp = ( value: string | null ): number => ( value ? Math.floor( getDate( value ).getTime() / 1000 ) : 0 );

/**
 * Converts a UTC timestamp to a picker value in the site timezone.
 *
 * @param seconds Timestamp.
 * @return Picker value.
 */
const timestampToPicker = ( seconds: number ): string => dateI18n( 'Y-m-d\\TH:i:s', seconds * 1000, undefined );

/**
 * Token of an item: "Name (#id)". The name makes FormTokenField's suggestion matching work; the ID
 * keeps tokens unique even when names repeat.
 *
 * @param id    ID.
 * @param names Known names by ID.
 * @return Token.
 */
const tokenOf = ( id: number, names: Record< number, string > ): string => `${ names[ id ] ?? '' } (#${ id })`.trim();

/**
 * ID of a token, or 0.
 *
 * @param token Token.
 * @return ID.
 */
const idOf = ( token: string ): number => Number( /\(#(\d+)\)$/.exec( token )?.[ 1 ] ?? 0 );

/**
 * Token field bound to IDs.
 *
 * @param props            Props.
 * @param props.label      Field label.
 * @param props.value      Selected IDs.
 * @param props.names      Known names by ID.
 * @param props.candidates Suggested IDs.
 * @param props.max        Maximum number of items.
 * @param props.onSearch   Debounced search handler.
 * @param props.onChange   Change handler.
 * @return Token field.
 */
function IdTokenField( props: {
	label: string;
	value: number[];
	names: Record< number, string >;
	candidates: number[];
	max?: number;
	onSearch: ( term: string ) => void;
	onChange: ( ids: number[] ) => void;
} ) {
	const { label, value, names, candidates, max, onSearch, onChange } = props;
	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ value.map( id => tokenOf( id, names ) ) }
			suggestions={ candidates.filter( id => ! value.includes( id ) ).map( id => tokenOf( id, names ) ) }
			displayTransform={ ( token: string ) => names[ idOf( token ) ] ?? token }
			__experimentalValidateInput={ ( token: string ) => idOf( token ) > 0 }
			maxLength={ max }
			onInputChange={ onSearch }
			onChange={ tokens => {
				const ids = tokens.map( token => idOf( typeof token === 'string' ? token : token.value ) ).filter( id => id > 0 );
				onChange( Array.from( new Set( ids ) ).slice( 0, max ?? ids.length ) );
			} }
		/>
	);
}

/**
 * Team field: users who can post entries, searched through `/team-candidates`.
 *
 * @param props          Props.
 * @param props.value    Selected user IDs.
 * @param props.onChange Change handler.
 * @return Field.
 */
function TeamField( { value, onChange }: { value: number[]; onChange: ( ids: number[] ) => void } ) {
	const [ names, setNames ] = useState< Record< number, string > >( {} );
	const [ candidates, setCandidates ] = useState< number[] >( [] );
	const latest = useRef( 0 );

	const remember = useCallback( ( users: TeamCandidate[] ) => {
		setNames( previous => ( { ...previous, ...Object.fromEntries( users.map( user => [ user.id, user.name ] ) ) } ) );
	}, [] );

	useEffect( () => {
		const missing = value.filter( id => ! names[ id ] );
		if ( missing.length ) {
			fetchTeamCandidates( '', missing )
				.then( remember )
				.catch( () => undefined );
		}
	}, [ value.join( ',' ) ] );

	const search = useDebounce( ( term: string ) => {
		const request = ++latest.current;
		fetchTeamCandidates( term )
			.then( users => {
				// Ignore responses that arrive after a newer search.
				if ( request === latest.current ) {
					remember( users );
					setCandidates( users.map( user => user.id ) );
				}
			} )
			.catch( () => undefined );
	}, 300 );

	return (
		<IdTokenField
			label={ __( 'Live team', 'newspack-live' ) }
			value={ value }
			names={ names }
			candidates={ candidates }
			onSearch={ search }
			onChange={ onChange }
		/>
	);
}

/**
 * Tag or post picker backed by core-data search.
 *
 * @param props          Props.
 * @param props.kind     `tag` or `post`.
 * @param props.label    Field label.
 * @param props.value    Selected IDs.
 * @param props.max      Maximum number of items.
 * @param props.exclude  ID to leave out (the live itself).
 * @param props.onChange Change handler.
 * @return Token field.
 */
function EntityPicker( props: {
	kind: 'tag' | 'post';
	label: string;
	value: number[];
	max: number;
	exclude?: number;
	onChange: ( ids: number[] ) => void;
} ) {
	const { kind, label, value, max, exclude, onChange } = props;
	const [ search, setSearch ] = useState( '' );
	const debouncedSearch = useDebounce( setSearch, 300 );
	const [ kindName, entity ] = kind === 'tag' ? [ 'taxonomy', 'post_tag' ] : [ 'postType', 'post' ];
	const { found, selected } = useSelect(
		select => {
			type Record = { id: number; name?: string; title?: { rendered?: string } };
			const store = select( coreStore );
			const results = ( store.getEntityRecords( kindName, entity, {
				search,
				per_page: 20,
				...( kind === 'post' ? { status: 'publish' } : {} ),
			} ) ?? EMPTY ) as Record[];
			const current = value.length
				? ( ( store.getEntityRecords( kindName, entity, { include: value, per_page: value.length } ) ?? EMPTY ) as Record[] )
				: EMPTY;
			return { found: results, selected: current };
		},
		[ search, value.join( ',' ) ]
	);
	const names = useMemo(
		() =>
			Object.fromEntries(
				[ ...found, ...selected ].map( record => [ record.id, decodeEntities( record.name ?? record.title?.rendered ?? '' ) ] )
			),
		[ found, selected ]
	);
	return (
		<IdTokenField
			label={ label }
			value={ value }
			names={ names }
			candidates={ found.map( record => record.id ).filter( id => id !== exclude ) }
			max={ max }
			onSearch={ debouncedSearch }
			onChange={ onChange }
		/>
	);
}

/**
 * The panel.
 *
 * @return Panel, or null on post types that can't host lives.
 */
export default function LivePanel() {
	const config = window.newspackLiveEditor;
	const { postType, postId, postStatus, isSaving } = useSelect( select => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType() as string,
			postId: editor.getCurrentPostId() as number,
			postStatus: editor.getEditedPostAttribute( 'status' ) as string,
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' ) as unknown as [ Meta, ( next: Meta ) => void ];
	const [ status, setStatus ] = useState< LiveStatus | null >( null );
	const { createWarningNotice, removeNotice } = useDispatch( noticesStore );
	const wasSaving = useRef( false );

	const supported = !! config && config.postTypes.includes( postType );
	const enabled = !! meta?._newspack_live_enabled;
	const saved = postStatus !== 'auto-draft';
	const canManage = !! config?.canManage;

	const update = ( key: string, value: unknown ) => setMeta( { ...meta, [ key ]: value } );

	const refresh = useCallback( () => {
		if ( ! supported || ! enabled || ! saved || ! postId ) {
			setStatus( null );
			return;
		}
		fetchStatus( postId )
			.then( setStatus )
			.catch( () => setStatus( null ) );
	}, [ supported, enabled, saved, postId ] );

	// Load now, then refresh periodically so a warning that starts while editing shows up.
	useEffect( () => {
		refresh();
		const timer = window.setInterval( refresh, STATUS_REFRESH );
		return () => window.clearInterval( timer );
	}, [ refresh ] );

	// Refresh after each save (settings such as the start time change the state).
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			refresh();
		}
		wasSaving.current = isSaving;
	}, [ isSaving ] );

	// Auto-end warning notice with "Keep live". It describes a standing state: not spoken.
	useEffect( () => {
		if ( ! status?.auto_end?.warning ) {
			removeNotice( NOTICE_ID );
			return;
		}
		createWarningNotice( status.auto_end.message, {
			id: NOTICE_ID,
			isDismissible: false,
			speak: false,
			actions: [
				{
					label: __( 'Keep live', 'newspack-live' ),
					onClick: () => {
						postState( postId, 'keep_live' ).then( refresh ).catch( refresh );
					},
				},
			],
		} );
	}, [ status?.auto_end?.warning, status?.auto_end?.message ] );

	useEffect(
		() => () => {
			removeNotice( NOTICE_ID );
		},
		[]
	);

	const teamIds = useMemo(
		() => ( Array.isArray( meta?._newspack_live_team ) ? ( meta._newspack_live_team as number[] ) : [] ),
		[ meta?._newspack_live_team ]
	);

	if ( ! supported || ! meta ) {
		return null;
	}

	const scheduled = Number( meta._newspack_live_scheduled_start ) || 0;
	const dateSettings = getSettings();
	const relatedSource = String( meta._newspack_live_related_source || 'primary-category' );
	const stateLabel = STATE_LABELS[ status?.state ?? 'draft' ] ?? STATE_LABELS.draft;

	if ( ! canManage ) {
		// Settings require newspack_live_manage (authors and above): others only see the state.
		return enabled ? (
			<PluginDocumentSettingPanel name="newspack-live" title={ __( 'Live coverage', 'newspack-live' ) } className="newspack-live-panel">
				<div className="newspack-live-panel__state">
					<span className={ `newspack-live-panel__badge newspack-live-panel__badge--${ status?.state ?? 'draft' }` }>{ stateLabel }</span>
					<Button
						variant="secondary"
						href={ saved ? `${ config.consoleUrl }${ postId }` : undefined }
						disabled={ ! saved }
						__next40pxDefaultSize
					>
						{ __( 'Open live console', 'newspack-live' ) }
					</Button>
				</div>
			</PluginDocumentSettingPanel>
		) : null;
	}

	return (
		<PluginDocumentSettingPanel name="newspack-live" title={ __( 'Live coverage', 'newspack-live' ) } className="newspack-live-panel">
			<VStack spacing={ 4 }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Enable live coverage', 'newspack-live' ) }
					checked={ enabled }
					onChange={ value => update( '_newspack_live_enabled', value ) }
				/>
				{ enabled && (
					<>
						<div className="newspack-live-panel__state">
							<span className={ `newspack-live-panel__badge newspack-live-panel__badge--${ status?.state ?? 'draft' }` }>
								{ stateLabel }
							</span>
							<Button
								variant="secondary"
								href={ saved ? `${ config.consoleUrl }${ postId }` : undefined }
								disabled={ ! saved }
								__next40pxDefaultSize
							>
								{ __( 'Open live console', 'newspack-live' ) }
							</Button>
						</div>
						<div className="newspack-live-panel__start">
							<span className="components-base-control__label">{ __( 'Start time', 'newspack-live' ) }</span>
							<Dropdown
								popoverProps={ { placement: 'left-start' } }
								renderToggle={ ( { isOpen, onToggle } ) => (
									<Button variant="tertiary" onClick={ onToggle } aria-expanded={ isOpen }>
										{ scheduled
											? dateI18n( `${ dateSettings.formats.date } ${ dateSettings.formats.time }`, scheduled * 1000, undefined )
											: __( 'Publish time', 'newspack-live' ) }
									</Button>
								) }
								renderContent={ () => (
									<div className="newspack-live-panel__picker">
										<DateTimePicker
											currentDate={ timestampToPicker( scheduled || Math.floor( Date.now() / 1000 ) ) }
											onChange={ date => update( '_newspack_live_scheduled_start', pickerToTimestamp( date ) ) }
										/>
										<Button variant="link" onClick={ () => update( '_newspack_live_scheduled_start', 0 ) }>
											{ __( 'Use the publish time', 'newspack-live' ) }
										</Button>
									</div>
								) }
							/>
						</div>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Auto-end after inactivity', 'newspack-live' ) }
							value={ String( meta._newspack_live_auto_end_hours ?? 24 ) }
							options={ options( [
								[ '6', __( '6 hours', 'newspack-live' ) ],
								[ '12', __( '12 hours', 'newspack-live' ) ],
								[ '24', __( '24 hours', 'newspack-live' ) ],
								[ '48', __( '48 hours', 'newspack-live' ) ],
								[ '0', __( 'Never', 'newspack-live' ) ],
							] ) }
							onChange={ value => update( '_newspack_live_auto_end_hours', Number( value ) ) }
						/>
						{ !! status?.hosts?.length && (
							<p className="newspack-live-panel__hosts">
								{ __( 'Hosts (always included):', 'newspack-live' ) } { status.hosts.join( ', ' ) }
							</p>
						) }
						<TeamField value={ teamIds } onChange={ ids => update( '_newspack_live_team', ids ) } />
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Reader questions', 'newspack-live' ) }
							value={ String( meta._newspack_live_questions_mode ?? 'anyone' ) }
							options={ options( [
								[ 'off', __( 'Off', 'newspack-live' ) ],
								[ 'anyone', __( 'Anyone', 'newspack-live' ) ],
								[ 'registered', __( 'Registered readers only', 'newspack-live' ) ],
							] ) }
							onChange={ value => update( '_newspack_live_questions_mode', value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Question prompt', 'newspack-live' ) }
							placeholder={ __( 'Ask the newsroom a question', 'newspack-live' ) }
							value={ String( meta._newspack_live_question_prompt ?? '' ) }
							onChange={ value => update( '_newspack_live_question_prompt', value ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show entry author', 'newspack-live' ) }
							checked={ meta._newspack_live_show_entry_author !== false }
							onChange={ value => update( '_newspack_live_show_entry_author', value ) }
						/>
						{ config.hasAds && (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Hide all ads on this live', 'newspack-live' ) }
								checked={ !! meta.newspack_ads_suppress_ads }
								onChange={ value => update( 'newspack_ads_suppress_ads', value ) }
							/>
						) }
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Related coverage', 'newspack-live' ) }
							value={ relatedSource }
							options={ options( [
								[ 'primary-category', __( 'Primary category', 'newspack-live' ) ],
								[ 'tag', __( 'Tag', 'newspack-live' ) ],
								[ 'manual', __( 'Choose stories', 'newspack-live' ) ],
								...( config.collections?.length || relatedSource === 'collection'
									? [ [ 'collection', __( 'Collection', 'newspack-live' ) ] as [ string, string ] ]
									: [] ),
							] ) }
							onChange={ value => update( '_newspack_live_related_source', value ) }
						/>
						{ relatedSource === 'tag' && (
							<EntityPicker
								kind="tag"
								label={ __( 'Related tag', 'newspack-live' ) }
								value={ Number( meta._newspack_live_related_tag ) ? [ Number( meta._newspack_live_related_tag ) ] : [] }
								max={ 1 }
								onChange={ ids => update( '_newspack_live_related_tag', ids[ 0 ] ?? 0 ) }
							/>
						) }
						{ relatedSource === 'collection' && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Related collection', 'newspack-live' ) }
								help={
									config.collections?.length
										? __( 'The aside lists up to 4 of its stories.', 'newspack-live' )
										: __(
												'Turn on Newspack Collections to use a collection; the primary category is used meanwhile.',
												'newspack-live'
											)
								}
								value={ String( Number( meta._newspack_live_related_collection ) || '' ) }
								options={ options( [
									[ '', __( 'Choose a collection', 'newspack-live' ) ],
									...( config.collections ?? [] ).map( item => [ String( item.id ), item.title ] as [ string, string ] ),
								] ) }
								onChange={ value => update( '_newspack_live_related_collection', Number( value ) || 0 ) }
							/>
						) }
						{ relatedSource === 'manual' && (
							<EntityPicker
								kind="post"
								label={ __( 'Related stories (up to 4)', 'newspack-live' ) }
								value={ Array.isArray( meta._newspack_live_related_posts ) ? ( meta._newspack_live_related_posts as number[] ) : [] }
								max={ 4 }
								exclude={ postId }
								onChange={ ids => update( '_newspack_live_related_posts', ids ) }
							/>
						) }
					</>
				) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
