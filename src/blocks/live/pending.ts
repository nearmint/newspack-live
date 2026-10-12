/**
 * New updates waiting behind the "new updates" button: count cap, button label, tab title.
 * Pure helpers, unit-tested.
 */

/**
 * Internal dependencies
 */
import { pluralize, type Plural } from './plural';

/**
 * Above this many waiting updates, the button reads "20+ new updates" and reloads the page.
 */
export const PENDING_CAP = 20;

/**
 * Whether the waiting updates are past the cap.
 *
 * @param count Waiting updates.
 * @return True above the cap.
 */
export function isCapped( count: number ): boolean {
	return count > PENDING_CAP;
}

/**
 * Text for a count of waiting updates: the plural form, or the capped text above the cap.
 *
 * @param count  Waiting updates.
 * @param plural Plural forms ("%d new updates").
 * @param capped Text above the cap ("20+ new updates").
 * @return Text, '' when nothing waits.
 */
export function pendingText( count: number, plural: Plural | undefined, capped: string ): string {
	if ( count <= 0 ) {
		return '';
	}
	if ( isCapped( count ) ) {
		return capped;
	}
	return plural ? pluralize( plural.forms, plural.table, count ) : String( count );
}

/**
 * The page title with the waiting count in front ("(3) Title", "(20+) Title"), or the title alone.
 *
 * @param title Original title.
 * @param count Waiting updates.
 * @return Title.
 */
export function titleWithCount( title: string, count: number ): string {
	if ( count <= 0 ) {
		return title;
	}
	return `(${ isCapped( count ) ? `${ PENDING_CAP }+` : count }) ${ title }`;
}
