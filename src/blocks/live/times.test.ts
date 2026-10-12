/**
 * Internal dependencies
 */
import { previousDay, refreshTimes, siteDay, timeLabel } from './times';

const STRINGS = {
	yesterday: 'Yesterday, %s',
	yesterdayMid: 'yesterday, %s',
	dateTime: '%1$s, %2$s',
	updated: 'Updated %s',
	updatedYesterday: 'Updated yesterday at %s',
	updatedDate: 'Updated %1$s at %2$s',
};

const element = ( day: string ) =>
	`<time data-day="${ day }" data-clock="10:48 PM" data-date="Sep 27" data-date-year="Sep 27, 2025"><span class="newspack-live-time">x</span></time>`;

describe( 'entry time labels', () => {
	it( 'computes the site day in IANA and offset timezones', () => {
		// 2026-10-06 03:30 UTC is still Oct 5 in New York, already Oct 6 in Kolkata.
		const now = Date.parse( '2026-10-06T03:30:00Z' );
		expect( siteDay( now, 'America/New_York' ) ).toBe( '2026-10-05' );
		expect( siteDay( now, 'Asia/Kolkata' ) ).toBe( '2026-10-06' );
		expect( siteDay( now, '-05:00' ) ).toBe( '2026-10-05' );
		expect( siteDay( now, '+05:30' ) ).toBe( '2026-10-06' );
		expect( siteDay( now, 'UTC' ) ).toBe( '2026-10-06' );
		expect( previousDay( '2026-03-01' ) ).toBe( '2026-02-28' );
	} );

	it( 'labels today, yesterday, earlier and other-year entries', () => {
		const base = { clock: '10:48 PM', date: 'Sep 27', dateYear: 'Sep 27, 2025' };
		expect( timeLabel( { ...base, day: '2026-10-05' }, '2026-10-05', STRINGS ) ).toBe( '10:48 PM' );
		expect( timeLabel( { ...base, day: '2026-10-04' }, '2026-10-05', STRINGS ) ).toBe( 'Yesterday, 10:48 PM' );
		expect( timeLabel( { ...base, day: '2026-09-27' }, '2026-10-05', STRINGS ) ).toBe( 'Sep 27, 10:48 PM' );
		expect( timeLabel( { ...base, day: '2025-09-27' }, '2026-10-05', STRINGS ) ).toBe( 'Sep 27, 2025, 10:48 PM' );
	} );

	it( 'corrects labels across midnight with a fixed clock (site in New York)', () => {
		document.body.innerHTML = element( '2026-10-05' ) + element( '2026-10-04' );
		const labels = () => Array.from( document.querySelectorAll( '.newspack-live-time' ) ).map( node => node.textContent );

		// 23:59 in New York on Oct 5.
		refreshTimes( document, Date.parse( '2026-10-06T03:59:00Z' ), 'America/New_York', STRINGS );
		expect( labels() ).toEqual( [ '10:48 PM', 'Yesterday, 10:48 PM' ] );

		// 00:01 on Oct 6: the page stayed open past midnight.
		refreshTimes( document, Date.parse( '2026-10-06T04:01:00Z' ), 'America/New_York', STRINGS );
		expect( labels() ).toEqual( [ 'Yesterday, 10:48 PM', 'Sep 27, 10:48 PM' ] );
	} );

	it( 'accepts positional and sequential placeholders', () => {
		const base = { clock: '10:48 PM', date: 'Sep 27', dateYear: 'Sep 27, 2025' };
		expect( timeLabel( { ...base, day: '2026-10-04' }, '2026-10-05', { ...STRINGS, yesterday: 'Hier, %1$s' } ) ).toBe( 'Hier, 10:48 PM' );
		expect( timeLabel( { ...base, day: '2026-09-27' }, '2026-10-05', { ...STRINGS, dateTime: '%2$s · %1$s' } ) ).toBe( '10:48 PM · Sep 27' );
	} );

	it( 'uses lowercase "yesterday" mid-sentence (after "Updated")', () => {
		const strings = { ...STRINGS, yesterdayMid: 'yesterday, %s' };
		const base = { clock: '10:48 PM', date: 'Sep 27', dateYear: 'Sep 27, 2025', day: '2026-10-04' };
		expect( timeLabel( { ...base, mid: '1' }, '2026-10-05', strings ) ).toBe( 'yesterday, 10:48 PM' );
		expect( timeLabel( base, '2026-10-05', strings ) ).toBe( 'Yesterday, 10:48 PM' );
	} );

	it( 'labels the header line for today, yesterday, this year and another year, across midnight', () => {
		const strings = { ...STRINGS, updated: 'Updated %s', updatedYesterday: 'Updated yesterday at %s', updatedDate: 'Updated %1$s at %2$s' };
		const base = { clock: '8:57 am', date: 'Sep 27', dateYear: 'Sep 27, 2025', format: 'updated' };
		expect( timeLabel( { ...base, day: '2026-10-05' }, '2026-10-05', strings ) ).toBe( 'Updated 8:57 am' );
		expect( timeLabel( { ...base, day: '2026-10-04' }, '2026-10-05', strings ) ).toBe( 'Updated yesterday at 8:57 am' );
		expect( timeLabel( { ...base, day: '2026-09-27' }, '2026-10-05', strings ) ).toBe( 'Updated Sep 27 at 8:57 am' );
		expect( timeLabel( { ...base, day: '2025-09-27' }, '2026-10-05', strings ) ).toBe( 'Updated Sep 27, 2025 at 8:57 am' );

		// A page left open past midnight in New York (UTC-4): today, then yesterday.
		document.body.innerHTML =
			'<time data-format="updated" data-day="2026-10-05" data-clock="8:57 am" data-date="Oct 5" data-date-year="Oct 5, 2026"><span class="newspack-live-time">x</span></time>';
		const label = () => document.querySelector( '.newspack-live-time' )?.textContent;
		refreshTimes( document, Date.parse( '2026-10-06T03:59:00Z' ), 'America/New_York', strings );
		expect( label() ).toBe( 'Updated 8:57 am' );
		refreshTimes( document, Date.parse( '2026-10-06T04:01:00Z' ), 'America/New_York', strings );
		expect( label() ).toBe( 'Updated yesterday at 8:57 am' );
	} );
} );
