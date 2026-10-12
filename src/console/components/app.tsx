/**
 * Live console layout: status bar, composer, tabs; 15 s refresh.
 */

/**
 * WordPress dependencies
 */
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { config } from '../config';
import { store } from '../store';
import Composer from './composer';
import EntryList from './entry-list';
import { usePresence } from './presence';
import QuestionsTab from './questions-tab';
import StatusBar from './status-bar';

/**
 * Console app.
 *
 * @return Element.
 */
export default function App() {
	const { loaded, error, feed, drafts, draftsTotal, hasMore, hasMoreDrafts, newQuestions, editingId, user } = useSelect( select => {
		const console = select( store );
		return {
			loaded: console.isLoaded(),
			error: console.getError(),
			feed: console.getFeed(),
			drafts: console.getDrafts(),
			draftsTotal: console.getDraftsTotal(),
			hasMore: console.hasMore(),
			hasMoreDrafts: console.hasMoreDrafts(),
			newQuestions: console.getQuestionCount().nl_new ?? 0,
			editingId: console.getEditing()?.id ?? 0,
			user: console.getUser(),
		};
	}, [] );
	const { load, loadMore } = useDispatch( store );
	const registry = useRegistry();
	const composerRef = useRef< HTMLDivElement >( null );
	usePresence( config.liveId );

	useEffect( () => {
		load( config.liveId, true );
		const timer = window.setInterval( () => load( config.liveId ), config.pollInterval * 1000 );
		return () => window.clearInterval( timer );
	}, [] );

	// Bring the composer into view when an entry is loaded for editing (mobile: it's above the tabs).
	const focusComposer = () => composerRef.current?.scrollIntoView( { block: 'start' } );
	useEffect( () => {
		if ( editingId ) {
			focusComposer();
		}
	}, [ editingId ] );

	if ( ! loaded ) {
		return error ? (
			<Notice status="error" isDismissible={ false } politeness="polite">
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const tabs = [
		{ name: 'feed', title: __( 'Feed', 'newspack-live' ) },
		{
			name: 'drafts',
			title: sprintf(
				/* translators: %d: number of drafts. */
				__( 'Drafts (%d)', 'newspack-live' ),
				Math.max( draftsTotal, drafts.length )
			),
		},
		...( user?.can_moderate
			? [
					{
						name: 'questions',
						title: sprintf(
							/* translators: %d: number of new questions. */
							__( 'Questions (%d new)', 'newspack-live' ),
							newQuestions
						),
					},
				]
			: [] ),
	];

	return (
		<div className="newspack-live-console">
			<StatusBar />
			{ error && (
				<Notice status="warning" isDismissible={ false } politeness="polite">
					{ error }
				</Notice>
			) }
			<div className="newspack-live-console__main">
				<div className="newspack-live-console__composer" ref={ composerRef }>
					<Composer />
				</div>
				<div className="newspack-live-console__tabs">
					<TabPanel tabs={ tabs } className="newspack-live-console__tab-panel">
						{ tab => {
							switch ( tab.name ) {
								case 'drafts':
									return (
										<EntryList
											entries={ drafts }
											empty={ __( 'No drafts.', 'newspack-live' ) }
											label={ __( 'Drafts', 'newspack-live' ) }
											hasMore={ hasMoreDrafts }
											onLoadMore={ () =>
												// The cursor is the oldest draft in server order (the list shows pending ones first).
												loadMore(
													config.liveId,
													'drafts',
													registry.select( store ).getRawDraftsLast()
												) as unknown as Promise< number >
											}
										/>
									);
								case 'questions':
									return <QuestionsTab onAnswer={ focusComposer } />;
								default:
									return (
										<EntryList
											entries={ feed }
											empty={ __( 'Nothing published yet.', 'newspack-live' ) }
											label={ __( 'Published entries', 'newspack-live' ) }
											hasMore={ hasMore }
											onLoadMore={ () =>
												loadMore(
													config.liveId,
													'feed',
													registry.select( store ).getFeedCursor()
												) as unknown as Promise< number >
											}
										/>
									);
							}
						} }
					</TabPanel>
				</div>
			</div>
		</div>
	);
}
