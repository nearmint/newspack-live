/**
 * Typed helpers for the plugin's REST API (admin side: cookie auth, nonce added by apiFetch).
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export const NAMESPACE = '/newspack-live/v1';

export type LiveState = 'none' | 'draft' | 'upcoming' | 'live' | 'ended';

export interface LiveStatus {
	state: LiveState;
	rev: number;
	last_update: number;
	updated_label: string;
	badge_label: string;
	poll_interval: number;
	questions_open: boolean;
	questions_mode: 'off' | 'anyone' | 'registered';
	auto_end?: { warning: boolean; at: number; hours: number; message: string };
	hosts?: string[];
}

export interface TeamCandidate {
	id: number;
	name: string;
	avatar: string;
}

/**
 * Fetches the status of a live.
 *
 * @param liveId Live ID.
 * @return Status.
 */
export function fetchStatus( liveId: number ): Promise< LiveStatus > {
	return apiFetch< LiveStatus >( { path: `${ NAMESPACE }/lives/${ liveId }/status` } );
}

/**
 * Applies a state action.
 *
 * @param liveId Live ID.
 * @param action Action.
 * @return Response.
 */
export function postState( liveId: number, action: 'go_live' | 'end' | 'reopen' | 'keep_live' ): Promise< unknown > {
	return apiFetch( { path: `${ NAMESPACE }/lives/${ liveId }/state`, method: 'POST', data: { action } } );
}

/**
 * Searches users who can post entries, or resolves IDs.
 *
 * @param search  Search string.
 * @param include IDs to resolve.
 * @return Users.
 */
export function fetchTeamCandidates( search: string, include: number[] = [] ): Promise< TeamCandidate[] > {
	const params = new URLSearchParams();
	if ( search ) {
		params.set( 'search', search );
	}
	include.forEach( id => params.append( 'include[]', String( id ) ) );
	return apiFetch< TeamCandidate[] >( { path: `${ NAMESPACE }/team-candidates?${ params.toString() }` } );
}
