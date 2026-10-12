/**
 * Questions inbox with similar-question groups.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, CheckboxControl, Notice, SearchControl, SelectControl, Spinner } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanTimeDiff } from '@wordpress/date';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import * as api from '../api';
import { store } from '../store';
import type { Question, QuestionStatus } from '../types';
import LoadMore from './load-more';

const MAX_ANSWER = 50;

type Filter = QuestionStatus | 'all';

/**
 * Status filters.
 *
 * @return Filters.
 */
const filters = (): Array< { value: Filter; label: string } > => [
	{ value: 'nl_new', label: __( 'New', 'newspack-live' ) },
	{ value: 'nl_shortlisted', label: __( 'Shortlisted', 'newspack-live' ) },
	{ value: 'nl_answered', label: __( 'Answered', 'newspack-live' ) },
	{ value: 'nl_rejected', label: __( 'Rejected', 'newspack-live' ) },
	{ value: 'nl_spam', label: __( 'Spam', 'newspack-live' ) },
	{ value: 'all', label: __( 'All', 'newspack-live' ) },
];

/**
 * Bulk actions.
 *
 * @return Actions.
 */
const bulkActions = (): Array< { status: Exclude< QuestionStatus, 'nl_answered' >; label: string } > => [
	{ status: 'nl_shortlisted', label: __( 'Shortlist', 'newspack-live' ) },
	{ status: 'nl_rejected', label: __( 'Reject', 'newspack-live' ) },
	{ status: 'nl_spam', label: __( 'Mark as spam', 'newspack-live' ) },
	{ status: 'nl_new', label: __( 'Restore to New', 'newspack-live' ) },
];

/**
 * Inbox.
 *
 * @param props          Props.
 * @param props.onAnswer Called after "Answer selected" (switches to the composer on mobile).
 * @return Element.
 */
export default function QuestionsTab( { onAnswer }: { onAnswer: () => void } ) {
	const { live, user, count } = useSelect(
		select => ( { live: select( store ).getLive(), user: select( store ).getUser(), count: select( store ).getQuestionCount() } ),
		[]
	);
	const { setAnswering, setQuestionCount } = useDispatch( store );
	const [ filter, setFilter ] = useState< Filter >( 'nl_new' );
	const [ search, setSearch ] = useState( '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ items, setItems ] = useState< Question[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	// "Load more" was used: refreshes keep every item down to the last one loaded.
	const paged = useRef( false );
	const itemsRef = useRef< Question[] >( [] );
	itemsRef.current = items;
	// A refresh asked for while "Load more" runs waits for it (it would replace the page being added).
	const loadingMore = useRef( false );
	const refreshPending = useRef( false );
	// Bumped when the filter or search changes: pages loaded for the old list are dropped.
	const generation = useRef( 0 );
	const [ loading, setLoading ] = useState( false );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ error, setError ] = useState( '' );
	const [ guidance, setGuidance ] = useState( false );
	const latest = useRef( 0 );
	const listRef = useRef< HTMLUListElement >( null );
	const liveId = live?.id ?? 0;

	const fetchPage = useCallback( async () => {
		if ( ! liveId ) {
			return;
		}
		if ( loadingMore.current ) {
			refreshPending.current = true;
			return;
		}
		const request = ++latest.current;
		setLoading( true );
		try {
			const last = itemsRef.current[ itemsRef.current.length - 1 ];
			const until = paged.current && last ? last.id : 0;
			const response = await api.fetchQuestions( liveId, { status: filter, search, until } );
			if ( request === latest.current ) {
				setError( '' );
				setItems( response.items );
				setHasMore( response.has_more );
				setQuestionCount( response.count );
			}
		} catch ( failure ) {
			setError( ( failure as { message?: string } )?.message ?? '' );
		} finally {
			setLoading( false );
		}
	}, [ liveId, filter, search ] );
	// The latest fetchPage (a "Load more" that resolves after a filter change must refresh the new list).
	const fetchRef = useRef( fetchPage );
	fetchRef.current = fetchPage;

	const loadMore = async (): Promise< number | null > => {
		const last = itemsRef.current[ itemsRef.current.length - 1 ];
		const started = generation.current;
		loadingMore.current = true;
		// A refresh already in flight would replace the page being added: drop it and refresh after.
		if ( loading ) {
			++latest.current;
			refreshPending.current = true;
		}
		try {
			const response = await api.fetchQuestions( liveId, { status: filter, search, before: last?.id ?? 0 } );
			if ( started !== generation.current ) {
				return null;
			}
			const known = new Set( itemsRef.current.map( item => item.id ) );
			const added = response.items.filter( item => ! known.has( item.id ) );
			paged.current = true;
			itemsRef.current = [ ...itemsRef.current, ...added ];
			setItems( itemsRef.current );
			setHasMore( response.has_more );
			setQuestionCount( response.count );
			return added.length;
		} finally {
			loadingMore.current = false;
			if ( refreshPending.current ) {
				refreshPending.current = false;
				fetchRef.current();
			}
		}
	};

	// A new filter or search starts again from the first page.
	const restart = () => {
		generation.current++;
		paged.current = false;
		itemsRef.current = [];
		setItems( [] );
		setSelected( [] );
	};

	useEffect( () => {
		fetchPage();
	}, [ fetchPage ] );

	// Refresh the list when the counts change (new questions, answers published from the composer).
	useEffect( () => {
		fetchPage();
	}, [ JSON.stringify( count ) ] );

	const debouncedSearch = useDebounce( ( value: string ) => {
		restart();
		setSearch( value );
	}, 300 );

	if ( ! live || ! user?.can_moderate ) {
		return <p>{ __( 'Only editors and authors on the team can moderate questions.', 'newspack-live' ) }</p>;
	}

	const toggle = ( id: number, checked: boolean ) =>
		setSelected( current => ( checked ? [ ...current, id ] : current.filter( other => other !== id ) ) );

	const bulk = async ( status: Exclude< QuestionStatus, 'nl_answered' > ) => {
		try {
			const response = await api.bulkQuestions( live.id, selected, status );
			setQuestionCount( response.count );
			speak(
				sprintf(
					/* translators: %d: number of questions. */
					_n( '%d question updated.', '%d questions updated.', response.updated.length, 'newspack-live' ),
					response.updated.length
				)
			);
			setSelected( [] );
			fetchPage();
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? '';
			setError( message );
			speak( message, 'assertive' );
		}
	};

	// Selection order, each group with its similar questions (answering a group lists every
	// grouped question).
	const answerItems = selected
		.map( id => items.find( item => item.id === id ) )
		.filter( ( item ): item is Question => !! item )
		.flatMap( item => [ item, ...( item.similar ?? [] ) ] )
		.filter( ( item, index, all ) => all.findIndex( other => other.id === item.id ) === index );

	const answer = () => {
		setAnswering( answerItems.slice( 0, MAX_ANSWER ) );
		setSelected( [] );
		speak( __( 'The composer is ready to answer the selected questions.', 'newspack-live' ) );
		onAnswer();
	};

	const ungroup = async ( id: number ) => {
		try {
			await api.ungroupQuestion( live.id, id );
			speak( __( 'Question ungrouped.', 'newspack-live' ) );
			// The button goes away with the group: keep focus in the list.
			listRef.current?.focus();
			fetchPage();
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? '';
			setError( message );
			speak( message, 'assertive' );
		}
	};

	return (
		<div className="newspack-live-questions">
			<div className="newspack-live-questions__filters">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'newspack-live' ) }
					value={ filter }
					options={ filters().map( option => ( { value: option.value as string, label: option.label } ) ) }
					onChange={ value => {
						restart();
						setFilter( value as Filter );
					} }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search questions', 'newspack-live' ) }
					value={ searchInput }
					onChange={ value => {
						setSearchInput( value );
						debouncedSearch( value );
					} }
				/>
			</div>
			<div className="newspack-live-questions__guidance">
				<Button variant="link" aria-expanded={ guidance } aria-controls="newspack-live-guidance" onClick={ () => setGuidance( ! guidance ) }>
					{ __( 'Which questions to answer?', 'newspack-live' ) }
				</Button>
				<p id="newspack-live-guidance" hidden={ ! guidance }>
					{ __(
						'Pick questions that are courteous, answerable now, not already answered, and about facts rather than opinions.',
						'newspack-live'
					) }
				</p>
			</div>
			<div className="newspack-live-questions__bulk" role="group" aria-label={ __( 'Bulk actions', 'newspack-live' ) }>
				{ bulkActions()
					.filter( action => action.status !== filter )
					.map( action => (
						<Button
							key={ action.status }
							variant="secondary"
							size="compact"
							disabled={ ! selected.length }
							onClick={ () => bulk( action.status ) }
						>
							{ action.label }
						</Button>
					) ) }
				<Button variant="primary" size="compact" disabled={ ! selected.length || answerItems.length > MAX_ANSWER } onClick={ answer }>
					{ __( 'Answer selected', 'newspack-live' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) } spokenMessage="">
					{ error }
				</Notice>
			) }
			{ loading && <Spinner /> }
			{ ! loading && ! items.length && <p className="newspack-live-console__empty">{ __( 'No questions here.', 'newspack-live' ) }</p> }
			<ul className="newspack-live-questions__list" ref={ listRef } tabIndex={ -1 } aria-label={ __( 'Questions', 'newspack-live' ) }>
				{ items.map( item => (
					<li key={ item.id } className="newspack-live-question" data-item-id={ item.id } tabIndex={ -1 }>
						<CheckboxControl
							__nextHasNoMarginBottom
							checked={ selected.includes( item.id ) }
							onChange={ checked => toggle( item.id, checked ) }
							label={ sprintf(
								/* translators: %s: reader display name. */
								__( 'Select the question from %s', 'newspack-live' ),
								item.name
							) }
							className="newspack-live-question__check"
						/>
						<div className="newspack-live-question__body">
							<p className="newspack-live-question__text">{ item.text }</p>
							<p className="newspack-live-question__meta">
								<span className="newspack-live-question__name">{ item.name }</span>
								{ item.registered && (
									<span className="newspack-live-console-entry__pill">{ __( 'Registered', 'newspack-live' ) }</span>
								) }
								<span>{ humanTimeDiff( item.time * 1000, undefined ) }</span>
							</p>
							{ !! item.similar?.length && (
								<div className="newspack-live-question__similar">
									<p className="newspack-live-question__similar-title">
										{ sprintf(
											/* translators: %d: number of similar questions. */
											_n( '%d similar question', '%d similar questions', item.similar.length, 'newspack-live' ),
											item.similar.length
										) }
									</p>
									<ul>
										{ item.similar.map( member => (
											<li key={ member.id }>
												<span className="newspack-live-question__text">{ member.text }</span>{ ' ' }
												<span className="newspack-live-question__name">{ member.name }</span>{ ' ' }
												<Button
													variant="link"
													onClick={ () => ungroup( member.id ) }
													aria-label={ sprintf(
														/* translators: %s: reader display name. */
														__( 'Ungroup the question from %s', 'newspack-live' ),
														member.name
													) }
												>
													{ __( 'Ungroup', 'newspack-live' ) }
												</Button>
											</li>
										) ) }
									</ul>
								</div>
							) }
						</div>
					</li>
				) ) }
			</ul>
			{ hasMore && <LoadMore onLoad={ loadMore } list={ () => listRef.current } /> }
		</div>
	);
}
