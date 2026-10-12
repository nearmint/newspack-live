/**
 * Pure composer helpers (unit-tested).
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getDate } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Seconds of clock skew tolerated for entry times.
 */
export const FUTURE_TOLERANCE = 60;

export interface ComposerDraft {
	title: string;
	content: string;
	time: number | null;
	pinned: boolean;
	/** Entry label; may be absent in an autosave. */
	label?: string;
	editingId: number;
	questionIds: number[];
	savedAt: number;
}

/**
 * Whether block markup has visible content (text, media, embeds or article cards).
 *
 * @param content Serialized blocks.
 * @return True when not empty.
 */
export function hasBody( content: string ): boolean {
	const text = content
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&nbsp;/g, ' ' )
		.trim();
	if ( text ) {
		return true;
	}
	return /<(img|iframe|video|audio|table)\b|<!--\s+wp:(embed|newspack-live\/article-card|image|gallery|video)\b/i.test( content );
}

/**
 * Validates a composer submission.
 *
 * @param input         Fields.
 * @param input.title   Title.
 * @param input.content Serialized body.
 * @param input.time    Chosen time (UTC seconds) or null for "now".
 * @param now           Current time (UTC seconds).
 * @return Error message, or '' when valid.
 */
export function validate( input: { title: string; content: string; time: number | null }, now = Math.floor( Date.now() / 1000 ) ): string {
	if ( ! input.title.trim() && ! hasBody( input.content ) ) {
		return __( 'Add a title or some content before publishing.', 'newspack-live' );
	}
	if ( input.time !== null && input.time > now + FUTURE_TOLERANCE ) {
		return __( "The time can't be in the future.", 'newspack-live' );
	}
	return '';
}

/**
 * Q&A greeting: "Hi {names}," with names joined like the byline.
 *
 * @param names Display names.
 * @return Greeting.
 */
export function greeting( names: string[] ): string {
	const unique = Array.from( new Set( names.filter( Boolean ) ) );
	let joined = unique[ 0 ] ?? '';
	if ( unique.length > 1 ) {
		joined = sprintf(
			/* translators: 1: comma-separated names, 2: last name. */
			__( '%1$s and %2$s', 'newspack-live' ),
			unique.slice( 1, -1 ).reduce(
				( list, name ) =>
					sprintf(
						/* translators: 1: names so far, 2: next name. */
						__( '%1$s, %2$s', 'newspack-live' ),
						list,
						name
					),
				unique[ 0 ]
			),
			unique[ unique.length - 1 ]
		);
	}
	const template = /* translators: %s: reader names. */ __( 'Hi %s,', 'newspack-live' );
	return sprintf( template, joined );
}

/**
 * localStorage key of the local autosave.
 *
 * @param liveId Live ID.
 * @param userId User ID.
 * @return Key.
 */
export const autosaveKey = ( liveId: number, userId: number ) => `newspack-live:${ liveId }:${ userId }`;

/**
 * Reads the local autosave; storage may be unavailable (private mode), so failures return null.
 *
 * @param key Storage key.
 * @return Draft or null.
 */
export function readAutosave( key: string ): ComposerDraft | null {
	try {
		const raw = window.localStorage.getItem( key );
		return raw ? ( JSON.parse( raw ) as ComposerDraft ) : null;
	} catch {
		return null;
	}
}

/**
 * Writes or clears the local autosave.
 *
 * @param key   Storage key.
 * @param draft Draft, or null to clear.
 */
export function writeAutosave( key: string, draft: ComposerDraft | null ): void {
	try {
		if ( draft ) {
			window.localStorage.setItem( key, JSON.stringify( draft ) );
		} else {
			window.localStorage.removeItem( key );
		}
	} catch {
		// Storage unavailable: autosave is best effort.
	}
}

/**
 * Converts a `datetime-local` value, read in the site timezone (like every date the newsroom sees),
 * to a UTC timestamp.
 *
 * @param value Input value.
 * @return Seconds, or null when empty or invalid.
 */
export function fromLocalInput( value: string ): number | null {
	if ( ! value ) {
		return null;
	}
	const ms = getDate( value ).getTime();
	return Number.isNaN( ms ) ? null : Math.floor( ms / 1000 );
}

/**
 * Converts a UTC timestamp to a `datetime-local` value in the site timezone.
 *
 * @param seconds Timestamp.
 * @return Input value.
 */
export function toLocalInput( seconds: number ): string {
	return dateI18n( 'Y-m-d\\TH:i', seconds * 1000, undefined );
}
