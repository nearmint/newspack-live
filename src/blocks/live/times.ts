/**
 * Entry time labels: the server renders them for the day it rendered the page; cached pages
 * and pages left open past midnight get their "today" / "yesterday" part corrected here, in the
 * site timezone, without a date library.
 */

export interface TimeStrings {
	/** "Yesterday, %s". */
	yesterday: string;
	/** "yesterday, %s", mid-sentence after "Updated". */
	yesterdayMid: string;
	/** "%1$s, %2$s" (date, time). */
	dateTime: string;
	/** Header line: "Updated %s" today. */
	updated: string;
	/** "Updated yesterday at %s". */
	updatedYesterday: string;
	/** "Updated %1$s at %2$s" (date, time). */
	updatedDate: string;
}

/**
 * Site date (`YYYY-MM-DD`) of a moment.
 *
 * @param now      Timestamp in milliseconds.
 * @param timezone Site timezone: an IANA name, "UTC", or an offset like "+05:30".
 * @return Date.
 */
export function siteDay( now: number, timezone: string ): string {
	const offset = /^([+-])(\d{2}):(\d{2})$/.exec( timezone );
	if ( offset || ! timezone || timezone === 'UTC' ) {
		const minutes = offset ? ( offset[ 1 ] === '-' ? -1 : 1 ) * ( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] ) ) : 0;
		return new Date( now + minutes * 60000 ).toISOString().slice( 0, 10 );
	}
	try {
		const parts: Record< string, string > = {};
		new Intl.DateTimeFormat( 'en-US', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' } )
			.formatToParts( now )
			.forEach( part => ( parts[ part.type ] = part.value ) );
		return `${ parts.year }-${ parts.month }-${ parts.day }`;
	} catch {
		return new Date( now ).toISOString().slice( 0, 10 );
	}
}

/**
 * The day before a `YYYY-MM-DD` date.
 *
 * @param day Date.
 * @return Previous date.
 */
export function previousDay( day: string ): string {
	return new Date( Date.parse( `${ day }T12:00:00Z` ) - 86400000 ).toISOString().slice( 0, 10 );
}

/**
 * Fills a translated template: positional (`%1$s`) and sequential (`%s`) placeholders.
 *
 * @param template Template.
 * @param values   Values.
 * @return Text.
 */
function fill( template: string, ...values: string[] ): string {
	let next = 0;
	return template.replace( /%(?:(\d+)\$)?s/g, ( _match, position?: string ) => values[ position ? Number( position ) - 1 : next++ ] ?? '' );
}

/**
 * Label of a `<time>` element rendered by Dates::time_element().
 *
 * @param data          Element dataset.
 * @param data.day      Its site date.
 * @param data.clock    Its time ("10:48 PM").
 * @param data.date     Its short date ("Sep 27").
 * @param data.dateYear Its short date with the year ("Sep 27, 2025").
 * @param data.mid      "1" when the label goes mid-sentence ("Updated yesterday, …").
 * @param data.format   "updated" for the header line.
 * @param today         Current site date.
 * @param strings       Templates.
 * @return Label.
 */
export function timeLabel(
	data: { day?: string; clock?: string; date?: string; dateYear?: string; mid?: string; format?: string },
	today: string,
	strings: TimeStrings
): string {
	const clock = data.clock ?? '';
	if ( data.format === 'updated' ) {
		if ( data.day === today ) {
			return fill( strings.updated, clock );
		}
		if ( data.day === previousDay( today ) ) {
			return fill( strings.updatedYesterday, clock );
		}
		return fill( strings.updatedDate, ( data.day?.slice( 0, 4 ) === today.slice( 0, 4 ) ? data.date : data.dateYear ) ?? '', clock );
	}
	if ( data.day === today ) {
		return clock;
	}
	if ( data.day === previousDay( today ) ) {
		return fill( data.mid ? strings.yesterdayMid : strings.yesterday, clock );
	}
	const date = data.day?.slice( 0, 4 ) === today.slice( 0, 4 ) ? data.date : data.dateYear;
	return fill( strings.dateTime, date ?? '', clock );
}

/**
 * Corrects every time label under a root: entries and the header line.
 *
 * @param root     Root.
 * @param now      Timestamp in milliseconds.
 * @param timezone Site timezone.
 * @param strings  Templates.
 */
export function refreshTimes( root: ParentNode, now: number, timezone: string, strings: TimeStrings ): void {
	const today = siteDay( now, timezone );
	root.querySelectorAll< HTMLElement >( 'time[data-day]' ).forEach( element => {
		const label = element.querySelector< HTMLElement >( '.newspack-live-time' );
		const text = timeLabel( element.dataset, today, strings );
		if ( label && text && label.textContent !== text ) {
			label.textContent = text;
		}
	} );
}
