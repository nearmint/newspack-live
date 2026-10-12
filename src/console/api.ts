/**
 * Console REST calls (cookie auth; apiFetch adds the nonce in wp-admin).
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from '../shared/api';
import type { ConsoleEntry, ConsolePage, CustomLabel, ConsoleResponse, EntryInput, LivePayload, Question, QuestionStatus } from './types';

interface EntryResponse {
	entry: ConsoleEntry;
	rev: number;
}

/**
 * Retries once when the live is busy (`newspack_live_try_again`).
 *
 * @param request Request factory.
 * @return Response.
 */
async function withRetry< T >( request: () => Promise< T > ): Promise< T > {
	try {
		return await request();
	} catch ( error ) {
		if ( ( error as { code?: string } )?.code === 'newspack_live_try_again' ) {
			return request();
		}
		throw error;
	}
}

export const fetchConsole = ( liveId: number, since?: number, draftsUntil = 0 ) => {
	const params = new URLSearchParams();
	if ( since !== undefined ) {
		params.set( 'since', String( since ) );
	}
	if ( draftsUntil > 0 ) {
		params.set( 'drafts_until', String( draftsUntil ) );
	}
	const query = params.toString();
	return apiFetch< ConsoleResponse >( { path: `${ NAMESPACE }/lives/${ liveId }/console${ query ? `?${ query }` : '' }` } );
};

/**
 * The next 50 entries of the Feed or Drafts list after a cursor.
 *
 * @param liveId Live ID.
 * @param list   List.
 * @param before Cursor (the last entry loaded).
 * @return Page.
 */
export const fetchConsoleEntries = ( liveId: number, list: 'feed' | 'drafts', before: number ) =>
	apiFetch< ConsolePage< ConsoleEntry > >( {
		path: `${ NAMESPACE }/lives/${ liveId }/console/entries?${ new URLSearchParams( { list, before: String( before ) } ).toString() }`,
	} );

export const createEntry = ( liveId: number, data: EntryInput ) =>
	withRetry( () => apiFetch< EntryResponse >( { path: `${ NAMESPACE }/lives/${ liveId }/entries`, method: 'POST', data } ) );

export const updateEntry = ( entryId: number, data: EntryInput ) =>
	withRetry( () => apiFetch< EntryResponse >( { path: `${ NAMESPACE }/entries/${ entryId }`, method: 'PUT', data } ) );

export const setFlags = ( entryId: number, data: { pinned: boolean } ) =>
	withRetry( () => apiFetch< EntryResponse >( { path: `${ NAMESPACE }/entries/${ entryId }/flags`, method: 'POST', data } ) );

export const setEntryStatus = ( entryId: number, status: 'publish' | 'draft' | 'trash' ) =>
	withRetry( () => apiFetch< EntryResponse >( { path: `${ NAMESPACE }/entries/${ entryId }/status`, method: 'POST', data: { status } } ) );

export const createLabel = ( liveId: number, text: string ) =>
	withRetry( () =>
		apiFetch< { label: CustomLabel; labels: CustomLabel[] } >( {
			path: `${ NAMESPACE }/lives/${ liveId }/labels`,
			method: 'POST',
			data: { text },
		} )
	);

export const deleteLabel = ( liveId: number, id: string ) =>
	withRetry( () =>
		apiFetch< { deleted: boolean; labels: CustomLabel[] } >( {
			path: `${ NAMESPACE }/lives/${ liveId }/labels/${ encodeURIComponent( id ) }`,
			method: 'DELETE',
		} )
	);

export const setState = ( liveId: number, action: 'go_live' | 'end' | 'reopen' | 'keep_live' ) =>
	withRetry( () =>
		apiFetch< { live: LivePayload; rev: number } >( { path: `${ NAMESPACE }/lives/${ liveId }/state`, method: 'POST', data: { action } } )
	);

export const setQuestionsOpen = ( liveId: number, open: boolean ) =>
	withRetry( () =>
		apiFetch< { live: LivePayload; rev: number } >( {
			path: `${ NAMESPACE }/lives/${ liveId }/settings`,
			method: 'POST',
			data: { questions_open: open },
		} )
	);

export interface QuestionQuery {
	status: QuestionStatus | 'all';
	search: string;
	/** The page after this question (Load more). */
	before?: number;
	/** Everything down to this question (refresh of the pages loaded). */
	until?: number;
}

export const fetchQuestions = ( liveId: number, query: QuestionQuery ) => {
	const params = new URLSearchParams( { status: query.status } );
	if ( query.search ) {
		params.set( 'search', query.search );
	}
	if ( query.before ) {
		params.set( 'before', String( query.before ) );
	}
	if ( query.until ) {
		params.set( 'until', String( query.until ) );
	}
	return apiFetch< ConsolePage< Question > & { count: Record< QuestionStatus, number > } >( {
		path: `${ NAMESPACE }/lives/${ liveId }/questions?${ params.toString() }`,
	} );
};

export const ungroupQuestion = ( liveId: number, questionId: number ) =>
	apiFetch< { ungrouped: boolean } >( { path: `${ NAMESPACE }/lives/${ liveId }/questions/${ questionId }/ungroup`, method: 'POST' } );

export const bulkQuestions = ( liveId: number, ids: number[], status: Exclude< QuestionStatus, 'nl_answered' > ) =>
	apiFetch< { updated: number[]; count: Record< QuestionStatus, number > } >( {
		path: `${ NAMESPACE }/lives/${ liveId }/questions/bulk`,
		method: 'POST',
		data: { ids, status },
	} );
