/**
 * Lifecycle: states, state actions, auto-end and New live.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { openConsole } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

const doctor = ( liveId: number ) => JSON.parse( wp( 'newspack-live', 'doctor', String( liveId ), '--format=json' ) );

const status = async ( liveId: number ) => {
	const api = await apiContext( null );
	const data = await ( await api.get( `${ API }/lives/${ liveId }/status` ) ).json();
	await api.dispose();
	return data;
};

test.describe( 'lifecycle', () => {
	test.use( { storageState: storageStatePath( 'editor' ) } );
	test.afterAll( () => cleanE2E() );

	test( 'an upcoming live becomes Live once its start time passes', async ( { page } ) => {
		const live = createLive( { title: 'E2E upcoming to live', state: 'upcoming' } );
		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live-badge--upcoming' ) ).toBeVisible();
		expect( ( await status( live ) ).state ).toBe( 'upcoming' );

		setMeta( live, '_newspack_live_scheduled_start', '-1 minute', true );
		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live-badge--live' ) ).toBeVisible();
		await expect.poll( async () => ( await status( live ) ).state, { timeout: 90_000 } ).toBe( 'live' );
	} );

	test( 'Go live, End live, Reopen and Keep live', async ( { page } ) => {
		const live = createLive( { title: 'E2E actions', state: 'upcoming' } );
		await openConsole( page, live );

		await page.getByRole( 'button', { name: 'Go live' } ).click();
		await expect( page.getByTestId( 'newspack-live-state' ) ).toHaveText( 'Live' );
		let report = doctor( live );
		expect( report.state ).toBe( 'live' );
		expect( report.start ).not.toBe( '-' );
		expect( report.end ).toBe( '-' );
		expect( report.activity_at ).not.toBe( '-' );

		setMeta( live, '_newspack_live_activity_at', '-2 hours', true );
		await page.getByRole( 'button', { name: 'Keep live' } ).click();
		await expect.poll( () => Date.now() - Date.parse( doctor( live ).activity_at ) ).toBeLessThan( 120_000 );

		await page.getByRole( 'button', { name: 'End live' } ).click();
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toContainText( 'End this live? Readers will see it as ended and questions will close.' );
		await dialog.getByRole( 'button', { name: 'End live' } ).click();
		await expect( page.getByTestId( 'newspack-live-state' ) ).toHaveText( 'Ended' );
		report = doctor( live );
		expect( report.state ).toBe( 'ended' );
		expect( report.end ).not.toBe( '-' );
		await expect( page.getByText( 'This live has ended. Readers can still read it. Reopen it to post again.' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Reopen' } ).click();
		await expect( page.getByTestId( 'newspack-live-state' ) ).toHaveText( 'Live' );
		expect( doctor( live ).end ).toBe( '-' );
	} );

	test( 'the auto-end warning offers "Keep live" in the console and the post editor', async ( { page } ) => {
		const live = createLive( { title: 'E2E auto-end warning' } );
		setMeta( live, '_newspack_live_auto_end_hours', 6 );
		// The inactivity reference is max( activity, start ): both are set in the past.
		setMeta( live, '_newspack_live_start', '-8 hours', true );
		setMeta( live, '_newspack_live_activity_at', '-330 minutes', true );

		await openConsole( page, live );
		const banner = page.getByText( /This live will end automatically at .+ because nothing has been posted for 6 hours\./ );
		await expect( banner ).toBeVisible();
		await page.getByRole( 'button', { name: 'Keep live' } ).first().click();
		await expect( banner ).toBeHidden();

		setMeta( live, '_newspack_live_activity_at', '-330 minutes', true );
		await page.goto( `/wp-admin/post.php?post=${ live }&action=edit` );
		const notice = page.locator( '.components-notice' ).filter( { hasText: 'This live will end automatically' } );
		await expect( notice ).toBeVisible( { timeout: 30_000 } );
		await notice.getByRole( 'button', { name: 'Keep live' } ).click();
		await expect( notice ).toBeHidden();
	} );

	test( 'a live idle beyond its auto-end reads as Ended everywhere, and cron persists the end', async ( { page } ) => {
		const live = createLive( { title: 'E2E auto-ended' } );
		setMeta( live, '_newspack_live_auto_end_hours', 6 );
		setMeta( live, '_newspack_live_activity_at', '-7 hours', true );
		setMeta( live, '_newspack_live_start', '-8 hours', true );

		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live-badge--ended' ) ).toBeVisible();
		expect( ( await status( live ) ).state ).toBe( 'ended' );
		await openConsole( page, live );
		await expect( page.getByTestId( 'newspack-live-state' ) ).toHaveText( 'Ended' );

		expect( doctor( live ).end ).toBe( '-' );
		wp( 'newspack-live', 'run-cron', 'hourly' );
		expect( doctor( live ).end ).not.toBe( '-' );
	} );

	test( '"New live" opens the editor on a draft with Live enabled, default settings and an empty Context', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?newspack_live=1' );
		// The list's "Add New" button points to "New live".
		await page.locator( '.wrap .page-title-action' ).first().click();
		await page.waitForURL( /post-new\.php\?newspack_live=1&_wpnonce=/ );
		const handle = await page.waitForFunction( () =>
			( window as unknown as { wp?: { data?: { select: ( s: string ) => { getCurrentPostId?: () => number } | undefined } } } ).wp?.data
				?.select( 'core/editor' )
				?.getCurrentPostId?.()
		);
		const postId = Number( await handle.jsonValue() );

		expect( wp( 'post', 'meta', 'get', String( postId ), '_newspack_live_enabled' ) ).toBe( '1' );
		expect( wp( 'post', 'get', String( postId ), '--field=post_content' ) ).toBe( '' );
		const live = doctor( postId );
		expect( String( live.auto_end_hours ) ).toBe( '24' );
		expect( live.questions_open ).toBe( 'yes' );
		await expect( page.getByRole( 'button', { name: 'Live coverage' } ).or( page.getByText( 'Live coverage' ) ).first() ).toBeVisible();
	} );
} );
