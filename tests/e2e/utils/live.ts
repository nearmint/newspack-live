/**
 * Test content helpers built on the `wp newspack-live` WP-CLI commands.
 * Everything created here carries an e2e marker set at creation (`_newspack_live_e2e` meta, or an
 * e2e user as author) and is removed by `clean-e2e`, which selects by marker only.
 */

/**
 * External dependencies
 */
import path from 'path';

/**
 * Internal dependencies
 */
import { PLUGIN_DIR, credentials } from './env';
import { wp } from './wp-cli';

export type LiveState = 'draft' | 'upcoming' | 'live' | 'ended';

export interface LiveOptions {
	title?: string;
	state?: LiveState;
	excerpt?: string;
	/** Description (post content). */
	content?: string;
	/** Context HTML. */
	context?: string;
	author?: string;
	category?: string;
}

export interface EntryOptions {
	title?: string;
	content?: string;
	pinned?: boolean;
	time?: string;
	status?: 'publish' | 'draft' | 'pending';
	author?: string;
	questionIds?: number[];
	/** Entry label: a preset slug or `custom:<id>`. */
	label?: string;
}

/**
 * Creates a live.
 *
 * @param options Options.
 * @return Live ID.
 */
export function createLive( options: LiveOptions = {} ): number {
	const args = [
		'newspack-live',
		'create-live',
		'--e2e',
		'--porcelain',
		`--title=${ options.title ?? 'E2E live' }`,
		`--state=${ options.state ?? 'live' }`,
	];
	if ( options.excerpt ) {
		args.push( `--excerpt=${ options.excerpt }` );
	}
	if ( options.content ) {
		args.push( `--content=${ options.content }` );
	}
	if ( options.context ) {
		args.push( `--context-html=${ options.context }` );
	}
	if ( options.author ) {
		args.push( `--author=${ options.author }` );
	}
	if ( options.category ) {
		args.push( `--category=${ options.category }` );
	}
	return Number( wp( ...args ) );
}

/**
 * Adds an entry.
 *
 * @param liveId  Live ID.
 * @param options Options.
 * @return Entry ID.
 */
export function addEntry( liveId: number, options: EntryOptions = {} ): number {
	const args = [ 'newspack-live', 'add-entry', String( liveId ), '--porcelain' ];
	if ( options.title !== undefined ) {
		args.push( `--title=${ options.title }` );
	}
	if ( options.content !== undefined ) {
		args.push( `--content=${ options.content }` );
	}
	if ( options.pinned ) {
		args.push( '--pinned' );
	}
	if ( options.time ) {
		args.push( `--time=${ options.time }` );
	}
	if ( options.status ) {
		args.push( `--status=${ options.status }` );
	}
	if ( options.author ) {
		args.push( `--author=${ options.author }` );
	}
	if ( options.questionIds?.length ) {
		args.push( `--question-ids=${ options.questionIds.join( ',' ) }` );
	}
	if ( options.label ) {
		args.push( `--label=${ options.label }` );
	}
	return Number( wp( ...args ) );
}

/**
 * Adds a reader question.
 *
 * @param liveId Live ID.
 * @param text   Question.
 * @param name   Display name.
 * @param status Status.
 * @return Question ID.
 */
export function addQuestion( liveId: number, text: string, name = 'Reader', status = 'nl_new' ): number {
	return Number( wp( 'newspack-live', 'add-question', String( liveId ), text, `--name=${ name }`, `--status=${ status }`, '--porcelain' ) );
}

/**
 * Sets a Newspack Live meta value.
 *
 * @param postId Post ID.
 * @param key    Meta key.
 * @param value  Value.
 * @param isTime Whether the value is a time expression ("-7 hours").
 */
export function setMeta( postId: number, key: string, value: string | number, isTime = false ): void {
	const args = [ 'newspack-live', 'set-meta', String( postId ), key, String( value ) ];
	if ( isTime ) {
		args.push( '--time' );
	}
	wp( ...args );
}

/**
 * Applies a state action.
 *
 * @param liveId Live ID.
 * @param action Action.
 */
export function setState( liveId: number, action: 'go_live' | 'end' | 'reopen' | 'keep_live' ): void {
	wp( 'newspack-live', 'set-state', String( liveId ), action );
}

/**
 * Imports the fixture photo as the featured image of a live, with caption and credit.
 *
 * @param liveId Live ID.
 * @return Attachment ID.
 */
export function addFeaturedImage( liveId: number ): number {
	const file = path.join( PLUGIN_DIR, 'tests/e2e/fixtures/photo.jpg' );
	const id = Number(
		wp(
			'media',
			'import',
			file,
			`--post_id=${ liveId }`,
			'--featured_image',
			'--caption=Voters line up at City Hall.',
			'--title=E2E photo',
			// Created by the e2e admin: its author is the creation marker.
			`--user=${ credentials( 'admin' ).user }`,
			'--porcelain'
		)
	);
	wp( 'post', 'meta', 'update', String( id ), '_media_credit', 'Jane Photographer' );
	wp( 'post', 'meta', 'update', String( id ), '_navis_media_credit_org', 'Riverton Ledger' );
	return id;
}

/**
 * URL of an attachment.
 *
 * @param attachmentId Attachment ID.
 * @return Absolute URL.
 */
export function attachmentUrl( attachmentId: number ): string {
	return wp( 'eval', `echo wp_get_attachment_url( ${ Number( attachmentId ) } );` );
}

/**
 * Permalink of a post.
 *
 * @param postId Post ID.
 * @return Absolute URL.
 */
export function permalink( postId: number ): string {
	return wp( 'eval', `echo get_permalink( ${ Number( postId ) } );` );
}

/**
 * ID of a published demo story that isn't a live (article cards link to it).
 *
 * @return Post ID.
 */
export function demoStoryId(): number {
	return Number(
		wp(
			'post',
			'list',
			'--post_type=post',
			'--post_status=publish',
			'--meta_key=_newspack_live_demo',
			'--posts_per_page=1',
			'--field=ID',
			'--orderby=ID',
			'--order=ASC'
		)
	);
}

/**
 * Deletes all e2e content.
 */
export function cleanE2E(): void {
	wp( 'newspack-live', 'clean-e2e' );
}
