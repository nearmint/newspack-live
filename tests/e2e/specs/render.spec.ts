/**
 * Server rendering.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addEntry, addFeaturedImage, addQuestion, attachmentUrl, cleanE2E, createLive, demoStoryId, permalink } from '../utils/live';

const para = ( text: string ) => `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

test.describe( 'render', () => {
	test.afterAll( () => cleanE2E() );

	test( 'a live with every module renders the full layout', async ( { page } ) => {
		const live = createLive( {
			title: 'E2E election night',
			excerpt: 'Results as they come in.',
			content: para( 'Live results from the county elections office.' ),
			context: '<p>What: the 2026 municipal election.</p>',
		} );
		const photo = addFeaturedImage( live );
		const photoUrl = attachmentUrl( photo );

		const question = addQuestion( live, 'When will results be final?', 'Ana' );
		addEntry( live, { title: 'Pinned note', content: para( 'Read this first.' ), pinned: true, time: '-50 minutes' } );
		addEntry( live, { title: 'Standard update', content: para( 'Polls are open.' ), time: '-45 minutes' } );
		addEntry( live, { title: 'Key update', content: para( 'Turnout is high.' ), time: '-40 minutes' } );
		addEntry( live, { title: 'Concession', content: para( 'A candidate conceded.' ), time: '-35 minutes' } );
		addEntry( live, { title: '', content: para( 'Hi Ana, tonight.' ), questionIds: [ question ], time: '-30 minutes' } );
		addEntry( live, {
			title: 'Media update',
			content: `<!-- wp:image --><figure class="wp-block-image"><img src="${ photoUrl }" alt="Ballots"/><figcaption class="wp-element-caption">Ballots</figcaption></figure><!-- /wp:image -->`,
			time: '-25 minutes',
		} );
		addEntry( live, {
			title: 'Quote update',
			content:
				'<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Every vote counts.</p><!-- /wp:paragraph --><cite>The clerk</cite></blockquote><!-- /wp:quote -->',
			time: '-20 minutes',
		} );
		addEntry( live, {
			title: 'Card update',
			content: `<!-- wp:newspack-live/article-card {"postId":${ demoStoryId() }} /-->`,
			time: '-15 minutes',
		} );
		const storyUrl = permalink( demoStoryId() );
		addEntry( live, {
			title: 'Embed update',
			content: `<!-- wp:embed {"url":"${ storyUrl }","type":"wp-embed","providerNameSlug":"newspack"} --><figure class="wp-block-embed is-type-wp-embed"><div class="wp-block-embed__wrapper">\n${ storyUrl }\n</div></figure><!-- /wp:embed -->`,
			time: '-17 minutes',
		} );

		const response = await page.goto( permalink( live ) );
		expect( response?.status() ).toBe( 200 );
		const root = page.locator( '.newspack-live' );

		// Header.
		await expect( root.locator( '.newspack-live-badge--live' ) ).toHaveText( /Live/ );
		// "Updated {time}", no "today"; the full date is read by assistive technology.
		await expect( root.locator( '.newspack-live__updated .newspack-live-time' ) ).toHaveText( /^Updated \d{1,2}:\d{2}\s?[ap]m$/i );
		await expect( root.locator( '.newspack-live__updated .newspack-live__sr-only' ) ).toHaveText( /^Updated .+ at / );
		await expect( page.getByRole( 'heading', { level: 1, name: 'E2E election night' } ) ).toBeVisible();
		await expect( root.locator( '.newspack-live__standfirst' ) ).toHaveText( 'Results as they come in.' );
		await expect( root.locator( '.newspack-live__byline' ) ).toContainText( 'Live hosted by' );
		await expect( root.locator( '.newspack-live__header summary.newspack-live-share__button' ) ).toHaveAccessibleName( 'Share' );
		await expect( root.locator( '.newspack-live__media img' ) ).toBeVisible();
		await expect( root.locator( '.newspack-live__media-caption' ) ).toContainText( 'Voters line up at City Hall.' );
		await expect( root.locator( '.newspack-live__media-credit' ) ).toHaveText( 'Jane Photographer / Riverton Ledger' );

		// The description (post content) under the header, then the Context card (its own field),
		// then the form.
		await expect( root.locator( '.newspack-live__main > .newspack-live__description:first-child' ) ).toHaveText(
			'Live results from the county elections office.'
		);
		await expect( page.getByRole( 'heading', { level: 2, name: 'Context' } ) ).toBeVisible();
		await expect( root.locator( '.newspack-live__context' ) ).toContainText( 'the 2026 municipal election' );
		await expect( root.locator( '.newspack-live__description + .newspack-live__context + .newspack-live__ask' ) ).toHaveCount( 1 );
		await expect( root.locator( '.newspack-live__aside .newspack-live__context' ) ).toHaveCount( 0 );
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		await expect( page.getByLabel( 'Name to display' ) ).toBeVisible();

		// Feed: pinned first, then newest first; every module.
		const pinned = root.locator( '.newspack-live__pinned .newspack-live-entry' );
		await expect( pinned ).toHaveCount( 1 );
		await expect( pinned ).toContainText( 'Pinned note' );
		await expect( pinned.locator( '.newspack-live-entry__card .newspack-live-entry__pinned' ) ).toHaveText( 'Pinned' );
		const entries = root.locator( '.newspack-live__entries > .newspack-live-entry' );
		await expect( entries ).toHaveCount( 8 );
		const times = await entries.evaluateAll( els => els.map( el => Number( ( el as HTMLElement ).dataset.time ) ) );
		expect( times ).toEqual( [ ...times ].sort( ( a, b ) => b - a ) );
		await expect( entries.first() ).toContainText( 'Card update' );
		const qa = root.locator( '.newspack-live-entry--qa' );
		await expect( qa.locator( '.newspack-live-qa__question' ) ).toContainText( 'When will results be final?' );
		await expect( qa.locator( '.newspack-live-qa__name' ) ).toHaveText( 'Ana' );
		// Answers carry the automatic "Your questions" label.
		await expect( qa.locator( '.newspack-live-entry__label' ) ).toHaveText( ', Your questions' );
		await expect( root.locator( '.newspack-live-entry figure.wp-block-image img' ) ).toBeVisible();
		await expect( root.locator( '.newspack-live-entry blockquote.wp-block-quote' ) ).toContainText( 'Every vote counts.' );
		await expect( root.locator( '.newspack-live-card' ) ).toContainText( 'Read the story' );
		await expect( root.locator( '.newspack-live-card h4' ) ).toBeVisible();
		await expect( root.locator( '.newspack-live-card__read-time' ) ).toHaveText( /\d+ min read/ );
		// Embeds keep their iframe (no second KSES pass).
		await expect( root.locator( '.newspack-live-entry .wp-block-embed iframe' ) ).toHaveCount( 1 );

		// Entry markup.
		const first = entries.first();
		const id = await first.getAttribute( 'data-entry-id' );
		await expect( first ).toHaveAttribute( 'id', `live-entry-${ id }` );
		await expect( first ).toHaveAttribute( 'data-rev', /^\d+$/ );
		await expect( first ).toHaveAttribute( 'data-time', /^\d+$/ );
		// The time opens the card's top row.
		await expect(
			first.locator( '.newspack-live-entry__card > header.newspack-live-entry__meta > time.newspack-live-entry__time[datetime]:first-child' )
		).toHaveCount( 1 );
		await expect( first.locator( ':scope > time' ) ).toHaveCount( 0 );
		await expect( first.locator( '.newspack-live-entry__time .newspack-live-time' ) ).toHaveText( /^\d{1,2}:\d{2}\s?[ap]m$/i );
		await expect( first.locator( 'summary.newspack-live-entry__share[data-action="share"]' ) ).toHaveAttribute(
			'data-url',
			new RegExp( `live-entry=${ id }#live-entry-${ id }$` )
		);
		await expect( first.locator( 'h3.newspack-live-entry__title' ) ).toHaveText( 'Card update' );
		await expect( first.locator( '.newspack-live-entry__author' ) ).toBeVisible();
		await expect( first.locator( '.newspack-live-entry__share' ) ).toHaveAccessibleName( 'Share: Card update' );

		// Aside: related coverage.
		await expect( page.getByRole( 'heading', { level: 2, name: 'Related coverage' } ) ).toBeVisible();

		// Accessibility structure.
		await expect( page.getByRole( 'link', { name: 'Skip to latest updates' } ) ).toHaveAttribute( 'href', '#newspack-live-feed' );
		await expect( page.getByRole( 'region', { name: 'Live updates' } ) ).toBeAttached();
	} );

	test( 'upcoming, live without entries and ended states', async ( { page } ) => {
		const upcoming = createLive( { title: 'E2E upcoming', state: 'upcoming' } );
		await page.goto( permalink( upcoming ) );
		await expect( page.locator( '.newspack-live-badge--upcoming' ) ).toHaveText( /^Upcoming · Starts / );
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		await expect( page.locator( '.newspack-live__empty' ) ).toHaveText( /^Coverage starts .+ at .+\. Check back then\.$/ );

		const empty = createLive( { title: 'E2E empty live' } );
		await page.goto( permalink( empty ) );
		await expect( page.locator( '.newspack-live-badge--live' ) ).toBeVisible();
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		await expect( page.locator( '.newspack-live__empty' ) ).toHaveText( 'First updates coming soon.' );

		const ended = createLive( { title: 'E2E ended', state: 'ended' } );
		const older = addEntry( ended, { title: 'Older', time: '-2 hours' } );
		const newer = addEntry( ended, { title: 'Newer', time: '-1 hours' } );
		await page.goto( permalink( ended ) );
		await expect( page.locator( '.newspack-live-badge--ended' ) ).toHaveText( 'Ended' );
		await expect( page.locator( '.newspack-live__closed' ) ).toHaveText( 'Questions are closed.' );
		await expect( page.locator( 'form.newspack-live-ask' ) ).toHaveCount( 0 );
		const ids = async () =>
			page
				.locator( '.newspack-live__entries > .newspack-live-entry' )
				.evaluateAll( els => els.map( el => Number( ( el as HTMLElement ).dataset.entryId ) ) );
		expect( await ids() ).toEqual( [ newer, older ] );
	} );

	test( 'cursor and entry views render server side', async ( { page } ) => {
		const live = createLive( { title: 'E2E pagination' } );
		const ids: number[] = [];
		for ( let i = 0; i < 25; i++ ) {
			ids.push( addEntry( live, { title: `Entry ${ i + 1 }`, time: `-${ 100 - i } minutes` } ) );
		}
		const newestFirst = [ ...ids ].reverse();
		const shown = async () =>
			page
				.locator( '.newspack-live__entries > .newspack-live-entry' )
				.evaluateAll( els => els.map( el => Number( ( el as HTMLElement ).dataset.entryId ) ) );

		await page.goto( permalink( live ) );
		expect( await shown() ).toEqual( newestFirst.slice( 0, 15 ) );
		const older = page.getByRole( 'link', { name: 'Load older updates' } );
		await expect( older ).toHaveAttribute( 'href', new RegExp( `live-before=${ newestFirst[ 14 ] }` ) );

		// Server-side cursor page (the view module would append in place instead, see permalinks-pagination.spec.ts).
		await page.goto( ( await older.getAttribute( 'href' ) ) ?? '' );
		expect( await shown() ).toEqual( newestFirst.slice( 15 ) );
		await expect( page.getByRole( 'link', { name: 'Back to latest' } ) ).toBeVisible();
		await expect( page.locator( '.newspack-live__start' ) ).toHaveText( "You've reached the start of this live." );

		const target = newestFirst[ 22 ];
		await page.goto( `${ permalink( live ) }?live-entry=${ target }#live-entry-${ target }` );
		expect( await shown() ).toEqual( newestFirst.slice( 12, 25 ) );
		await expect( page.locator( `#live-entry-${ target }` ) ).toBeInViewport();
		await expect( page.getByRole( 'link', { name: 'Back to latest' } ) ).toBeVisible();
	} );
} );
