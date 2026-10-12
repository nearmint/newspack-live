/**
 * Internal dependencies
 */
import { getDrafts, getFeed, initialState, reducer } from './reducer';
import { selectors } from './index';
import type { ConsoleEntry, ConsoleResponse } from '../types';

const entry = ( id: number, overrides: Partial< ConsoleEntry > = {} ): ConsoleEntry => ( {
	id,
	rev: id,
	status: 'publish',
	title: `Entry ${ id }`,
	content: '',
	excerpt: `Entry ${ id }`,
	time: 1000 + id,
	modified: 1000 + id,
	author: { id: 1, name: 'Ana' },
	pinned: false,
	question_ids: [],
	label: '',
	permalink: '',
	edit_link: '',
	can_edit: true,
	...overrides,
} );

const response = ( overrides: Partial< ConsoleResponse > = {} ): ConsoleResponse => ( {
	rev: 5,
	reset: false,
	live: {
		id: 1,
		title: 'Live',
		status: 'publish',
		state: 'live',
		url: '',
		edit_url: '',
		settings: { questions_mode: 'anyone', questions_open: true, show_entry_author: true },
		start: 0,
		end: 0,
		last_update: 0,
		upcoming_label: '',
		labels: [],
	},
	entries: [],
	removed: [],
	drafts: [],
	question_count: { nl_new: 0, nl_shortlisted: 0, nl_answered: 0, nl_rejected: 0, nl_spam: 0 },
	auto_end: { warning: false, at: 0, hours: 24 },
	...overrides,
} );

describe( 'console reducer', () => {
	it( 'bootstraps, then merges refreshes and removals', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1 ), entry( 2 ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 2, 1 ] );
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 7, entries: [ entry( 3 ) ], removed: [ 1 ] } ),
			bootstrap: false,
			writes: 0,
		} );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 3, 2 ] );
		expect( state.rev ).toBe( 7 );
	} );

	it( 'orders the feed by time, then ID', () => {
		const state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1, { time: 50 } ), entry( 2, { time: 10 } ), entry( 3, { time: 50 } ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 3, 1, 2 ] );
	} );

	it( 'keeps a single pinned entry', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1, { pinned: true } ), entry( 2 ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		state = reducer( state, { type: 'RECEIVE_ENTRY', entry: entry( 2, { pinned: true } ) } );
		expect(
			getFeed( state )
				.filter( e => e.pinned )
				.map( e => e.id )
		).toEqual( [ 2 ] );
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1, { pinned: true } ) ] } ),
			bootstrap: false,
			writes: state.writes,
		} );
		expect(
			getFeed( state )
				.filter( e => e.pinned )
				.map( e => e.id )
		).toEqual( [ 1 ] );
	} );

	it( 'moves entries between the feed and drafts, pending first', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1 ) ], drafts: [ entry( 2, { status: 'draft' } ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		state = reducer( state, { type: 'RECEIVE_ENTRY', entry: entry( 1, { status: 'draft' } ) } );
		state = reducer( state, { type: 'RECEIVE_ENTRY', entry: entry( 3, { status: 'pending' } ) } );
		expect( getFeed( state ) ).toEqual( [] );
		expect( getDrafts( state ).map( e => e.id ) ).toEqual( [ 3, 1, 2 ] );
		state = reducer( state, { type: 'RECEIVE_ENTRY', entry: entry( 3 ) } );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 3 ] );
		expect( getDrafts( state ).map( e => e.id ) ).toEqual( [ 1, 2 ] );
	} );

	it( 'editing and answering are exclusive', () => {
		let state = reducer( initialState, { type: 'SET_EDITING', entry: entry( 1 ) } );
		state = reducer( state, {
			type: 'SET_ANSWERING',
			questions: [ { id: 5, text: 'Why?', name: 'Ana', registered: false, time: 0, status: 'nl_new', answer_entry: 0 } ],
		} );
		expect( state.editing ).toBeNull();
		state = reducer( state, { type: 'SET_EDITING', entry: entry( 2 ) } );
		expect( state.answering ).toEqual( [] );
	} );

	it( 'removing the edited entry leaves edit mode', () => {
		let state = reducer( initialState, { type: 'SET_EDITING', entry: entry( 1 ) } );
		state = reducer( state, { type: 'REMOVE_ENTRY', id: 1 } );
		expect( state.editing ).toBeNull();
	} );

	it( 'ignores refreshes started before a local write, and older revisions', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1 ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		state = reducer( state, { type: 'REMOVE_ENTRY', id: 1 } );
		state = reducer( state, { type: 'RECEIVE_CONSOLE', response: response( { rev: 6, entries: [ entry( 1 ) ] } ), bootstrap: false, writes: 0 } );
		expect( getFeed( state ) ).toEqual( [] );
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 3, entries: [ entry( 1 ) ] } ),
			bootstrap: false,
			writes: state.writes,
		} );
		expect( getFeed( state ) ).toEqual( [] );
		expect( state.rev ).toBe( 5 );
	} );

	it( 'memoizes the feed', () => {
		const state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 1 ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( getFeed( state ) ).toBe( getFeed( state ) );
	} );
	it( 'appends older feed pages without replacing refreshed entries', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 60 ), entry( 59, { title: 'Fresh' } ) ], has_more: true } ),
			bootstrap: true,
			writes: 0,
		} );
		state = reducer( state, {
			type: 'RECEIVE_MORE',
			list: 'feed',
			items: [ entry( 59, { title: 'Stale' } ), entry( 58 ), entry( 57 ) ],
			hasMore: false,
		} );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 60, 59, 58, 57 ] );
		expect( state.entries[ 59 ].title ).toBe( 'Fresh' );
		expect( state.hasMore ).toBe( false );
	} );

	it( 'pages drafts and keeps them through refreshes down to the oldest loaded', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( {
				drafts: [ entry( 9, { status: 'draft' } ), entry( 8, { status: 'draft' } ) ],
				drafts_more: true,
				drafts_total: 4,
			} ),
			bootstrap: true,
			writes: 0,
		} );
		expect( selectors.getDraftsUntil( state ) ).toBe( 0 );
		expect( selectors.getDraftsTotal( state ) ).toBe( 4 );
		state = reducer( state, {
			type: 'RECEIVE_MORE',
			list: 'drafts',
			items: [ entry( 8, { status: 'draft' } ), entry( 7, { status: 'pending' } ), entry( 6, { status: 'draft' } ) ],
			hasMore: false,
		} );
		expect( state.drafts.map( e => e.id ) ).toEqual( [ 9, 8, 7, 6 ] );
		expect( getDrafts( state ).map( e => e.id ) ).toEqual( [ 7, 9, 8, 6 ] );
		expect( selectors.getDraftsUntil( state ) ).toBe( 6 );
		expect( selectors.getRawDraftsLast( state ) ).toBe( 6 );
		expect( selectors.hasMoreDrafts( state ) ).toBe( false );
		// A refresh (not a bootstrap) keeps the paged mode; a bootstrap resets it.
		state = reducer( state, { type: 'RECEIVE_CONSOLE', response: response( { rev: 6, drafts: state.drafts } ), bootstrap: false, writes: 0 } );
		expect( selectors.getDraftsUntil( state ) ).toBe( 6 );
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { drafts: [ entry( 9, { status: 'draft' } ) ] } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( selectors.getDraftsUntil( state ) ).toBe( 0 );
	} );

	it( 'stores who is online', () => {
		const users = [ { id: 1, name: 'Ana', avatar: '', self: true } ];
		expect( selectors.getPresence( reducer( initialState, { type: 'SET_PRESENCE', users } ) ) ).toEqual( users );
	} );
	it( 'keeps the feed cursor on the oldest page loaded, whatever refreshes bring', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { entries: [ entry( 150 ), entry( 101 ) ], has_more: true } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( selectors.getFeedCursor( state ) ).toBe( 101 );
		// An old entry edited by a colleague arrives with a refresh: the cursor doesn't move.
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 6, entries: [ entry( 10 ) ] } ),
			bootstrap: false,
			writes: 0,
		} );
		expect( getFeed( state ).map( e => e.id ) ).toEqual( [ 150, 101, 10 ] );
		expect( selectors.getFeedCursor( state ) ).toBe( 101 );
		state = reducer( state, { type: 'RECEIVE_MORE', list: 'feed', items: [ entry( 100 ), entry( 51 ) ], hasMore: true } );
		expect( selectors.getFeedCursor( state ) ).toBe( 51 );
	} );

	it( 'ignores drafts from a refresh requested before "Load more"', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { drafts: [ entry( 9, { status: 'draft' } ) ], drafts_more: true } ),
			bootstrap: true,
			writes: 0,
		} );
		state = reducer( state, { type: 'RECEIVE_MORE', list: 'drafts', items: [ entry( 8, { status: 'draft' } ) ], hasMore: false } );
		// Requested with drafts_until=0 (before the load): the loaded page stays.
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 6, drafts: [ entry( 9, { status: 'draft' } ) ], drafts_more: true } ),
			bootstrap: false,
			writes: 0,
			draftsUntil: 0,
		} );
		expect( state.drafts.map( e => e.id ) ).toEqual( [ 9, 8 ] );
		expect( selectors.hasMoreDrafts( state ) ).toBe( false );
		// Requested down to the last draft loaded: applied.
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( {
				rev: 7,
				drafts: [ entry( 10, { status: 'draft' } ), entry( 9, { status: 'draft' } ), entry( 8, { status: 'draft' } ) ],
			} ),
			bootstrap: false,
			writes: 0,
			draftsUntil: 8,
		} );
		expect( state.drafts.map( e => e.id ) ).toEqual( [ 10, 9, 8 ] );
	} );
	it( 'shows "Load more" on Drafts only while the server reports a next draft', () => {
		let state = reducer( initialState, {
			type: 'RECEIVE_CONSOLE',
			response: response( { drafts: [ entry( 9, { status: 'draft' } ) ], drafts_more: true } ),
			bootstrap: true,
			writes: 0,
		} );
		expect( selectors.hasMoreDrafts( state ) ).toBe( true );
		state = reducer( state, { type: 'RECEIVE_MORE', list: 'drafts', items: [ entry( 8, { status: 'draft' } ) ], hasMore: false } );
		expect( selectors.hasMoreDrafts( state ) ).toBe( false );
		// A refresh down to the last draft loaded keeps the server's answer (no extra item: no button).
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 6, drafts: [ entry( 9, { status: 'draft' } ), entry( 8, { status: 'draft' } ) ], drafts_more: false } ),
			bootstrap: false,
			writes: 0,
			draftsUntil: 8,
		} );
		expect( selectors.hasMoreDrafts( state ) ).toBe( false );
		// A missing flag means no next draft.
		state = reducer( state, {
			type: 'RECEIVE_CONSOLE',
			response: response( { rev: 7, drafts: [ entry( 9, { status: 'draft' } ), entry( 8, { status: 'draft' } ) ] } ),
			bootstrap: false,
			writes: 0,
			draftsUntil: 8,
		} );
		expect( selectors.hasMoreDrafts( state ) ).toBe( false );
	} );
} );
