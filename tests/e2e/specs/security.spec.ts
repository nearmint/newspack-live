/**
 * Live-scoped authorization.
 */

/**
 * External dependencies
 */
import { test, expect, type APIRequestContext } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { addEntry, addQuestion, cleanE2E, createLive, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

type Call = [ method: 'get' | 'post' | 'put', path: string, data?: Record< string, unknown > ];

test.describe( 'security', () => {
	let liveA: number;
	let liveB: number;
	let entryB: number;
	let questionB: number;

	test.beforeAll( () => {
		const author = wp( 'user', 'get', 'nl-e2e-author', '--field=ID' );
		const contributor = wp( 'user', 'get', 'nl-e2e-contributor', '--field=ID' );
		liveA = createLive( { title: 'E2E live A' } );
		liveB = createLive( { title: 'E2E live B' } );
		setMeta( liveA, '_newspack_live_team', `[${ author },${ contributor }]` );
		entryB = addEntry( liveB, { title: 'Entry on B' } );
		questionB = addQuestion( liveB, 'Question on B?' );
	} );

	test.afterAll( () => cleanE2E() );

	const writesOnB = (): Call[] => [
		[ 'post', `${ API }/lives/${ liveB }/entries`, { title: 'Intrusion', status: 'pending' } ],
		[ 'put', `${ API }/entries/${ entryB }`, { title: 'Intrusion' } ],
		[ 'post', `${ API }/entries/${ entryB }/flags`, { pinned: true } ],
		[ 'post', `${ API }/entries/${ entryB }/status`, { status: 'trash' } ],
		[ 'post', `${ API }/lives/${ liveB }/state`, { action: 'end' } ],
		[ 'post', `${ API }/lives/${ liveB }/settings`, { questions_open: false } ],
		[ 'post', `${ API }/lives/${ liveB }/questions/bulk`, { ids: [ questionB ], status: 'nl_spam' } ],
		[ 'get', `${ API }/lives/${ liveB }/console` ],
		[ 'get', `${ API }/lives/${ liveB }/questions` ],
	];

	const call = async ( api: APIRequestContext, [ method, path, data ]: Call ) => api[ method ]( path, data ? { data } : undefined );

	for ( const role of [ 'author', 'contributor' ] as const ) {
		test( `${ role } on the team of live A gets 403 on every route of live B`, async () => {
			const api = await apiContext( role );
			for ( const request of writesOnB() ) {
				const response = await call( api, request );
				expect( response.status(), `${ request[ 0 ].toUpperCase() } ${ request[ 1 ] }` ).toBe( 403 );
			}
			// Their own live works.
			const own = await api.post( `${ API }/lives/${ liveA }/entries`, { data: { title: `By ${ role }`, status: 'pending' } } );
			expect( own.status() ).toBe( 201 );
			await api.dispose();
		} );
	}

	test( 'anonymous users get 401 on private routes and on the core entries route', async () => {
		const api = await apiContext( null );
		for ( const request of writesOnB() ) {
			const response = await call( api, request );
			expect( response.status(), `${ request[ 0 ].toUpperCase() } ${ request[ 1 ] }` ).toBe( 401 );
		}
		expect( ( await api.get( '/wp-json/wp/v2/newspack-live-entries' ) ).status() ).toBe( 401 );
		expect( ( await api.get( `/wp-json/wp/v2/newspack-live-entries/${ entryB }` ) ).status() ).toBe( 401 );
		// Nothing changed on live B.
		expect( wp( 'post', 'get', String( entryB ), '--field=post_title' ) ).toBe( 'Entry on B' );
		expect( wp( 'post', 'get', String( questionB ), '--field=post_status' ) ).toBe( 'nl_new' );
		await api.dispose();
	} );

	test( 'public routes are readable anonymously and cacheable', async () => {
		const api = await apiContext( null );
		const status = await api.get( `${ API }/lives/${ liveB }/status` );
		expect( status.status() ).toBe( 200 );
		expect( status.headers()[ 'cache-control' ] ).toBe( 'public, max-age=60, s-maxage=60' );
		expect( ( await status.json() ).state ).toBe( 'live' );
		const entries = await api.get( `${ API }/lives/${ liveB }/entries` );
		expect( entries.status() ).toBe( 200 );
		expect( ( await entries.json() ).entries[ 0 ].html ).toContain( 'Entry on B' );
		await api.dispose();
	} );
	test( 'WP-CLI with --user keeps no script for users without unfiltered_html', () => {
		const payload =
			'<!-- wp:html --><p>x</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">y</a><!-- /wp:html -->';
		const dangerous = /<\s*script\b|\son[a-z]+\s*=|javascript:/i;
		const content = ( id: string ) => wp( 'post', 'get', id, '--field=post_content' );
		for ( const user of [ 'nl-e2e-contributor', 'nl-e2e-author' ] ) {
			const viaCommand = wp(
				'newspack-live',
				'add-entry',
				String( liveA ),
				`--content=${ payload }`,
				'--status=draft',
				`--author=${ user }`,
				`--user=${ user }`,
				'--porcelain'
			);
			expect( content( viaCommand ), `add-entry --user=${ user }` ).not.toMatch( dangerous );
			const viaCore = wp(
				'post',
				'create',
				'--post_type=newspack_live_entry',
				'--post_status=draft',
				`--post_parent=${ liveA }`,
				'--post_title=E2E CLI',
				`--post_content=${ payload }`,
				'--meta_input={"_newspack_live_e2e":1}',
				`--user=${ user }`,
				'--porcelain'
			);
			expect( content( viaCore ), `post create --user=${ user }` ).not.toMatch( dangerous );
		}
		// An administrator (unfiltered_html) keeps core's behavior on save.
		const admin = wp(
			'newspack-live',
			'add-entry',
			String( liveA ),
			`--content=${ payload }`,
			'--status=draft',
			'--author=nl-e2e-admin',
			'--user=nl-e2e-admin',
			'--porcelain'
		);
		expect( content( admin ) ).toContain( '<script>alert(1)</script>' );
	} );
} );
