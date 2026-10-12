<?php
/**
 * Seeds fictional English demo content for "The Riverton Ledger".
 *
 * Usage (from the WordPress root, or with --path):
 *   wp eval-file wp-content/plugins/newspack-live/bin/seed-demo-content.php
 *
 * The script is idempotent: every item is looked up by a stable key (login,
 * slug or seed key) before it is created, so re-running it never creates
 * duplicates. Existing content that was not created by this script is never
 * modified. Everything it creates is flagged with the `_newspack_live_demo`
 * meta (post, user or term meta) so it can be removed later.
 *
 * It also turns the two live drafts into lives: #46 "Election night" in state Live with
 * ~25 updates (auto-end off), and #48 "Storm Watch" Upcoming (tomorrow 18:00 site time). Re-running
 * moves #48's start to the next day and refreshes the demo wording, without duplicating anything
 * or undoing the newsroom's pins and co-hosts.
 *
 * All people, places and events are fictional.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Demo;

use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

const MARKER      = '_newspack_live_demo';
const SEED_KEY    = '_newspack_live_demo_key';
const IMAGE_BASE  = 'https://picsum.photos/seed/%s/1600/900.jpg';
const MENU_NAME   = 'Primary';
const MENU_LOC    = 'primary-menu';
const HOME_SLUG   = 'home';
const ABOUT_SLUG  = 'about';
const DAY_SECONDS = 86400;

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

/**
 * Logs a line to the WP-CLI output.
 *
 * @param string $message Message.
 * @return void
 */
function log( string $message ): void {
	WP_CLI::log( $message );
}

/**
 * Finds a post created by this script by its seed key.
 *
 * @param string          $key       Seed key.
 * @param string|string[] $post_type Post type(s).
 * @return int Post ID, or 0.
 */
function find_seeded_post( string $key, $post_type ): int {
	$ids = get_posts(
		[
			'post_type'        => $post_type,
			// 'any' skips statuses excluded from search, such as the question statuses.
			'post_status'      => array_values( array_diff( get_post_stati(), [ 'trash' ] ) ),
			'meta_key'         => SEED_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'suppress_filters' => true,
		]
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Renders a list of simple block definitions as block markup.
 *
 * Supported blocks: [ 'p', text ], [ 'h', text ], [ 'list', items ], [ 'quote', text, citation ].
 *
 * @param array $blocks Block definitions.
 * @return string Serialized block markup.
 */
function render_blocks( array $blocks ): string {
	$out = [];
	foreach ( $blocks as $block ) {
		switch ( $block[0] ) {
			case 'h':
				$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $block[1] ) . "</h2>\n<!-- /wp:heading -->";
				break;
			case 'list':
				$items = array_map(
					fn( $item ) => "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->",
					$block[1]
				);
				$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( "\n", $items ) . "</ul>\n<!-- /wp:list -->";
				break;
			case 'quote':
				$out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>" . esc_html( $block[1] ) . "</p>\n<!-- /wp:paragraph --><cite>" . esc_html( $block[2] ) . "</cite></blockquote>\n<!-- /wp:quote -->";
				break;
			default:
				$out[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $block[1] ) . "</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $out );
}

/**
 * Creates the demo users.
 *
 * @return array<string,int> User IDs keyed by login.
 */
function seed_users(): array {
	$users = [
		[
			'login' => 'eleanor.whitfield',
			'first' => 'Eleanor',
			'last'  => 'Whitfield',
			'role'  => 'editor',
			'bio'   => 'Eleanor Whitfield is the executive editor of The Riverton Ledger. She has covered Riverton for more than twenty years, starting as a night cops reporter.',
		],
		[
			'login' => 'marcus.delgado',
			'first' => 'Marcus',
			'last'  => 'Delgado',
			'role'  => 'author',
			'bio'   => 'Marcus Delgado covers City Hall and politics for The Riverton Ledger. He is leading the newsroom\'s coverage of the 2026 election.',
		],
		[
			'login' => 'priya.raman',
			'first' => 'Priya',
			'last'  => 'Raman',
			'role'  => 'author',
			'bio'   => 'Priya Raman reports on courts, public safety and local government accountability. Before joining the Ledger, she covered the county courthouse for a regional wire service.',
		],
		[
			'login' => 'tom.brennan',
			'first' => 'Tom',
			'last'  => 'Brennan',
			'role'  => 'author',
			'bio'   => 'Tom Brennan covers weather, the river and high school sports. He has chased every storm that has come up the Riverton valley since 2011.',
		],
		[
			'login' => 'hannah.cole',
			'first' => 'Hannah',
			'last'  => 'Cole',
			'role'  => 'contributor',
			'bio'   => 'Hannah Cole is a freelance contributor who writes about neighborhoods, schools and community life in Riverton.',
		],
	];

	$ids = [];
	foreach ( $users as $user ) {
		$existing = get_user_by( 'login', $user['login'] );
		if ( $existing ) {
			if ( ! get_user_meta( $existing->ID, MARKER, true ) ) {
				WP_CLI::warning( "User {$user['login']} exists and was not created by this script; using it as is." );
			}
			$ids[ $user['login'] ] = $existing->ID;
			continue;
		}
		$password = wp_generate_password( 20, true, false );
		$user_id  = wp_insert_user(
			[
				'user_login'   => $user['login'],
				'user_pass'    => $password,
				'user_email'   => $user['login'] . '@riverton-ledger.test',
				'first_name'   => $user['first'],
				'last_name'    => $user['last'],
				'display_name' => $user['first'] . ' ' . $user['last'],
				'nickname'     => $user['first'] . ' ' . $user['last'],
				'description'  => $user['bio'],
				'role'         => $user['role'],
			]
		);
		if ( is_wp_error( $user_id ) ) {
			WP_CLI::warning( "Could not create user {$user['login']}: " . $user_id->get_error_message() );
			continue;
		}
		update_user_meta( $user_id, MARKER, 1 );
		$ids[ $user['login'] ] = $user_id;
		log( sprintf( 'Created user %-18s role=%-11s password=%s', $user['login'], $user['role'], $password ) );
	}
	return $ids;
}

/**
 * Creates (or reuses) a term and marks it if this script created it.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy.
 * @return int Term ID, or 0 on failure.
 */
function ensure_term( string $name, string $taxonomy ): int {
	$existing = term_exists( $name, $taxonomy );
	if ( $existing ) {
		return (int) $existing['term_id'];
	}
	$term = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $term ) ) {
		WP_CLI::warning( "Could not create {$taxonomy} {$name}: " . $term->get_error_message() );
		return 0;
	}
	update_term_meta( $term['term_id'], MARKER, 1 );
	log( "Created {$taxonomy}: {$name}" );
	return (int) $term['term_id'];
}

/**
 * Creates the demo categories and tags.
 *
 * @return array{categories: array<string,int>, tags: array<string,int>}
 */
function seed_terms(): array {
	$categories = [];
	foreach ( [ 'Politics', 'Local', 'Weather', 'Courts', 'Sports' ] as $name ) {
		$categories[ $name ] = ensure_term( $name, 'category' );
	}
	$tags = [];
	foreach ( [ '2026 Election', 'City Council', 'Storm Watch', 'School Board', 'High School Football', 'Mayor\'s Race', 'Transportation', 'Flooding', 'Public Safety', 'Budget' ] as $name ) {
		$tags[ $name ] = ensure_term( $name, 'post_tag' );
	}
	return [
		'categories' => $categories,
		'tags'       => $tags,
	];
}

/**
 * Imports a placeholder image with `wp media import` and sets it as the featured image.
 *
 * @param int    $post_id Post ID.
 * @param string $seed    Picsum seed.
 * @param string $caption Caption.
 * @param string $alt     Alt text.
 * @return void
 */
function ensure_featured_image( int $post_id, string $seed, string $caption, string $alt ): void {
	$attachment_id = find_seeded_post( 'image:' . $seed, 'attachment' );

	if ( ! $attachment_id ) {
		// The in-process command parser does not unescape quoted values, so only
		// pass space-free arguments here and set text fields through the API below.
		$result = WP_CLI::runcommand(
			sprintf(
				'media import %s --post_id=%d --file_name=%s --porcelain',
				sprintf( IMAGE_BASE, $seed ),
				$post_id,
				$seed . '.jpg'
			),
			[
				'return'     => 'all',
				'launch'     => false,
				'exit_error' => false,
			]
		);
		$attachment_id = (int) trim( $result->stdout );
		if ( 0 !== $result->return_code || ! $attachment_id ) {
			WP_CLI::warning( "Image import failed for {$seed}: " . trim( $result->stderr ) . ' (re-run the script to retry).' );
			return;
		}
		update_post_meta( $attachment_id, MARKER, 1 );
		update_post_meta( $attachment_id, SEED_KEY, 'image:' . $seed );
		log( "  Imported image {$seed} (#{$attachment_id})" );
	}

	// Keep text fields and credits in sync on attachments this script created.
	$attachment = get_post( $attachment_id );
	if ( $attachment->post_title !== $alt || $attachment->post_excerpt !== $caption ) {
		wp_update_post(
			wp_slash(
				[
					'ID'           => $attachment_id,
					'post_title'   => $alt,
					'post_excerpt' => $caption,
				]
			)
		);
	}
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
	update_post_meta( $attachment_id, '_media_credit', 'Placeholder photo' );
	update_post_meta( $attachment_id, '_navis_media_credit_org', 'Picsum' );

	if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
		set_post_thumbnail( $post_id, $attachment_id );
	}
}

/**
 * Creates a post (or page) if it does not exist yet.
 *
 * @param array $args {
 *     Post definition.
 *
 *     @type string $key       Stable seed key.
 *     @type string $type      Post type.
 *     @type string $status    Post status.
 *     @type string $title     Title.
 *     @type string $content   Serialized block content.
 *     @type string $excerpt   Excerpt.
 *     @type string $subtitle  Subtitle.
 *     @type int    $author    Author ID.
 *     @type int    $timestamp Publication timestamp (GMT).
 *     @type int[]  $cats      Category IDs.
 *     @type int[]  $tags      Tag IDs.
 *     @type bool   $sync      Refresh the content of an existing post created by this script.
 * }
 * @return int Post ID, or 0.
 */
function ensure_post( array $args ): int {
	$post_id = find_seeded_post( $args['key'], $args['type'] );
	if ( $post_id ) {
		if ( ! empty( $args['sync'] ) && get_post_field( 'post_content', $post_id ) !== $args['content'] ) {
			wp_update_post(
				wp_slash(
					[
						'ID'           => $post_id,
						'post_content' => $args['content'],
					]
				)
			);
			log( "Updated content: {$args['title']} (#{$post_id})" );
			return $post_id;
		}
		log( "Exists: {$args['title']} (#{$post_id})" );
		return $post_id;
	}

	$postarr = [
		'post_type'    => $args['type'],
		'post_status'  => $args['status'],
		'post_title'   => $args['title'],
		'post_content' => $args['content'],
		'post_excerpt' => $args['excerpt'] ?? '',
		'post_author'  => $args['author'] ?? 1,
		'meta_input'   => [
			MARKER   => 1,
			SEED_KEY => $args['key'],
		],
	];
	if ( ! empty( $args['subtitle'] ) ) {
		$postarr['meta_input']['newspack_post_subtitle'] = $args['subtitle'];
	}
	if ( ! empty( $args['timestamp'] ) ) {
		$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $args['timestamp'] );
		$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
	}
	if ( ! empty( $args['cats'] ) ) {
		$postarr['post_category'] = $args['cats'];
	}
	if ( ! empty( $args['tags'] ) ) {
		$postarr['tax_input'] = [ 'post_tag' => $args['tags'] ];
	}

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "Could not create {$args['title']}: " . $post_id->get_error_message() );
		return 0;
	}
	// tax_input requires a capable current user; set tags explicitly.
	if ( ! empty( $args['tags'] ) ) {
		wp_set_post_terms( $post_id, $args['tags'], 'post_tag' );
	}
	log( "Created {$args['type']} ({$args['status']}): {$args['title']} (#{$post_id})" );
	return $post_id;
}

/**
 * Returns the demo article definitions.
 *
 * Each entry: seed, category, tags, author login, days ago, title, subtitle, excerpt, caption, alt, body.
 *
 * @return array[]
 */
function articles(): array {
	return [
		[
			'seed'     => 'riverton-01',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Mayor\'s Race' ],
			'author'   => 'marcus.delgado',
			'days'     => 1,
			'title'    => 'Hollis and Ochoa clash over housing costs in first mayoral debate',
			'subtitle' => 'The two candidates offered sharply different plans for a city where rents have climbed 30 percent in five years.',
			'excerpt'  => 'Councilmember Diane Hollis and businessman Raymond Ochoa traded pointed attacks over housing, taxes and the riverfront in a packed debate at Riverton Community College.',
			'caption'  => 'The candidates on stage at Riverton Community College.',
			'alt'      => 'Two podiums on a stage in front of an audience',
			'body'     => [
				[ 'p', 'Housing dominated the first debate of the Riverton mayoral race on Tuesday night, as Councilmember Diane Hollis and businessman Raymond Ochoa offered sharply different visions for a city where the median rent has climbed nearly 30 percent in five years.' ],
				[ 'p', 'Hollis, who has represented the East Bend district for eight years, pitched a plan to allow duplexes and small apartment buildings on lots currently zoned for single-family homes. Ochoa, who owns a chain of hardware stores, said the city should instead cut permit fees and speed up approvals.' ],
				[ 'quote', 'We cannot build our way out of this crisis one permit at a time. We need to change the rules.', 'Diane Hollis' ],
				[ 'h', 'Sparks over the riverfront' ],
				[ 'p', 'The sharpest exchange came over the proposed riverfront flood wall. Ochoa accused Hollis of supporting a "blank check" for the project, while Hollis said her opponent had no plan to protect the neighborhoods that flooded two years ago.' ],
				[ 'p', 'The candidates will meet again on Oct. 14 for a debate focused on public safety, hosted by the Riverton Chamber of Commerce.' ],
			],
		],
		[
			'seed'     => 'riverton-02',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Mayor\'s Race' ],
			'author'   => 'marcus.delgado',
			'days'     => 3,
			'title'    => 'Riverton mayoral race: Where the candidates stand on the issues',
			'subtitle' => 'A guide to Diane Hollis and Raymond Ochoa\'s positions on housing, public safety, taxes and the river.',
			'excerpt'  => 'Voters will choose Riverton\'s next mayor on Nov. 3. Here is where the two candidates stand on the issues that matter most to residents.',
			'caption'  => 'Riverton City Hall on Main Street.',
			'alt'      => 'A civic building with columns',
			'body'     => [
				[ 'p', 'Riverton voters will choose a new mayor on Nov. 3 for the first time in twelve years, after Mayor Gordon Pike announced he would not seek a fourth term. The Ledger asked both candidates the same questions on the issues residents told us matter most.' ],
				[ 'h', 'Housing' ],
				[ 'p', 'Hollis wants to legalize duplexes citywide and create a housing trust fund. Ochoa wants to cut permit fees by half and set a 90-day deadline for the city to approve building plans.' ],
				[ 'h', 'Public safety' ],
				[ 'p', 'Both candidates support hiring more police officers. Hollis would also expand the city\'s mental health crisis team, while Ochoa has proposed a new police substation in the downtown district.' ],
				[ 'list', [ 'Election day: Tuesday, Nov. 3', 'Early voting: Oct. 19 to Oct. 31', 'Registration deadline: Oct. 5' ] ],
			],
		],
		[
			'seed'     => 'riverton-03',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election' ],
			'author'   => 'hannah.cole',
			'days'     => 5,
			'title'    => 'Early voting sites announced for the November election',
			'subtitle' => 'Five locations will be open for two weeks, including a new site at the Northgate library branch.',
			'excerpt'  => 'The county elections office will open five early voting sites on Oct. 19, including a new location in the fast-growing Northgate neighborhood.',
			'caption'  => 'Voting booths set up at a previous election.',
			'alt'      => 'Rows of voting booths in a gymnasium',
			'body'     => [
				[ 'p', 'Riverton residents will be able to cast their ballots early at five locations starting Oct. 19, the county elections office announced Wednesday.' ],
				[ 'p', 'For the first time, an early voting site will open at the Northgate branch library, which elections director Carla Mendez said was added after long lines in the neighborhood during the primary.' ],
				[ 'list', [ 'Riverton City Hall, 100 Main St.', 'Northgate Branch Library, 2400 Northgate Blvd.', 'East Bend Community Center, 18 Water St.', 'Riverton Community College, Student Union', 'County Administration Building, 55 Court St.' ] ],
				[ 'p', 'All sites will be open from 8 a.m. to 7 p.m. on weekdays and from 9 a.m. to 5 p.m. on weekends.' ],
			],
		],
		[
			'seed'     => 'riverton-04',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Mayor\'s Race' ],
			'author'   => 'marcus.delgado',
			'days'     => 8,
			'title'    => 'Campaign finance reports show Ochoa outraising Hollis two to one',
			'subtitle' => 'Much of Ochoa\'s money came from developers and business owners; Hollis relied on small donors.',
			'excerpt'  => 'Raymond Ochoa has raised more than $410,000 for his mayoral campaign, roughly double the total of Councilmember Diane Hollis, according to new filings.',
			'caption'  => 'Campaign signs along Riverside Drive.',
			'alt'      => 'Campaign yard signs on a lawn',
			'body'     => [
				[ 'p', 'Raymond Ochoa has raised more than $410,000 for his mayoral campaign, roughly twice as much as Councilmember Diane Hollis, according to campaign finance reports filed Friday.' ],
				[ 'p', 'About a third of Ochoa\'s contributions came from real estate developers, contractors and their employees, a Ledger analysis found. Hollis reported $198,000, with more than 60 percent of her donations under $100.' ],
				[ 'quote', 'Every dollar we raise comes with no strings attached.', 'Raymond Ochoa' ],
				[ 'p', 'Ochoa has also loaned his campaign $75,000. The next reporting deadline is Oct. 23.' ],
			],
		],
		[
			'seed'     => 'riverton-05',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Flooding', 'Budget' ],
			'author'   => 'marcus.delgado',
			'days'     => 12,
			'title'    => 'Ballot measure would fund $40 million riverfront flood wall',
			'subtitle' => 'Measure R would raise property taxes by about $6 a month for the typical homeowner.',
			'excerpt'  => 'Riverton voters will decide in November whether to raise property taxes to build a flood wall protecting East Bend and downtown.',
			'caption'  => 'The Riverton riverfront near the East Bend neighborhood.',
			'alt'      => 'A river running along a city neighborhood',
			'body'     => [
				[ 'p', 'Riverton voters will decide on Nov. 3 whether to pay for a $40 million flood wall along the river, two years after floodwaters damaged more than 300 homes in East Bend.' ],
				[ 'p', 'Measure R would raise property taxes by about $6 a month for the owner of a median-priced home for 20 years. City engineers say the wall would protect East Bend and downtown from a 100-year flood.' ],
				[ 'h', 'What supporters and opponents say' ],
				[ 'list', [ 'Supporters say the wall would lower flood insurance premiums for thousands of households.', 'Opponents say the city should seek state and federal funding before raising local taxes.', 'The measure needs a simple majority to pass.' ] ],
			],
		],
		[
			'seed'     => 'riverton-06',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'City Council' ],
			'author'   => 'hannah.cole',
			'days'     => 15,
			'title'    => 'League of Women Voters to host City Council candidate forum',
			'subtitle' => 'Candidates in all four contested council districts have agreed to take part.',
			'excerpt'  => 'Eleven City Council candidates will answer residents\' questions at a forum at the Riverton Public Library next Thursday.',
			'caption'  => 'The main reading room of the Riverton Public Library.',
			'alt'      => 'A library reading room with long tables',
			'body'     => [
				[ 'p', 'The Riverton chapter of the League of Women Voters will host a forum for City Council candidates next Thursday at 6:30 p.m. at the main branch of the Riverton Public Library.' ],
				[ 'p', 'Eleven candidates running in the city\'s four contested districts have agreed to participate. Residents can submit questions in advance or on index cards at the event.' ],
				[ 'p', 'The forum will be streamed live on the library\'s website and rebroadcast on the city\'s public access channel.' ],
			],
		],
		[
			'seed'     => 'riverton-07',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Mayor\'s Race', 'Transportation' ],
			'author'   => 'eleanor.whitfield',
			'days'     => 20,
			'title'    => 'Poll: Riverton voters split on mayor, united on fixing roads',
			'subtitle' => 'A Ledger poll finds the race within the margin of error, with one in five voters undecided.',
			'excerpt'  => 'A new Riverton Ledger poll finds Diane Hollis and Raymond Ochoa virtually tied, but broad agreement that road repair should be the next mayor\'s top priority.',
			'caption'  => 'A pothole-lined stretch of Canal Street.',
			'alt'      => 'A damaged asphalt road',
			'body'     => [
				[ 'p', 'The race for Riverton mayor is a dead heat, according to a Ledger poll of 600 likely voters conducted last week. Hollis had the support of 41 percent of respondents and Ochoa 39 percent, within the poll\'s margin of error.' ],
				[ 'p', 'One in five voters said they had not yet made up their minds.' ],
				[ 'h', 'Top priorities for the next mayor' ],
				[ 'list', [ 'Fixing roads and bridges: 58%', 'Housing costs: 44%', 'Public safety: 39%', 'Flood protection: 31%' ] ],
				[ 'p', 'The poll was conducted by phone and online and has a margin of error of plus or minus 4 percentage points.' ],
			],
		],
		[
			'seed'     => 'riverton-08',
			'cat'      => 'Local',
			'tags'     => [ 'City Council', 'Budget' ],
			'author'   => 'marcus.delgado',
			'days'     => 2,
			'title'    => 'City Council approves budget with longer library hours',
			'subtitle' => 'The $312 million spending plan restores Sunday hours at all branches.',
			'excerpt'  => 'The Riverton City Council voted 6-1 to approve next year\'s budget, which restores Sunday hours at every library branch and adds 12 firefighters.',
			'caption'  => 'Council members during Monday\'s budget vote.',
			'alt'      => 'A city council chamber during a meeting',
			'body'     => [
				[ 'p', 'The Riverton City Council approved a $312 million budget for next year on Monday night, restoring Sunday hours at all five library branches for the first time since 2020.' ],
				[ 'p', 'The budget also funds 12 new firefighter positions and a 3 percent raise for city employees. Councilmember Frank Delacroix cast the lone dissenting vote, saying the plan relied too heavily on one-time reserves.' ],
				[ 'quote', 'Libraries are where this city does its homework. Sunday hours matter.', 'Council President Angela Moss' ],
			],
		],
		[
			'seed'     => 'riverton-09',
			'cat'      => 'Local',
			'tags'     => [ 'School Board' ],
			'author'   => 'hannah.cole',
			'days'     => 6,
			'title'    => 'School Board votes to push back start times at Riverton High',
			'subtitle' => 'Classes will begin at 8:45 a.m. starting next fall, nearly an hour later than today.',
			'excerpt'  => 'The Riverton School Board voted 5-2 to move the high school start time to 8:45 a.m., citing research on teenage sleep.',
			'caption'  => 'Students arriving at Riverton High School.',
			'alt'      => 'Students walking toward a school building',
			'body'     => [
				[ 'p', 'Riverton High School students will start class nearly an hour later next fall after the School Board voted 5-2 on Thursday to move the first bell to 8:45 a.m.' ],
				[ 'p', 'Supporters pointed to research linking later start times to better attendance and grades. Opponents worried about the effect on after-school jobs and sports practices.' ],
				[ 'h', 'What changes next year' ],
				[ 'list', [ 'High school: 8:45 a.m. to 3:35 p.m.', 'Middle schools: 8:00 a.m. to 2:50 p.m.', 'Elementary schools: unchanged' ] ],
			],
		],
		[
			'seed'     => 'riverton-10',
			'cat'      => 'Local',
			'tags'     => [ 'Transportation' ],
			'author'   => 'tom.brennan',
			'days'     => 9,
			'title'    => 'Oak Street bridge to close for six weeks of repairs',
			'subtitle' => 'Drivers will be detoured to the Canal Street and Route 9 bridges starting Monday.',
			'excerpt'  => 'The Oak Street bridge, which carries about 18,000 vehicles a day, will close for six weeks while crews replace its deck joints.',
			'caption'  => 'The Oak Street bridge seen from the east bank.',
			'alt'      => 'A steel truss bridge over a river',
			'body'     => [
				[ 'p', 'The Oak Street bridge will close to all traffic on Monday for six weeks of repairs, city officials said, forcing about 18,000 daily drivers onto detours.' ],
				[ 'p', 'Crews will replace the bridge\'s expansion joints and repave its deck. Pedestrians and cyclists will be able to use a temporary walkway on the south side.' ],
				[ 'p', 'Riverton Transit will reroute bus lines 4 and 7 during the closure.' ],
			],
		],
		[
			'seed'     => 'riverton-11',
			'cat'      => 'Local',
			'tags'     => [],
			'author'   => 'hannah.cole',
			'days'     => 24,
			'title'    => 'Saturday farmers market moves to the riverfront',
			'subtitle' => 'Downtown construction pushed the 40-year-old market to a new home at Heron Park.',
			'excerpt'  => 'The Riverton Farmers Market will spend the rest of the season at Heron Park while Market Square is rebuilt.',
			'caption'  => 'Produce stands at the Riverton Farmers Market.',
			'alt'      => 'Vegetable stands at an outdoor market',
			'body'     => [
				[ 'p', 'The Riverton Farmers Market, a Saturday morning fixture downtown for four decades, has moved to Heron Park on the riverfront while Market Square undergoes a year-long renovation.' ],
				[ 'p', 'Market manager Luis Ferreira said about 60 vendors made the move, and early crowds have been larger than expected.' ],
				[ 'quote', 'People come for the tomatoes and stay for the view of the river.', 'Luis Ferreira, market manager' ],
			],
		],
		[
			'seed'     => 'riverton-12',
			'cat'      => 'Weather',
			'tags'     => [ 'Storm Watch' ],
			'author'   => 'tom.brennan',
			'days'     => 0,
			'title'    => 'Storm Watch: Tropical Storm Delia forms, could reach Riverton by the weekend',
			'subtitle' => 'Forecasters say the storm may strengthen into a hurricane before landfall.',
			'excerpt'  => 'Tropical Storm Delia formed early Monday and could bring heavy rain and flooding to the Riverton valley by Saturday.',
			'caption'  => 'Storm clouds gathering over the valley.',
			'alt'      => 'Dark storm clouds over hills',
			'body'     => [
				[ 'p', 'Tropical Storm Delia formed early Monday and is forecast to strengthen as it moves north, potentially bringing heavy rain and damaging winds to the Riverton valley by Saturday.' ],
				[ 'p', 'It is too early to know the storm\'s exact track, forecasters said, but residents should review their emergency plans now.' ],
				[ 'h', 'What to do now' ],
				[ 'list', [ 'Check your flood insurance coverage.', 'Stock up on water, batteries and medications.', 'Clear gutters and storm drains near your home.', 'Sign up for RivertonAlert emergency notifications.' ] ],
			],
		],
		[
			'seed'     => 'riverton-13',
			'cat'      => 'Weather',
			'tags'     => [ 'Storm Watch', 'Flooding' ],
			'author'   => 'tom.brennan',
			'days'     => 11,
			'title'    => 'How to prepare your home for hurricane season',
			'subtitle' => 'A checklist from Riverton emergency managers, from sandbags to go-bags.',
			'excerpt'  => 'With peak hurricane season underway, Riverton\'s emergency management office shares what every household should do before a storm.',
			'caption'  => 'Sandbags stacked outside a home in East Bend.',
			'alt'      => 'A wall of sandbags in front of a house',
			'body'     => [
				[ 'p', 'Peak hurricane season runs through mid-October, and Riverton emergency managers say the time to prepare is before a storm has a name.' ],
				[ 'list', [ 'Build a go-bag with three days of supplies.', 'Know your evacuation zone.', 'Photograph your belongings for insurance claims.', 'Pick up free sandbags at the Public Works yard on Industrial Way.' ] ],
				[ 'quote', 'The families who fare best are the ones who made a plan in June.', 'Deputy Emergency Manager Rosa Kim' ],
			],
		],
		[
			'seed'     => 'riverton-14',
			'cat'      => 'Weather',
			'tags'     => [ 'Flooding' ],
			'author'   => 'tom.brennan',
			'days'     => 17,
			'title'    => 'Record rainfall floods streets in East Bend',
			'subtitle' => 'Nearly four inches fell in three hours, overwhelming storm drains.',
			'excerpt'  => 'A record 3.8 inches of rain fell in three hours on Tuesday, flooding streets and basements across the East Bend neighborhood.',
			'caption'  => 'A flooded intersection on Water Street.',
			'alt'      => 'Cars driving through a flooded street',
			'body'     => [
				[ 'p', 'A slow-moving thunderstorm dropped 3.8 inches of rain on Riverton in three hours on Tuesday, setting a record for the date and flooding streets throughout East Bend.' ],
				[ 'p', 'Firefighters rescued two drivers whose cars stalled in high water on Water Street. No injuries were reported.' ],
				[ 'p', 'Public Works crews spent the night clearing storm drains clogged with leaves and debris.' ],
			],
		],
		[
			'seed'     => 'riverton-15',
			'cat'      => 'Courts',
			'tags'     => [ 'City Council' ],
			'author'   => 'priya.raman',
			'days'     => 4,
			'title'    => 'Judge rules city violated open records law in parking contract case',
			'subtitle' => 'The city must release thousands of emails about its downtown parking deal within 30 days.',
			'excerpt'  => 'A county judge ruled that Riverton improperly withheld records related to its 30-year downtown parking contract.',
			'caption'  => 'The Riverton County Courthouse.',
			'alt'      => 'The facade of a stone courthouse',
			'body'     => [
				[ 'p', 'A county judge ruled Thursday that the City of Riverton violated the state open records law when it refused to release emails about its 30-year downtown parking contract.' ],
				[ 'p', 'Judge Miriam Castellano ordered the city to turn over the records within 30 days and to pay the legal fees of the residents\' group that sued.' ],
				[ 'quote', 'The public has a right to know how this deal was made.', 'Judge Miriam Castellano, in her ruling' ],
				[ 'p', 'A city spokesperson said officials were reviewing the decision and had not decided whether to appeal.' ],
			],
		],
		[
			'seed'     => 'riverton-16',
			'cat'      => 'Courts',
			'tags'     => [ 'Public Safety' ],
			'author'   => 'priya.raman',
			'days'     => 13,
			'title'    => 'Former city contractor pleads guilty to billing fraud',
			'subtitle' => 'Prosecutors say the paving company overbilled Riverton by $1.2 million over four years.',
			'excerpt'  => 'The owner of a paving company that held city road contracts pleaded guilty to charges that he overbilled Riverton by more than $1 million.',
			'caption'  => 'A paving crew at work on a city street.',
			'alt'      => 'Road workers laying asphalt',
			'body'     => [
				[ 'p', 'The owner of a paving company that held millions of dollars in Riverton road contracts pleaded guilty Tuesday to fraud charges, admitting that he overbilled the city by $1.2 million.' ],
				[ 'p', 'Prosecutors said the company billed for asphalt it never laid and for work crews that did not exist.' ],
				[ 'p', 'Sentencing is scheduled for January. The city has suspended all contracts with the company.' ],
			],
		],
		[
			'seed'     => 'riverton-17',
			'cat'      => 'Courts',
			'tags'     => [],
			'author'   => 'priya.raman',
			'days'     => 27,
			'title'    => 'County courthouse reopens after two-year renovation',
			'subtitle' => 'The 1912 building has new courtrooms, an accessible entrance and restored murals.',
			'excerpt'  => 'The Riverton County Courthouse reopened Monday after a $28 million renovation that restored its historic murals.',
			'caption'  => 'The restored rotunda of the county courthouse.',
			'alt'      => 'A domed rotunda with painted murals',
			'body'     => [
				[ 'p', 'The Riverton County Courthouse reopened to the public on Monday after a two-year, $28 million renovation.' ],
				[ 'p', 'The project added two courtrooms, a new accessible entrance on Court Street and restored the 1930s murals in the rotunda, which had been hidden under layers of paint.' ],
				[ 'p', 'Court operations that had moved to a temporary building on Industrial Way will return over the next two weeks.' ],
			],
		],
		[
			'seed'     => 'riverton-18',
			'cat'      => 'Sports',
			'tags'     => [ 'High School Football' ],
			'author'   => 'tom.brennan',
			'days'     => 2,
			'title'    => 'Riverton High rallies past Millbrook 28-24 on homecoming night',
			'subtitle' => 'Quarterback Jalen Brooks threw the winning touchdown with 41 seconds left.',
			'excerpt'  => 'Riverton High scored two fourth-quarter touchdowns to beat rival Millbrook 28-24 in front of a homecoming crowd.',
			'caption'  => 'Riverton High players celebrate the winning touchdown.',
			'alt'      => 'Football players celebrating on a field at night',
			'body'     => [
				[ 'p', 'Riverton High trailed rival Millbrook by 10 points with six minutes to play Friday night. Then quarterback Jalen Brooks went to work.' ],
				[ 'p', 'Brooks led two touchdown drives in the final minutes, capped by a 22-yard pass to receiver Andre Wilkes with 41 seconds left, as the Hawks won 28-24 before a homecoming crowd of more than 4,000.' ],
				[ 'quote', 'We never stopped believing. This team does not quit.', 'Coach Dana Whitaker' ],
				[ 'p', 'The Hawks improve to 5-1 and host Lakeview next Friday.' ],
			],
		],
		[
			'seed'     => 'riverton-19',
			'cat'      => 'Sports',
			'tags'     => [],
			'author'   => 'tom.brennan',
			'days'     => 10,
			'title'    => 'Riverton Rivercats clinch first playoff berth in a decade',
			'subtitle' => 'The minor league club beat Harbor City 6-2 to lock up a postseason spot.',
			'excerpt'  => 'The Riverton Rivercats are headed to the playoffs for the first time since 2016 after beating Harbor City 6-2.',
			'caption'  => 'Fans at Riverside Park during a Rivercats game.',
			'alt'      => 'A crowd in the stands of a baseball stadium',
			'body'     => [
				[ 'p', 'The Riverton Rivercats clinched their first playoff berth in ten years on Wednesday night with a 6-2 win over Harbor City at Riverside Park.' ],
				[ 'p', 'Outfielder Sam Okoro homered twice, and starting pitcher Luke Harmon allowed one run over seven innings.' ],
				[ 'p', 'The best-of-three first round begins next Tuesday. Tickets go on sale Friday.' ],
			],
		],
		[
			'seed'     => 'riverton-20',
			'cat'      => 'Sports',
			'tags'     => [ 'School Board' ],
			'author'   => 'hannah.cole',
			'days'     => 22,
			'title'    => 'Hawks cross-country team wins regional title',
			'subtitle' => 'Senior Maya Torres set a course record as Riverton High swept the top three places.',
			'excerpt'  => 'Riverton High\'s girls cross-country team won the regional championship, led by a course-record run from senior Maya Torres.',
			'caption'  => 'Runners on the course at Hollow Creek Park.',
			'alt'      => 'Runners on a trail through a park',
			'body'     => [
				[ 'p', 'Senior Maya Torres set a course record at Hollow Creek Park on Saturday as the Riverton High girls cross-country team won its first regional title since 2009.' ],
				[ 'p', 'Torres finished the 5-kilometer course in 17 minutes, 42 seconds. Teammates Ava Lindqvist and Grace Nwosu finished second and third.' ],
				[ 'p', 'The team advances to the state championship in two weeks.' ],
			],
		],
	];
}

/**
 * Returns the draft posts that will become live coverage later.
 *
 * @return array[]
 */
function live_drafts(): array {
	return [
		[
			'seed'     => 'riverton-live-election',
			'cat'      => 'Politics',
			'tags'     => [ '2026 Election', 'Mayor\'s Race' ],
			'author'   => 'marcus.delgado',
			'title'    => 'Election night 2026: Live results and updates',
			'subtitle' => 'Follow live results for Riverton mayor, City Council and Measure R as the votes are counted.',
			'excerpt'  => 'Live coverage of election night in Riverton: results, reaction and analysis from The Riverton Ledger newsroom.',
			'caption'  => 'Election workers count ballots at the county elections office.',
			'alt'      => 'Election workers sorting ballots at a table',
		],
		[
			'seed'     => 'riverton-live-storm',
			'cat'      => 'Weather',
			'tags'     => [ 'Storm Watch', 'Flooding' ],
			'author'   => 'tom.brennan',
			'title'    => 'Storm Watch: Live updates as Hurricane Delia approaches',
			'subtitle' => 'The latest on Hurricane Delia\'s track, evacuations, shelters and power outages in the Riverton valley.',
			'excerpt'  => 'Live updates on Hurricane Delia from The Riverton Ledger: forecasts, evacuation orders, road closures and how to stay safe.',
			'caption'  => 'Waves crash against the river wall ahead of the storm.',
			'alt'      => 'Rough water under a stormy sky',
		],
		[
			'seed'     => 'riverton-live-bridge',
			'cat'      => 'Local',
			'tags'     => [],
			'author'   => 'priya.raman',
			'title'    => 'Riverside Bridge reopens: Live updates',
			'subtitle' => 'The bridge reopened to traffic after 14 months of repairs.',
			'excerpt'  => 'Live coverage of the Riverside Bridge reopening from The Riverton Ledger: ceremony, traffic and what changes for commuters.',
			'caption'  => 'The Riverside Bridge at dawn.',
			'alt'      => 'A steel bridge over a river',
		],
	];
}

/**
 * Creates the demo articles and live-coverage drafts.
 *
 * @param array $users Users keyed by login.
 * @param array $terms Terms from seed_terms().
 * @return void
 */
function seed_posts( array $users, array $terms ): void {
	$now = time();

	foreach ( articles() as $index => $article ) {
		// Spread publication times across the day so posts do not collide.
		$timestamp = $now - ( $article['days'] * DAY_SECONDS ) - ( ( $index % 8 ) + 1 ) * 1800;
		$post_id   = ensure_post(
			[
				'key'       => 'post:' . $article['seed'],
				'type'      => 'post',
				'status'    => 'publish',
				'title'     => $article['title'],
				'content'   => render_blocks( $article['body'] ),
				'excerpt'   => $article['excerpt'],
				'subtitle'  => $article['subtitle'],
				'author'    => $users[ $article['author'] ] ?? 1,
				'timestamp' => $timestamp,
				'cats'      => array_filter( [ $terms['categories'][ $article['cat'] ] ?? 0 ] ),
				'tags'      => array_values( array_filter( array_map( fn( $t ) => $terms['tags'][ $t ] ?? 0, $article['tags'] ) ) ),
			]
		);
		if ( $post_id ) {
			ensure_featured_image( $post_id, $article['seed'], $article['caption'], $article['alt'] );
		}
	}

	foreach ( live_drafts() as $draft ) {
		$post_id = ensure_post(
			[
				'key'      => 'post:' . $draft['seed'],
				'type'     => 'post',
				'status'   => 'draft',
				'title'    => $draft['title'],
				'content'  => '',
				'excerpt'  => $draft['excerpt'],
				'subtitle' => $draft['subtitle'],
				'author'   => $users[ $draft['author'] ] ?? 1,
				'cats'     => array_filter( [ $terms['categories'][ $draft['cat'] ] ?? 0 ] ),
				'tags'     => array_values( array_filter( array_map( fn( $t ) => $terms['tags'][ $t ] ?? 0, $draft['tags'] ) ) ),
			]
		);
		if ( $post_id ) {
			ensure_featured_image( $post_id, $draft['seed'], $draft['caption'], $draft['alt'] );
		}
	}
}

/**
 * Builds a Newspack Homepage Posts block comment.
 *
 * @param array $attributes Block attributes (see newspack-blocks homepage-articles block.json).
 * @return string
 */
function homepage_posts_block( array $attributes ): string {
	return '<!-- wp:newspack-blocks/homepage-articles ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
}

/**
 * Creates the About and Home pages.
 *
 * @param array $terms Terms from seed_terms().
 * @return array{home: int, about: int}
 */
function seed_pages( array $terms ): array {
	$about = ensure_post(
		[
			'key'     => 'page:' . ABOUT_SLUG,
			'type'    => 'page',
			'status'  => 'publish',
			'title'   => 'About',
			'content' => render_blocks(
				[
					[ 'p', 'The Riverton Ledger is an independent, nonprofit newsroom covering the city of Riverton and the surrounding valley. Our reporters cover City Hall, the courts, schools, the river and the high school sports our readers love.' ],
					[ 'h', 'Our mission' ],
					[ 'p', 'We believe every community deserves reliable local news. We hold local institutions accountable, explain the decisions that shape daily life and tell the stories of the people who make Riverton home.' ],
					[ 'h', 'Contact us' ],
					[ 'list', [ 'News tips: tips@riverton-ledger.test', 'Corrections: corrections@riverton-ledger.test', 'Newsroom: 200 Water Street, Riverton' ] ],
					[ 'p', 'The Riverton Ledger is a fictional publication created as demo content for developing the Newspack Live plugin.' ],
				]
			),
		]
	);

	// The three demo lives together, so their badges show side by side.
	$lives = array_values( array_filter( array_map( static fn( string $seed ): int => find_seeded_post( 'post:' . $seed, 'post' ), [ 'riverton-live-election', 'riverton-live-storm', 'riverton-live-bridge' ] ) ) );
	$sections = [
		homepage_posts_block(
			[
				// Limit to demo categories so pre-existing posts never take the top slot.
				'categories'    => array_values( array_filter( $terms['categories'] ) ),
				'postsToShow'   => 1,
				'mediaPosition' => 'left',
				'typeScale'     => 6,
				'imageScale'    => 3,
				'showSubtitle'  => true,
				'showCategory'  => true,
				'showAvatar'    => false,
			]
		),
	];
	if ( $lives ) {
		$sections[] = homepage_posts_block(
			[
				'sectionHeader' => 'Live coverage',
				'specificMode'  => true,
				'specificPosts' => $lives,
				'postsToShow'   => count( $lives ),
				'postLayout'    => 'grid',
				'columns'       => 3,
				'typeScale'     => 3,
				'showExcerpt'   => false,
				'showAvatar'    => false,
			]
		);
	}
	foreach ( $terms['categories'] as $name => $term_id ) {
		if ( ! $term_id ) {
			continue;
		}
		$sections[] = homepage_posts_block(
			[
				'sectionHeader' => $name,
				'categories'    => [ $term_id ],
				'postsToShow'   => 3,
				'postLayout'    => 'grid',
				'columns'       => 3,
				'typeScale'     => 3,
				'showExcerpt'   => false,
				'showAvatar'    => false,
			]
		);
	}

	$home = ensure_post(
		[
			'key'     => 'page:' . HOME_SLUG,
			'type'    => 'page',
			'status'  => 'publish',
			'title'   => 'Home',
			'content' => implode( "\n\n", $sections ),
			'sync'    => true,
		]
	);

	return [
		'home'  => $home,
		'about' => $about,
	];
}

/**
 * Sets the Home page as static front page, unless another front page is already configured.
 *
 * @param int $home_id Home page ID.
 * @return void
 */
function seed_front_page( int $home_id ): void {
	if ( ! $home_id ) {
		return;
	}
	$current = (int) get_option( 'page_on_front' );
	if ( 'page' === get_option( 'show_on_front' ) && $current && $current !== $home_id ) {
		WP_CLI::warning( "A static front page is already set (#{$current}); leaving it unchanged." );
		return;
	}
	if ( 'page' === get_option( 'show_on_front' ) && $current === $home_id ) {
		log( 'Front page already set to Home.' );
		return;
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home_id );
	log( "Set Home (#{$home_id}) as the static front page." );
}

/**
 * Creates the Primary menu and assigns it to the primary theme location if it is free.
 *
 * @param array $terms Terms from seed_terms().
 * @param int   $about About page ID.
 * @return void
 */
function seed_menu( array $terms, int $about ): void {
	$menu = wp_get_nav_menu_object( MENU_NAME );

	if ( $menu && ! get_term_meta( $menu->term_id, MARKER, true ) ) {
		WP_CLI::warning( 'A menu named "' . MENU_NAME . '" already exists and was not created by this script; leaving it unchanged.' );
		return;
	}

	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( MENU_NAME );
		if ( is_wp_error( $menu_id ) ) {
			WP_CLI::warning( 'Could not create menu: ' . $menu_id->get_error_message() );
			return;
		}
		update_term_meta( $menu_id, MARKER, 1 );

		foreach ( $terms['categories'] as $term_id ) {
			if ( ! $term_id ) {
				continue;
			}
			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-type'      => 'taxonomy',
					'menu-item-object'    => 'category',
					'menu-item-object-id' => $term_id,
					'menu-item-status'    => 'publish',
				]
			);
			if ( ! is_wp_error( $item_id ) ) {
				update_post_meta( $item_id, MARKER, 1 );
			}
		}
		if ( $about ) {
			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-type'      => 'post_type',
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $about,
					'menu-item-status'    => 'publish',
				]
			);
			if ( ! is_wp_error( $item_id ) ) {
				update_post_meta( $item_id, MARKER, 1 );
			}
		}
		log( 'Created menu "' . MENU_NAME . '".' );
	} else {
		$menu_id = (int) $menu->term_id;
		log( 'Menu "' . MENU_NAME . '" already exists.' );
	}

	$locations = get_nav_menu_locations();
	$assigned  = (int) ( $locations[ MENU_LOC ] ?? 0 );
	if ( $assigned && $assigned !== $menu_id && wp_get_nav_menu_object( $assigned ) ) {
		WP_CLI::warning( 'The ' . MENU_LOC . " location already has a menu (#{$assigned}); leaving it unchanged." );
		return;
	}
	if ( $assigned !== $menu_id ) {
		$locations[ MENU_LOC ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		log( 'Assigned "' . MENU_NAME . '" to ' . MENU_LOC . '.' );
	}
}

/**
 * Block markup of an image from the media library.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $caption       Caption.
 * @return string
 */
function image_block( int $attachment_id, string $caption ): string {
	$url = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
	$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	return sprintf(
		"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/><figcaption class=\"wp-element-caption\">%4\$s</figcaption></figure>\n<!-- /wp:image -->",
		$attachment_id,
		esc_url( $url ),
		esc_attr( $alt ),
		esc_html( $caption )
	);
}

/**
 * Block markup of a YouTube embed.
 *
 * @param string $url Video URL.
 * @return string
 */
function embed_block( string $url ): string {
	return sprintf(
		"<!-- wp:embed {\"url\":\"%1\$s\",\"type\":\"video\",\"providerNameSlug\":\"youtube\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\n%1\$s\n</div></figure>\n<!-- /wp:embed -->",
		esc_url( $url )
	);
}

/**
 * Block markup of a Newspack Live article card.
 *
 * @param int $post_id Linked post.
 * @return string
 */
function card_block( int $post_id ): string {
	return sprintf( '<!-- wp:newspack-live/article-card {"postId":%d} /-->', $post_id );
}

/**
 * Turns a seeded draft into a live: published, enabled, marked, with its context box.
 *
 * @param int    $post_id Post ID.
 * @param string $context Context box block markup.
 * @param int    $publish Publication timestamp.
 * @return void
 */
function enable_live( int $post_id, string $description, int $publish ): void {
	$gmt = gmdate( 'Y-m-d H:i:s', $publish );
	wp_update_post(
		wp_slash(
			[
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_content'  => $description,
				'post_date_gmt' => $gmt,
				'post_date'     => get_date_from_gmt( $gmt ),
				'edit_date'     => true,
			]
		)
	);
	update_post_meta( $post_id, \Newspack_Live\Live::META_ENABLED, 1 );
	update_post_meta( $post_id, \Newspack_Live\Live::META_QUESTIONS_OPEN, 1 );
}

/**
 * Refreshes the description (post content) and the Context (its own field) of a
 * demo live.
 *
 * @param int    $post_id     Post ID.
 * @param string $description Description block markup.
 * @param string $context     Context HTML.
 * @return void
 */
function sync_context( int $post_id, string $description, string $context ): void {
	if ( get_post_field( 'post_content', $post_id ) !== $description ) {
		wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => $description,
				]
			)
		);
	}
	if ( get_post_meta( $post_id, \Newspack_Live\Context::META, true ) !== $context ) {
		update_post_meta( $post_id, \Newspack_Live\Context::META, $context );
	}
}

/**
 * Context HTML: a bulleted list.
 *
 * @param string[] $items Bullet points (plain text).
 * @return string
 */
function context_html( array $items ): string {
	return '<ul>' . implode( '', array_map( static fn( string $text ): string => '<li>' . esc_html( $text ) . '</li>', $items ) ) . '</ul>';
}

/**
 * Saves a demo entry and marks it.
 *
 * @param int    $live_id Live ID.
 * @param string $key     Seed key.
 * @param array  $data    `Entries::save()` data (without `live_id`).
 * @return int Entry ID, or 0.
 */
function ensure_entry( int $live_id, string $key, array $data ): int {
	$existing = find_seeded_post( $key, \Newspack_Live\Post_Types::ENTRY );
	if ( $existing ) {
		// Refresh the wording of an entry created by an earlier version of this script.
		$entry = get_post( $existing );
		if ( $entry->post_title !== $data['title'] || $entry->post_content !== $data['content'] ) {
			$saved = \Newspack_Live\Entries::save(
				[
					'title'   => $data['title'],
					'content' => $data['content'],
				],
				$existing
			);
			if ( is_wp_error( $saved ) ) {
				WP_CLI::warning( "Could not update entry {$key}: " . $saved->get_error_message() );
			} else {
				// A wording refresh isn't a newsroom correction: no "Updated" marker.
				update_post_meta( $existing, \Newspack_Live\Entries::META_PUBLISHED_AT, (int) strtotime( get_post_field( 'post_modified_gmt', $existing ) . ' UTC' ) );
			}
		}
		return $existing;
	}
	$entry_id = \Newspack_Live\Entries::save( [ 'live_id' => $live_id ] + $data );
	if ( is_wp_error( $entry_id ) ) {
		WP_CLI::warning( "Could not create entry {$key}: " . $entry_id->get_error_message() );
		return 0;
	}
	update_post_meta( $entry_id, MARKER, 1 );
	update_post_meta( $entry_id, SEED_KEY, $key );
	return (int) $entry_id;
}

/**
 * Sets entry labels on demo entries: presets by slug, custom labels by text (created on
 * the live when missing). Idempotent: an entry is only saved when its label differs.
 *
 * @param int                   $live_id Live ID.
 * @param array<string, string> $labels  Seed key suffix => preset slug or custom label text.
 * @return void
 */
function seed_labels( int $live_id, array $labels ): void {
	foreach ( $labels as $key => $label ) {
		$entry_id = find_seeded_post( 'entry:election:' . $key, \Newspack_Live\Post_Types::ENTRY );
		if ( ! $entry_id ) {
			continue;
		}
		$value = in_array( $label, \Newspack_Live\Labels::SELECTABLE, true ) ? $label : '';
		if ( '' === $value ) {
			foreach ( \Newspack_Live\Labels::get_custom( $live_id ) as $custom ) {
				if ( $custom['text'] === $label ) {
					$value = \Newspack_Live\Labels::CUSTOM_PREFIX . $custom['id'];
				}
			}
		}
		if ( '' === $value ) {
			$created = \Newspack_Live\Labels::create( $live_id, $label );
			if ( is_wp_error( $created ) ) {
				WP_CLI::warning( "Could not create label {$label}: " . $created->get_error_message() );
				continue;
			}
			$value = \Newspack_Live\Labels::CUSTOM_PREFIX . $created['id'];
		}
		if ( \Newspack_Live\Labels::get_value( $entry_id ) === $value ) {
			continue;
		}
		$saved = \Newspack_Live\Entries::save( [ 'label' => $value ], $entry_id );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::warning( "Could not label entry {$key}: " . $saved->get_error_message() );
			continue;
		}
		// Labeling demo content isn't a newsroom correction: no "Updated" marker.
		update_post_meta( $entry_id, \Newspack_Live\Entries::META_PUBLISHED_AT, (int) strtotime( get_post_field( 'post_modified_gmt', $entry_id ) . ' UTC' ) );
	}
}

/**
 * Creates a demo question and marks it.
 *
 * @param int    $live_id Live ID.
 * @param string $key     Seed key.
 * @param string $text    Question.
 * @param string $name    Display name.
 * @param string $status  Status.
 * @param int    $time    Timestamp.
 * @return int Question ID, or 0.
 */
function ensure_question( int $live_id, string $key, string $text, string $name, string $status, int $time ): int {
	$existing = find_seeded_post( $key, \Newspack_Live\Post_Types::QUESTION );
	if ( $existing ) {
		return $existing;
	}
	$question_id = \Newspack_Live\Questions::create( $live_id, $text, $name, 0, $status );
	if ( is_wp_error( $question_id ) ) {
		WP_CLI::warning( "Could not create question {$key}: " . $question_id->get_error_message() );
		return 0;
	}
	$gmt = gmdate( 'Y-m-d H:i:s', $time );
	wp_update_post(
		[
			'ID'            => $question_id,
			'post_date_gmt' => $gmt,
			'post_date'     => get_date_from_gmt( $gmt ),
		]
	);
	update_post_meta( $question_id, MARKER, 1 );
	update_post_meta( $question_id, SEED_KEY, $key );
	return (int) $question_id;
}

/**
 * Seeds live #46 (election night, Live) and #48 (Storm Watch, Upcoming).
 *
 * @param array $users Users keyed by login.
 * @return void
 */
function seed_lives( array $users ): void {
	if ( ! class_exists( '\Newspack_Live\Live' ) ) {
		WP_CLI::warning( 'Newspack Live is not active: lives not seeded.' );
		return;
	}
	global $coauthors_plus;
	$now      = time();
	$election = find_seeded_post( 'post:riverton-live-election', 'post' );
	$storm    = find_seeded_post( 'post:riverton-live-storm', 'post' );
	$article = static fn( string $seed ): int => find_seeded_post( 'post:' . $seed, 'post' );

	if ( $election && get_post_meta( $election, MARKER, true ) ) {
		$start       = $now - 3 * HOUR_IN_SECONDS - 10 * MINUTE_IN_SECONDS;
		// No description: the standfirst (subtitle) introduces the live.
		$description = '';
		$context     = context_html(
			[
				'Riverton elects its first new mayor in twelve years: Council member Diane Hollis faces business owner Raymond Ochoa to replace retiring Mayor Gordon Pike.',
				'Three City Council seats are also on the ballot, along with Measure R, a $40 million bond for a riverfront flood wall.',
				'Results come in batches as the county counts early, mail-in and Election Day ballots; a margin under half a point triggers an automatic recount.',
			]
		);
		if ( ! \Newspack_Live\Live::is_live( $election ) ) {
			enable_live( $election, $description, $start );
			update_post_meta( $election, \Newspack_Live\Live::META_START, $start );
			log( "Live enabled: #{$election}" );
		}
		sync_context( $election, $description, $context );
		// The demo live never auto-ends (and comes back if it did before this setting existed).
		update_post_meta( $election, \Newspack_Live\Live::META_AUTO_END_HOURS, 0 );
		if ( \Newspack_Live\Live::STATE_ENDED === \Newspack_Live\Live::get_state( $election ) ) {
			\Newspack_Live\Live::apply_action( $election, 'reopen' );
		}
		if ( isset( $coauthors_plus ) && method_exists( $coauthors_plus, 'add_coauthors' ) && function_exists( 'get_coauthors' ) && count( get_coauthors( $election ) ) < 2 ) {
			$hosts = array_filter( [ get_userdata( $users['marcus.delgado'] ?? 0 ), get_userdata( $users['priya.raman'] ?? 0 ) ] );
			$coauthors_plus->add_coauthors( $election, array_map( static fn( $user ) => $user->user_nicename, $hosts ) );
		}

		$author = static fn( string $login ): int => (int) ( $users[ $login ] ?? get_post_field( 'post_author', $election ) );
		$at     = static fn( int $minutes_ago ): int => $now - $minutes_ago * MINUTE_IN_SECONDS;

		// Questions: 5 answered in two Q&A entries, 3 shortlisted, 4 new.
		$questions = [
			[ 'q01', 'When will we know who won the mayor\'s race?', 'Ana', 'nl_shortlisted', 170 ],
			[ 'q02', 'Are mail-in ballots counted tonight?', 'Ben', 'nl_shortlisted', 165 ],
			[ 'q03', 'What happens if the race is within half a point?', 'Carla', 'nl_shortlisted', 160 ],
			[ 'q04', 'How much would Measure R cost homeowners?', 'Dev', 'nl_shortlisted', 120 ],
			[ 'q05', 'When would construction of the flood wall start?', 'Elena', 'nl_shortlisted', 115 ],
			[ 'q06', 'Which council districts are the closest?', 'Frank', 'nl_shortlisted', 80 ],
			[ 'q07', 'Is turnout higher than in 2022?', 'Grace', 'nl_shortlisted', 60 ],
			[ 'q08', 'Will the new mayor keep the police chief?', 'Hector', 'nl_shortlisted', 45 ],
			[ 'q09', 'Where can I see precinct-level results?', 'Ines', 'nl_new', 30 ],
			[ 'q10', 'Did any polling place stay open late?', 'Jae', 'nl_new', 20 ],
			[ 'q11', 'When is the new mayor sworn in?', 'Kofi', 'nl_new', 12 ],
			[ 'q12', 'How many provisional ballots were cast?', 'Lena', 'nl_new', 5 ],
		];
		$question_ids = [];
		foreach ( $questions as [ $key, $text, $name, $status, $minutes ] ) {
			$question_ids[ $key ] = ensure_question( $election, 'question:election:' . $key, $text, $name, $status, $at( $minutes ) );
		}

		$entries = [
			[ 'e01', 185, 'marcus.delgado', [ 'title' => 'Welcome to our election night live coverage' ], [ [ 'p', 'Good evening from the Ledger newsroom. We will bring you results for mayor, City Council and Measure R as the county reports them, with reporters at both campaign parties.' ] ] ],
			[ 'e02', 178, 'priya.raman', [ 'title' => 'Polls are open for one more hour' ], [ [ 'p', 'Anyone in line when the polls close can still vote. The county elections office reported short lines at most of its 42 polling places.' ] ] ],
			[ 'e03', 172, 'marcus.delgado', [], [ [ 'p', 'Turnout was already at 38% an hour before the polls closed, according to the county clerk, ahead of the 2022 pace.' ] ] ],
			[ 'e04', 165, 'priya.raman', [ 'title' => 'Ready for voters' ], null, 'image' ],
			[ 'e05', 158, 'marcus.delgado', [ 'title' => 'Before the results: where the candidates stand' ], null, 'card-issues' ],
			[ 'e06', 150, 'marcus.delgado', [], [ [ 'quote', 'Whatever happens tonight, this city showed up.', 'Diane Hollis, speaking to supporters at the Grange Hall' ] ] ],
			[ 'e07', 142, 'priya.raman', [ 'title' => 'Your questions about the count' ], null, 'qa-count' ],
			[ 'e08', 135, 'marcus.delgado', [], [ [ 'p', 'Raymond Ochoa arrived at his watch party at the Riverside Brewing Co. and shook hands with volunteers for twenty minutes.' ] ] ],
			[ 'e09', 128, 'priya.raman', [ 'title' => 'Measure R: what is at stake' ], null, 'card-measure' ],
			[ 'e10', 120, 'marcus.delgado', [ 'title' => 'Polls are closed' ], [ [ 'p', 'The polls are closed. The county expects to post its first batch of results, early and mail-in votes, within the hour.' ] ] ],
			[ 'e11', 112, 'priya.raman', [], [ [ 'p', 'Two polling places in East Bend stayed open 30 minutes late after a court order, because of a power outage earlier in the day.' ] ] ],
			[ 'e12', 105, 'marcus.delgado', [ 'title' => 'First results: Ochoa leads early vote' ], [ [ 'p', 'With early and mail-in ballots counted, Ochoa has 52.4% and Hollis 47.6%. Election Day votes, which tend to favor Hollis, are still to come.' ] ] ],
			[ 'e13', 98, 'priya.raman', [ 'title' => 'Measure R ahead' ], [ [ 'p', 'The flood wall bond has 58% support in the first count. It needs a simple majority to pass.' ] ] ],
			[ 'e14', 90, 'marcus.delgado', [ 'title' => 'Your questions about Measure R' ], null, 'qa-measure' ],
			[ 'e15', 84, 'priya.raman', [], [ [ 'quote', 'We have been sandbagging this street every spring for fifteen years. I voted yes.', 'Rosa Jimenez, East Bend resident' ] ] ],
			[ 'e16', 76, 'marcus.delgado', [ 'title' => 'Analysis: the east side decides this race' ], [ [ 'p', 'Hollis needs a strong showing in the east side precincts, where she won her council seat twice. The early vote suggests her margin there is thinner than in 2020.' ], [ 'p', 'Ochoa\'s campaign spent heavily on mail outreach, which explains his lead in the first count.' ] ] ],
			[ 'e17', 68, 'priya.raman', [], [ [ 'p', 'District 2 council race: incumbent Alicia Brandt leads challenger Greg Pell 61% to 39%.' ] ] ],
			[ 'e18', 60, 'marcus.delgado', [ 'title' => 'Second batch narrows the gap' ], [ [ 'p', 'With 64% of precincts reporting, Ochoa leads 50.9% to 49.1%, a margin of about 700 votes.' ] ] ],
			[ 'e19', 52, 'priya.raman', [ 'title' => 'Meanwhile, something else to watch' ], null, 'embed' ],
			[ 'e20', 45, 'marcus.delgado', [], [ [ 'p', 'District 4 is the closest council race of the night: 83 votes separate the two candidates.' ] ] ],
			[ 'e21', 36, 'priya.raman', [ 'title' => 'Measure R passes' ], [ [ 'p', 'The county projects Measure R will pass with about 57% of the vote. Construction of the riverfront flood wall could start next year.' ] ] ],
			[ 'e22', 28, 'marcus.delgado', [], [ [ 'quote', 'We are going to wait for every vote to be counted.', 'Raymond Ochoa\'s campaign manager Beth Arnold' ] ] ],
			[ 'e23', 18, 'marcus.delgado', [ 'title' => 'Hollis pulls ahead' ], [ [ 'p', 'With 88% of precincts reporting, Hollis now leads by 212 votes as Election Day ballots from the east side come in.' ] ] ],
			[ 'e24', 8, 'priya.raman', [], [ [ 'p', 'The county clerk says about 1,400 provisional ballots will be reviewed over the next week.' ] ] ],
			[ 'e25', 3, 'marcus.delgado', [ 'title' => 'Too close to call' ], [ [ 'p', 'With all precincts reporting, Hollis leads by 0.3 points. The race is within the margin that triggers an automatic recount if it holds after provisional ballots.' ] ] ],
		];
		foreach ( $entries as $entry ) {
			[ $key, $minutes, $login, $fields, $body ] = $entry;
			$kind  = $entry[5] ?? '';
			$extra = [];
			switch ( $kind ) {
				case 'image':
					// Reused from the demo media library (the early-voting story's photo).
					$image   = (int) get_post_thumbnail_id( $article( 'riverton-03' ) );
					$content = render_blocks( [ [ 'p', 'Election workers began sorting mail-in ballots at the county elections office this afternoon, next to the booths set up for today.' ] ] ) . "\n\n" . ( $image ? image_block( $image, 'Voting booths ready at the county elections office.' ) : '' );
					break;
				case 'card-issues':
					$content = render_blocks( [ [ 'p', 'Hollis and Ochoa disagree most on housing and the riverfront. Our guide to their positions:' ] ] ) . "\n\n" . card_block( $article( 'riverton-02' ) );
					break;
				case 'card-measure':
					$content = render_blocks( [ [ 'p', 'Measure R would borrow $40 million to build a flood wall along the riverfront. Read our explainer:' ] ] ) . "\n\n" . card_block( $article( 'riverton-05' ) );
					break;
				case 'qa-count':
					$content = render_blocks( [ [ 'p', 'Hi Ana, Ben and Carla, the county will post results in batches through the night. Mail-in ballots postmarked by today and received by Friday are counted too, and a margin under half a point triggers an automatic recount.' ] ] );
					$extra   = [ 'question_ids' => array_values( array_filter( [ $question_ids['q01'], $question_ids['q02'], $question_ids['q03'] ] ) ) ];
					break;
				case 'qa-measure':
					$content = render_blocks( [ [ 'p', 'Hi Dev and Elena, the city estimates about $6 a month for the median homeowner. Construction could start next spring if bonds are sold this winter.' ] ] );
					$extra   = [ 'question_ids' => array_values( array_filter( [ $question_ids['q04'], $question_ids['q05'] ] ) ) ];
					break;
				case 'embed':
					$content = render_blocks( [ [ 'p', 'A break from the count: several readers asked for something else to watch while they wait. NASA\'s live stream:' ] ] ) . "\n\n" . embed_block( 'https://www.youtube.com/watch?v=21X5lGlDOfg' );
					break;
				default:
					$content = render_blocks( $body );
			}
			$data = [
				'title'   => $fields['title'] ?? '',
				'content' => $content,
				'status'  => 'publish',
				'author'  => $author( $login ),
				'time'    => $at( $minutes ),
			] + $extra;
			$entry_id = ensure_entry( $election, 'entry:election:' . $key, $data );
			foreach ( $entry_id ? $extra['question_ids'] ?? [] : [] as $question_id ) {
				if ( 'nl_answered' !== get_post_status( $question_id ) ) {
					\Newspack_Live\Questions::set_status( (int) $question_id, 'nl_answered', $entry_id );
				}
			}
		}

		// Pinned: the "polls are closed" entry stays on top while results come in.
		$pinned = find_seeded_post( 'entry:election:e10', \Newspack_Live\Post_Types::ENTRY );
		if ( $pinned && ! \Newspack_Live\Feed::get_pinned_id( $election ) ) {
			\Newspack_Live\Entries::set_flags( $pinned, [ 'pinned' => true ] );
		}

		seed_labels(
			$election,
			[
				'e04' => 'photos',
				'e12' => 'Results',
				'e18' => 'Results',
				'e19' => 'video',
				'e23' => 'key-point',
				'e25' => 'breaking',
			]
		);

		log( sprintf( 'Live #%d: %s', $election, \Newspack_Live\Live::get_state( $election ) ) );
	}

	if ( $storm && get_post_meta( $storm, MARKER, true ) ) {
		$description = render_blocks( [ [ 'p', 'Our live coverage starts tomorrow evening. Send us your questions about evacuations, shelters and road closures below.' ] ] );
		$context     = context_html( [ 'Hurricane Delia is forecast to reach the Riverton valley tomorrow evening as a Category 1 storm, with heavy rain and river flooding expected through the weekend.' ] );
		if ( ! \Newspack_Live\Live::is_live( $storm ) ) {
			enable_live( $storm, $description, $now );
			log( "Live enabled: #{$storm}" );
		}
		sync_context( $storm, $description, $context );
		$tomorrow = new \DateTimeImmutable( 'tomorrow 18:00', wp_timezone() );
		update_post_meta( $storm, \Newspack_Live\Live::META_SCHEDULED_START, $tomorrow->getTimestamp() );
		// Upcoming again: forget an end persisted after an earlier start passed (auto-end cron).
		delete_post_meta( $storm, \Newspack_Live\Live::META_END );
		delete_post_meta( $storm, \Newspack_Live\Live::META_START );
		log( sprintf( 'Live #%d: %s (starts %s)', $storm, \Newspack_Live\Live::get_state( $storm ), $tomorrow->format( 'Y-m-d H:i T' ) ) );
	}

	seed_ended_live( $users, $now );
}

/**
 * Seeds the ended demo live: the Riverside Bridge reopening, so the three listing badges
 * (Live, Upcoming live, Live ended) can be seen together on the homepage.
 *
 * @param array $users Users keyed by login.
 * @param int   $now   Current time.
 * @return void
 */
function seed_ended_live( array $users, int $now ): void {
	$bridge = find_seeded_post( 'post:riverton-live-bridge', 'post' );
	if ( ! $bridge || ! get_post_meta( $bridge, MARKER, true ) ) {
		return;
	}
	$start = $now - 6 * HOUR_IN_SECONDS;
	if ( ! \Newspack_Live\Live::is_live( $bridge ) ) {
		enable_live( $bridge, '', $start );
		update_post_meta( $bridge, \Newspack_Live\Live::META_START, $start );
		log( "Live enabled: #{$bridge}" );
	}
	sync_context(
		$bridge,
		render_blocks( [ [ 'p', 'Cars crossed the Riverside Bridge again this morning, 14 months after it closed for repairs. Our live coverage of the reopening has ended.' ] ] ),
		context_html(
			[
				'The bridge carries about 18,000 vehicles a day between East Bend and downtown.',
				'Repairs to the deck and the river piers cost $22 million, paid by the state and the county.',
			]
		)
	);
	$author = (int) ( $users['priya.raman'] ?? get_post_field( 'post_author', $bridge ) );
	$hours  = static fn( float $hours_ago ): int => (int) ( $now - $hours_ago * HOUR_IN_SECONDS );
	foreach (
		[
			[ 'b1', 5.5, 'Crews remove the last barriers', 'Highway crews are taking down the barriers at both ends of the bridge. The county says traffic will flow by 9 a.m.' ],
			[ 'b2', 4.5, 'Ribbon cutting', 'County executive Ruth Okafor cut the ribbon with the engineers who led the repairs.' ],
			[ 'b3', 3.5, 'First cars across', 'The first cars crossed at 9:04 a.m. Bus routes 4 and 11 return to the bridge on Monday.' ],
			[ 'b4', 2.5, 'That is it for today', 'Thank you for following along. Our full story on the repairs and what changes for commuters is on the homepage.' ],
		] as [ $key, $ago, $title, $text ]
	) {
		ensure_entry(
			$bridge,
			'entry:bridge:' . $key,
			[
				'title'   => $title,
				'content' => render_blocks( [ [ 'p', $text ] ] ),
				'status'  => 'publish',
				'author'  => $author,
				'time'    => $hours( $ago ),
			]
		);
	}
	if ( \Newspack_Live\Live::STATE_ENDED !== \Newspack_Live\Live::get_state( $bridge ) ) {
		update_post_meta( $bridge, \Newspack_Live\Live::META_END, $hours( 2 ) );
	}
	log( sprintf( 'Live #%d: %s', $bridge, \Newspack_Live\Live::get_state( $bridge ) ) );
}

/**
 * Creates a demo question once, with an author.
 *
 * @param int    $live_id Live ID.
 * @param string $key     Seed key.
 * @param array  $item    `text`, `name`, `status`, `user`, `ago` (seconds).
 * @return int Question ID, or 0.
 */
function ensure_reader_item( int $live_id, string $key, array $item ): int {
	$existing = find_seeded_post( $key, \Newspack_Live\Post_Types::QUESTION );
	if ( $existing ) {
		return $existing;
	}
	$id = \Newspack_Live\Questions::create( $live_id, $item['text'], $item['name'], (int) ( $item['user'] ?? 0 ), $item['status'] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "Could not create {$key}: " . $id->get_error_message() );
		return 0;
	}
	$gmt = gmdate( 'Y-m-d H:i:s', time() - (int) ( $item['ago'] ?? 0 ) );
	wp_update_post(
		[
			'ID'            => $id,
			'post_date_gmt' => $gmt,
			'post_date'     => get_date_from_gmt( $gmt ),
		]
	);
	update_post_meta( $id, MARKER, 1 );
	update_post_meta( $id, SEED_KEY, $key );
	return (int) $id;
}

/**
 * Participation demo on Election night: three similar questions, one from a registered
 * reader, answered in one Q&A entry.
 *
 * @return void
 */
function seed_participation(): void {
	$election = find_seeded_post( 'post:riverton-live-election', 'post' );
	if ( ! $election || ! \Newspack_Live\Live::is_live( $election ) ) {
		return;
	}
	// A registered demo reader.
	$reader = get_user_by( 'login', 'riverton.reader' );
	if ( ! $reader ) {
		$reader_id = wp_insert_user(
			[
				'user_login'   => 'riverton.reader',
				'user_pass'    => wp_generate_password( 24, true, false ),
				'user_email'   => 'riverton.reader@riverton-ledger.test',
				'display_name' => 'Jordan Ellis',
				'role'         => 'subscriber',
			]
		);
		if ( is_wp_error( $reader_id ) ) {
			WP_CLI::warning( 'Could not create the demo reader: ' . $reader_id->get_error_message() );
			return;
		}
		update_user_meta( $reader_id, MARKER, 1 );
		$reader = get_userdata( $reader_id );
		log( '  Created the demo reader riverton.reader (subscriber)' );
	}

	$editor = get_user_by( 'login', 'marcus.delgado' );
	$author = $editor ? (int) $editor->ID : 1;

	// Three similar questions (grouped on creation), answered together.
	$group = [];
	foreach (
		[
			[ 'g1', 'When will the final results be certified?', 'Jordan Ellis', (int) $reader->ID ],
			[ 'g2', 'When are the results certified?', 'Ana', 0 ],
			[ 'g3', 'When will the final results be certified by the county?', 'Theo', 0 ],
		] as [ $key, $text, $name, $user ]
	) {
		$group[] = ensure_reader_item( $election, 'question:election:' . $key, [ 'text' => $text, 'name' => $name, 'status' => 'nl_new', 'user' => $user, 'ago' => 35 * MINUTE_IN_SECONDS ] );
	}
	$group = array_values( array_filter( $group ) );
	if ( count( $group ) !== 3 ) {
		return;
	}
	$qa = ensure_entry(
		$election,
		'entry:election:qa-certified',
		[
			'title'        => 'Your questions about certification',
			'content'      => render_blocks( [ [ 'p', 'The county canvass board certifies the results within ten days, after provisional ballots are reviewed. Results stay unofficial until then.' ] ] ),
			'status'       => 'publish',
			'author'       => $author,
			'time'         => time() - 30 * MINUTE_IN_SECONDS,
			'question_ids' => $group,
		]
	);
	foreach ( $qa ? $group : [] as $id ) {
		if ( 'nl_answered' !== get_post_status( $id ) ) {
			\Newspack_Live\Questions::set_status( $id, 'nl_answered', $qa );
		}
	}
}

/**
 * Console paging demo: 30 short ward roundups on Election night, so the live
 * has more than 50 updates and the console shows "Load more". Marked like every demo entry; the
 * copy has no dates or clock times.
 *
 * @param array $users Demo users (login => ID).
 */
function seed_paging( array $users ): void {
	$election = find_seeded_post( 'post:riverton-live-election', 'post' );
	if ( ! $election || ! class_exists( '\Newspack_Live\Entries' ) ) {
		return;
	}
	$now     = time();
	$created = 0;
	for ( $ward = 1; $ward <= 30; $ward++ ) {
		$key = 'entry:election:ward-' . $ward;
		if ( find_seeded_post( $key, \Newspack_Live\Post_Types::ENTRY ) ) {
			continue;
		}
		$hollis  = 44 + ( $ward * 7 ) % 13;
		$measure = 52 + ( $ward * 5 ) % 15;
		$login   = 0 === $ward % 2 ? 'priya.raman' : 'marcus.delgado';
		$entry   = ensure_entry(
			$election,
			$key,
			[
				'title'   => sprintf( 'Ward %d roundup', $ward ),
				'content' => render_blocks(
					[
						[ 'p', sprintf( 'Ward %1$d has reported all of its precincts: Hollis %2$d%%, Ochoa %3$d%%. Measure R gets %4$d%% here.', $ward, $hollis, 100 - $hollis, $measure ) ],
					]
				),
				'author'  => (int) ( $users[ $login ] ?? get_post_field( 'post_author', $election ) ),
				// Spread across the evening, between the main updates.
				'time'    => $now - ( 178 - $ward * 5 ) * MINUTE_IN_SECONDS - 90,
			]
		);
		$created += $entry ? 1 : 0;
	}
	if ( $created ) {
		log( "  Ward roundups created on Election night: {$created}" );
	}
}

/**
 * Collections demo: one demo Collection holding three storm stories, used as
 * Storm Watch's related coverage. Only when the Newspack Collections module is active;
 * adds the Collection term to demo posts, removes nothing.
 */
function seed_collections(): void {
	$storm = find_seeded_post( 'post:riverton-live-storm', 'post' );
	if ( ! $storm || ! class_exists( '\Newspack_Live\Integrations\Collections' ) || ! \Newspack_Live\Integrations\Collections::available() ) {
		return;
	}
	$type       = \Newspack\Collections\Post_Type::get_post_type();
	$collection = find_seeded_post( 'collection:storm-season', $type );
	if ( ! $collection ) {
		$collection = wp_insert_post(
			[
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => 'Storm season guide (demo)',
				'post_content' => render_blocks( [ [ 'p', 'What to know before, during and after a storm in Riverton.' ] ] ),
				'meta_input'   => [
					MARKER   => 1,
					SEED_KEY => 'collection:storm-season',
				],
			]
		);
		if ( ! $collection || is_wp_error( $collection ) ) {
			WP_CLI::warning( 'Could not create the demo Collection.' );
			return;
		}
		log( "  Collection created (#{$collection})" );
	}
	$term = (int) \Newspack\Collections\Sync::get_term_linked_to_collection( (int) $collection );
	if ( ! $term ) {
		WP_CLI::warning( 'The demo Collection has no linked term.' );
		return;
	}
	foreach ( [ 'riverton-12', 'riverton-13', 'riverton-14' ] as $seed ) {
		$post_id = find_seeded_post( 'post:' . $seed, 'post' );
		if ( $post_id && ! has_term( $term, \Newspack\Collections\Collection_Taxonomy::get_taxonomy(), $post_id ) ) {
			wp_set_object_terms( $post_id, [ $term ], \Newspack\Collections\Collection_Taxonomy::get_taxonomy(), true );
		}
	}
	// Set once (an editor's later choice on the demo live is kept).
	if ( ! get_post_meta( $storm, '_newspack_live_related_collection', true ) ) {
		update_post_meta( $storm, '_newspack_live_related_source', 'collection' );
		update_post_meta( $storm, '_newspack_live_related_collection', (int) $collection );
	}
}

$newspack_live_users = seed_users();
$newspack_live_terms = seed_terms();
seed_posts( $newspack_live_users, $newspack_live_terms );
$newspack_live_pages = seed_pages( $newspack_live_terms );
seed_front_page( $newspack_live_pages['home'] );
seed_menu( $newspack_live_terms, $newspack_live_pages['about'] );
seed_lives( $newspack_live_users );
seed_paging( $newspack_live_users );
seed_participation();
seed_collections();

WP_CLI::success( 'Demo content is in place.' );
