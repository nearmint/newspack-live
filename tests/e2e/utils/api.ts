/**
 * Authenticated REST clients: cookie auth + REST nonce. No application passwords.
 */

/**
 * External dependencies
 */
import { request, type APIRequestContext } from '@playwright/test';

/**
 * Internal dependencies
 */
import { BASE_URL, storageStatePath, type Role } from './env';

export const API = '/wp-json/newspack-live/v1';

/**
 * A request context logged in as a role, sending its REST nonce.
 *
 * @param role Role, or null for an anonymous context.
 * @return Request context.
 */
export async function apiContext( role: Role | null ): Promise< APIRequestContext > {
	if ( ! role ) {
		return request.newContext( { baseURL: BASE_URL } );
	}
	const cookies = await request.newContext( { baseURL: BASE_URL, storageState: storageStatePath( role ) } );
	const nonceResponse = await cookies.get( '/wp-admin/admin-ajax.php?action=rest-nonce' );
	const nonce = ( await nonceResponse.text() ).trim();
	await cookies.dispose();
	if ( ! /^[a-f0-9]{10}$/.test( nonce ) ) {
		throw new Error( `No REST nonce for ${ role }: ${ nonce.slice( 0, 80 ) }` );
	}
	return request.newContext( {
		baseURL: BASE_URL,
		storageState: storageStatePath( role ),
		extraHTTPHeaders: { 'X-WP-Nonce': nonce },
	} );
}
