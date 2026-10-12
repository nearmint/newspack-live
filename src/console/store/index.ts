/**
 * `newspack-live/console` data store.
 */

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';

/**
 * Internal dependencies
 */
import * as api from '../api';
import type { ConsoleEntry, LivePayload, PresenceUser, Question, QuestionStatus } from '../types';
import { getDrafts, getFeed, initialState, reducer, type Action, type State } from './reducer';

interface ThunkArgs {
	dispatch: ( action: Action | Thunk ) => unknown;
	select: { getRev: () => number; getWrites: () => number; getDraftsUntil: () => number; getFeed: () => ConsoleEntry[] };
}

type Thunk = ( args: ThunkArgs ) => Promise< unknown >;

const errorMessage = ( error: unknown ): string => ( error as { message?: string } )?.message ?? String( error );

export const actions = {
	receiveEntry: ( entry: ConsoleEntry ): Action => ( { type: 'RECEIVE_ENTRY', entry } ),
	removeEntry: ( id: number ): Action => ( { type: 'REMOVE_ENTRY', id } ),
	receiveLive: ( live: LivePayload ): Action => ( { type: 'RECEIVE_LIVE', live } ),
	setError: ( error: string ): Action => ( { type: 'SET_ERROR', error } ),
	setEditing: ( entry: ConsoleEntry | null ): Action => ( { type: 'SET_EDITING', entry } ),
	setAnswering: ( questions: Question[] ): Action => ( { type: 'SET_ANSWERING', questions } ),
	setQuestionCount: ( count: Partial< Record< QuestionStatus, number > > ): Action => ( { type: 'SET_QUESTION_COUNT', count } ),
	setPresence: ( users: PresenceUser[] ): Action => ( { type: 'SET_PRESENCE', users } ),

	/**
	 * Loads the next 50 entries of the Feed or Drafts list.
	 *
	 * @param liveId Live ID.
	 * @param list   List.
	 * @param before Cursor: the last entry loaded.
	 * @return Thunk resolving to the number of entries loaded.
	 */
	loadMore:
		( liveId: number, list: 'feed' | 'drafts', before: number ): Thunk =>
		async ( { dispatch } ) => {
			const page = await api.fetchConsoleEntries( liveId, list, before );
			dispatch( { type: 'RECEIVE_MORE', list, items: page.items, hasMore: page.has_more } );
			return page.items.length;
		},

	/**
	 * Loads the console (no `since`) or refreshes it; reloads fully when the server asks for a reset.
	 *
	 * @param liveId    Live ID.
	 * @param bootstrap Whether to bootstrap.
	 * @return Thunk.
	 */
	load:
		( liveId: number, bootstrap = false ): Thunk =>
		async ( { dispatch, select } ) => {
			try {
				const writes = select.getWrites();
				const draftsUntil = bootstrap ? 0 : select.getDraftsUntil();
				const response = await api.fetchConsole( liveId, bootstrap ? undefined : select.getRev(), draftsUntil );
				if ( response.reset && ! bootstrap ) {
					return dispatch( actions.load( liveId, true ) );
				}
				dispatch( { type: 'RECEIVE_CONSOLE', response, bootstrap, writes, draftsUntil } );
			} catch ( error ) {
				dispatch( actions.setError( errorMessage( error ) ) );
			}
		},
};

export const selectors = {
	isLoaded: ( state: State ) => state.loaded,
	getError: ( state: State ) => state.error,
	getRev: ( state: State ) => state.rev,
	getWrites: ( state: State ) => state.writes,
	getLive: ( state: State ) => state.live,
	getUser: ( state: State ) => state.user,
	getTeam: ( state: State ) => state.team,
	getAutoEnd: ( state: State ) => state.autoEnd,
	getQuestionCount: ( state: State ) => state.questionCount,
	getFeed,
	getDrafts,
	getEditing: ( state: State ) => state.editing,
	getAnswering: ( state: State ) => state.answering,
	hasMore: ( state: State ) => state.hasMore,
	hasMoreDrafts: ( state: State ) => state.draftsMore,
	getDraftsTotal: ( state: State ) => state.draftsTotal,
	/** The oldest draft loaded, when "Load more" was used (refreshes keep the drafts down to it). */
	getDraftsUntil: ( state: State ) => ( state.draftsPaged && state.drafts.length ? state.drafts[ state.drafts.length - 1 ].id : 0 ),
	getPresence: ( state: State ) => state.presence,
	/** The "Load more" cursor of the Feed (not the last entry shown: refreshes add edited older ones). */
	getFeedCursor: ( state: State ) => state.feedCursor,
	/** The last draft in server order (the "Load more" cursor). */
	getRawDraftsLast: ( state: State ) => state.drafts[ state.drafts.length - 1 ]?.id ?? 0,
};

export const STORE_NAME = 'newspack-live/console';

export const store = createReduxStore( STORE_NAME, {
	reducer: reducer as ( state: State | undefined, action: { type: string } ) => State,
	initialState,
	actions,
	selectors,
} );

register( store );
