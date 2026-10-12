/**
 * Who's online: the team members working on this live, through the WordPress
 * Heartbeat API: every 15 s while the console is focused, every 30 s while it is visible but not
 * focused (core would wait 120 s); a hidden tab sends nothing and drops off within 60 s. Closing or
 * leaving the console leaves at once.
 */

/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { config } from '../config';
import { store } from '../store';
import type { PresenceUser } from '../types';

const KEY = 'newspack_live_presence';
const NS = '.newspackLivePresence';

interface HeartbeatApi {
	connectNow: () => void;
}

const VISIBLE_UNFOCUSED_PING = 30_000;

interface JQueryDocument {
	on: ( events: string, handler: ( event: unknown, data: Record< string, unknown > ) => void ) => JQueryDocument;
	off: ( events: string ) => JQueryDocument;
}

declare global {
	interface Window {
		jQuery?: ( target: Document ) => JQueryDocument;
		wp?: { heartbeat?: HeartbeatApi; apiFetch?: { nonceMiddleware?: { nonce?: string } } } & Record< string, unknown >;
		wpApiSettings?: { nonce?: string };
	}
}

/**
 * Sends the console's presence with each heartbeat and stores who is on the live.
 *
 * @param liveId Live ID.
 */
export function usePresence( liveId: number ): void {
	const { setPresence } = useDispatch( store );
	useEffect( () => {
		const heartbeat = window.wp?.heartbeat;
		const $ = window.jQuery;
		if ( ! heartbeat || ! $ || ! liveId ) {
			return;
		}
		$( document )
			.on( `heartbeat-send${ NS }`, ( _event, data ) => {
				// A hidden tab is away: it sends nothing and drops off the list.
				if ( document.visibilityState === 'visible' ) {
					data[ KEY ] = { live: liveId };
				}
			} )
			.on( `heartbeat-tick${ NS }`, ( _event, data ) => {
				const payload = data?.[ KEY ] as { live: number; users: PresenceUser[] } | undefined;
				if ( payload && payload.live === liveId ) {
					setPresence( payload.users );
				}
			} );
		heartbeat.connectNow();
		// Core beats every 120 s while the window isn't focused: keep a visible console listed.
		const ping = window.setInterval( () => {
			if ( document.visibilityState === 'visible' && ! document.hasFocus() ) {
				heartbeat.connectNow();
			}
		}, VISIBLE_UNFOCUSED_PING );
		const leave = () => {
			if ( ! config.presenceLeave || typeof navigator.sendBeacon !== 'function' ) {
				return;
			}
			// The current REST nonce: core's heartbeat refreshes `wpApiSettings.nonce`; apiFetch's middleware
			// refreshes after a failed call. The page's own one may have expired.
			const url = new URL( config.presenceLeave );
			const nonce = window.wpApiSettings?.nonce || window.wp?.apiFetch?.nonceMiddleware?.nonce;
			if ( nonce ) {
				url.searchParams.set( '_wpnonce', nonce );
			}
			navigator.sendBeacon( url.toString() );
		};
		const back = () => {
			if ( document.visibilityState === 'visible' ) {
				heartbeat.connectNow();
			}
		};
		window.addEventListener( 'pagehide', leave );
		window.addEventListener( 'pageshow', back );
		document.addEventListener( 'visibilitychange', back );
		return () => {
			window.clearInterval( ping );
			$( document ).off( NS );
			window.removeEventListener( 'pagehide', leave );
			window.removeEventListener( 'pageshow', back );
			document.removeEventListener( 'visibilitychange', back );
		};
	}, [ liveId ] );
}

/**
 * The list of people on the live (shown once the first heartbeat answered).
 *
 * @return Element.
 */
export default function Presence() {
	const users = useSelect( select => select( store ).getPresence(), [] );
	if ( ! users.length ) {
		return null;
	}
	return (
		<div className="newspack-live-presence">
			<span className="newspack-live-presence__label" id="newspack-live-presence-label">
				{ __( 'On this live:', 'newspack-live' ) }
			</span>
			<ul className="newspack-live-presence__list" aria-labelledby="newspack-live-presence-label">
				{ users.map( user => (
					<li key={ user.id } className="newspack-live-presence__user">
						{ user.avatar && <img src={ user.avatar } alt="" width={ 24 } height={ 24 } className="newspack-live-presence__avatar" /> }
						<span>
							{ user.self
								? sprintf(
										/* translators: %s: the current user's display name. */
										__( '%s (you)', 'newspack-live' ),
										user.name
									)
								: user.name }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}
