/**
 * Console state: pure reducer (unit-tested).
 */

/**
 * WordPress dependencies
 */
import { createSelector } from '@wordpress/data';

/**
 * Internal dependencies
 */
import type { AutoEnd, ConsoleEntry, ConsoleResponse, ConsoleUser, LivePayload, PresenceUser, Question, QuestionStatus, TeamMember } from '../types';

export interface State {
	loaded: boolean;
	error: string;
	rev: number;
	live: LivePayload | null;
	user: ConsoleUser | null;
	team: TeamMember[];
	autoEnd: AutoEnd | null;
	questionCount: Partial< Record< QuestionStatus, number > >;
	entries: Record< number, ConsoleEntry >;
	drafts: ConsoleEntry[];
	hasMore: boolean;
	/** The oldest published entry loaded by the bootstrap or "Load more" (refreshes never move it). */
	feedCursor: number;
	/** Older drafts exist after the ones loaded. */
	draftsMore: boolean;
	/** Drafts the user sees in all (the tab count). */
	draftsTotal: number;
	/** "Load more" was used on Drafts: refreshes keep the drafts down to the oldest loaded. */
	draftsPaged: boolean;
	editing: ConsoleEntry | null;
	answering: Question[];
	writes: number;
	presence: PresenceUser[];
}

export const initialState: State = {
	loaded: false,
	error: '',
	rev: 0,
	live: null,
	user: null,
	team: [],
	autoEnd: null,
	questionCount: {},
	entries: {},
	drafts: [],
	hasMore: false,
	feedCursor: 0,
	draftsMore: false,
	draftsTotal: 0,
	draftsPaged: false,
	editing: null,
	answering: [],
	writes: 0,
	presence: [],
};

export type Action =
	| { type: 'RECEIVE_CONSOLE'; response: ConsoleResponse; bootstrap: boolean; writes: number; draftsUntil?: number }
	| { type: 'RECEIVE_ENTRY'; entry: ConsoleEntry }
	| { type: 'REMOVE_ENTRY'; id: number }
	| { type: 'RECEIVE_LIVE'; live: LivePayload }
	| { type: 'SET_ERROR'; error: string }
	| { type: 'SET_EDITING'; entry: ConsoleEntry | null }
	| { type: 'SET_ANSWERING'; questions: Question[] }
	| { type: 'SET_QUESTION_COUNT'; count: Partial< Record< QuestionStatus, number > > }
	| { type: 'SET_AUTO_END'; autoEnd: AutoEnd | null }
	| { type: 'RECEIVE_MORE'; list: 'feed' | 'drafts'; items: ConsoleEntry[]; hasMore: boolean }
	| { type: 'SET_PRESENCE'; users: PresenceUser[] };

/**
 * Places an entry in the published map or the drafts list.
 *
 * @param state Current state.
 * @param entry Entry.
 * @return New state.
 */
function placeEntry( state: State, entry: ConsoleEntry ): State {
	const entries = { ...state.entries };
	let drafts = state.drafts.filter( draft => draft.id !== entry.id );
	if ( entry.status === 'publish' ) {
		if ( entry.pinned ) {
			// At most one pinned entry per live.
			Object.values( entries ).forEach( other => {
				if ( other.pinned && other.id !== entry.id ) {
					entries[ other.id ] = { ...other, pinned: false };
				}
			} );
		}
		entries[ entry.id ] = entry;
	} else {
		delete entries[ entry.id ];
		drafts = [ entry, ...drafts ];
	}
	const editing = state.editing?.id === entry.id ? entry : state.editing;
	return { ...state, entries, drafts, editing };
}

/**
 * Reducer.
 *
 * @param state  State.
 * @param action Action.
 * @return New state.
 */
export function reducer( state: State = initialState, action: Action ): State {
	switch ( action.type ) {
		case 'RECEIVE_CONSOLE': {
			const { response, bootstrap } = action;
			// A response started before a local write, or older than what we know, would undo recent changes:
			// keep the counters, drop the rest (the next refresh brings it).
			if ( ! bootstrap && ( action.writes < state.writes || response.rev < state.rev ) ) {
				return { ...state, autoEnd: response.auto_end, questionCount: response.question_count };
			}
			let entries: Record< number, ConsoleEntry > = bootstrap ? {} : { ...state.entries };
			response.removed.forEach( id => {
				delete entries[ id ];
			} );
			response.entries.forEach( entry => {
				entries[ entry.id ] = entry;
			} );
			// Keep a single pinned entry when a delta moved the pin.
			const pinned = response.entries.find( entry => entry.pinned );
			if ( pinned ) {
				entries = Object.fromEntries(
					Object.entries( entries ).map( ( [ id, entry ] ) => [
						id,
						entry.id === pinned.id || ! entry.pinned ? entry : { ...entry, pinned: false },
					] )
				);
			}
			// Drafts requested before a "Load more" (another `drafts_until`) would drop the page just loaded.
			const currentUntil = state.draftsPaged && state.drafts.length ? state.drafts[ state.drafts.length - 1 ].id : 0;
			const draftsCurrent = bootstrap || ( action.draftsUntil ?? 0 ) === currentUntil;
			return {
				...state,
				loaded: true,
				error: '',
				rev: response.rev,
				feedCursor: bootstrap ? ( response.entries[ response.entries.length - 1 ]?.id ?? 0 ) : state.feedCursor,
				live: response.live,
				user: response.user ?? state.user,
				team: response.team ?? state.team,
				hasMore: response.has_more ?? state.hasMore,
				autoEnd: response.auto_end,
				questionCount: response.question_count,
				entries,
				drafts: draftsCurrent ? response.drafts : state.drafts,
				draftsMore: draftsCurrent ? ( response.drafts_more ?? false ) : state.draftsMore,
				draftsTotal: response.drafts_total ?? response.drafts.length,
				draftsPaged: bootstrap ? false : state.draftsPaged,
			};
		}
		case 'RECEIVE_MORE': {
			if ( action.list === 'drafts' ) {
				const known = new Set( state.drafts.map( draft => draft.id ) );
				return {
					...state,
					drafts: [ ...state.drafts, ...action.items.filter( item => ! known.has( item.id ) ) ],
					draftsMore: action.hasMore,
					draftsPaged: true,
				};
			}
			// Older entries never replace what a refresh already brought.
			const entries = { ...state.entries };
			action.items.forEach( entry => {
				entries[ entry.id ] = entries[ entry.id ] ?? entry;
			} );
			return {
				...state,
				entries,
				hasMore: action.hasMore,
				feedCursor: action.items[ action.items.length - 1 ]?.id ?? state.feedCursor,
			};
		}
		case 'SET_PRESENCE':
			return { ...state, presence: action.users };
		case 'RECEIVE_ENTRY':
			return { ...placeEntry( state, action.entry ), writes: state.writes + 1 };
		case 'REMOVE_ENTRY': {
			const entries = { ...state.entries };
			delete entries[ action.id ];
			return {
				...state,
				writes: state.writes + 1,
				entries,
				drafts: state.drafts.filter( draft => draft.id !== action.id ),
				editing: state.editing?.id === action.id ? null : state.editing,
			};
		}
		case 'RECEIVE_LIVE':
			return { ...state, live: action.live };
		case 'SET_ERROR':
			return { ...state, error: action.error };
		case 'SET_EDITING':
			return { ...state, editing: action.entry, answering: action.entry ? [] : state.answering };
		case 'SET_ANSWERING':
			return { ...state, answering: action.questions, editing: action.questions.length ? null : state.editing };
		case 'SET_QUESTION_COUNT':
			return { ...state, questionCount: action.count };
		case 'SET_AUTO_END':
			return { ...state, autoEnd: action.autoEnd };
	}
	return state;
}

/**
 * Published entries, newest first (time, then ID). Memoized on the entries map.
 */
export const getFeed = createSelector(
	( state: State ): ConsoleEntry[] => Object.values( state.entries ).sort( ( a, b ) => b.time - a.time || b.id - a.id ),
	( state: State ) => [ state.entries ]
);

/**
 * Drafts with pending entries first. Memoized on the drafts list.
 */
export const getDrafts = createSelector(
	( state: State ): ConsoleEntry[] => [
		...state.drafts.filter( entry => entry.status === 'pending' ),
		...state.drafts.filter( entry => entry.status !== 'pending' ),
	],
	( state: State ) => [ state.drafts ]
);
