<?php
/**
 * State badges of lives in post listings.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Integrations\Homepage_Posts;
use Newspack_Live\Listings;
use Newspack_Live\Live;
use WP_Block;
use WP_UnitTestCase;

/**
 * Badges in each state; classic theme categories line, block theme Query Loop titles, Homepage Posts;
 * `the_title` untouched.
 *
 * @group render
 */
class Test_Listings extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * A live, an upcoming live, an ended live and a regular post.
	 *
	 * @return array{live: int, upcoming: int, ended: int, post: int}
	 */
	private function posts(): array {
		$ended = $this->create_live( [ 'post_title' => 'Ended live' ] );
		Live::apply_action( $ended, 'end' );
		return [
			'live'     => $this->create_live( [ 'post_title' => 'Live now' ] ),
			'upcoming' => $this->create_live( [ 'post_title' => 'Later' ], [ Live::META_SCHEDULED_START => time() + DAY_IN_SECONDS ] ),
			'ended'    => $ended,
			'post'     => self::factory()->post->create( [ 'post_title' => 'An article' ] ),
		];
	}

	/**
	 * One badge per state, self-styled with readable colors; nothing for other posts.
	 */
	public function test_badges(): void {
		$posts = $this->posts();
		$this->assertMatchesRegularExpression( '#^<span class="newspack-live-badge newspack-live-badge--live" style="[^"]*background:\#c8102e"><span class="newspack-live-badge__dot" style="[^"]+" aria-hidden="true"></span>Live</span>$#', Listings::badge( $posts['live'] ) );
		$this->assertMatchesRegularExpression( '#newspack-live-badge--upcoming" style="[^"]*">Upcoming live</span>$#', Listings::badge( $posts['upcoming'] ) );
		$this->assertMatchesRegularExpression( '#newspack-live-badge--ended" style="[^"]*background:\#4b5563">Live ended</span>$#', Listings::badge( $posts['ended'] ) );
		$this->assertSame( '', Listings::badge( $posts['post'] ) );
		$draft = $this->create_live( [ 'post_status' => 'draft' ] );
		$this->assertSame( '', Listings::badge( $draft ), 'Drafts are never listed.' );
		// Inline styles survive the theme's `wp_kses_post()` on the category line.
		$this->assertSame( Listings::badge( $posts['live'] ), wp_kses_post( Listings::badge( $posts['live'] ) ) );
	}

	/**
	 * Classic themes: the badge opens the category line of archives and search results, never on the
	 * live's own page; `the_title` is never filtered.
	 */
	public function test_classic_category_line(): void {
		$posts = $this->posts();
		$this->go_to( get_category_link( (int) get_the_category( $posts['live'] )[0]->term_id ) );
		foreach ( [
			'live'     => 'newspack-live-badge--live',
			'upcoming' => 'newspack-live-badge--upcoming',
			'ended'    => 'newspack-live-badge--ended',
		] as $name => $class ) {
			$GLOBALS['post'] = get_post( $posts[ $name ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture: the loop's post.
			$line = apply_filters( 'newspack_theme_categories', '<a href="#">Politics</a>' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack theme filter.
			$this->assertStringStartsWith( '<span class="newspack-live-badge ' . $class . '"', $line, $name );
			$this->assertStringEndsWith( ' <a href="#">Politics</a>', $line );
		}
		$GLOBALS['post'] = get_post( $posts['post'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( '<a href="#">Politics</a>', apply_filters( 'newspack_theme_categories', '<a href="#">Politics</a>' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack theme filter.

		$this->go_to( get_permalink( $posts['live'] ) );
		$GLOBALS['post'] = get_post( $posts['live'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( '<a href="#">Politics</a>', apply_filters( 'newspack_theme_categories', '<a href="#">Politics</a>' ), 'Not on its own page.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack theme filter.

		$this->assertSame( 'Live now', get_the_title( $posts['live'] ) );
		$this->assertFalse( has_filter( 'the_title', [ Listings::class, 'theme_categories' ] ) );
		foreach ( (array) ( $GLOBALS['wp_filter']['the_title']->callbacks ?? [] ) as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$this->assertStringNotContainsString( 'Newspack_Live', is_array( $callback['function'] ) && is_string( $callback['function'][0] ) ? $callback['function'][0] : '' );
			}
		}
	}

	/**
	 * Block themes: the post title of a listing gets the badge inside its heading; the live's own
	 * page doesn't.
	 */
	public function test_query_loop_title(): void {
		$posts  = $this->posts();
		$render = static function ( int $post_id, bool $in_query ): string {
			$context = [
				'postId'   => $post_id,
				'postType' => 'post',
			];
			if ( $in_query ) {
				$context['queryId'] = 1;
			}
			$block = new WP_Block(
				[
					'blockName'    => 'core/post-title',
					'attrs'        => [
						'level'  => 4,
						'isLink' => true,
					],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
				$context
			);
			// The block reads the global post, as the Query Loop's post template sets it.
			$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test.
			setup_postdata( $GLOBALS['post'] );
			$html = $block->render();
			wp_reset_postdata();
			return $html;
		};
		$this->assertMatchesRegularExpression( '#^<h4 class="[^"]*wp-block-post-title[^"]*"><span class="newspack-live-badge newspack-live-badge--live"[^>]*>.*?Live</span> <a href="[^"]+"[^>]*>Live now</a></h4>$#s', $render( $posts['live'], true ) );
		$this->assertStringContainsString( '>Live ended</span> <a', $render( $posts['ended'], true ) );
		$this->assertStringContainsString( '>Upcoming live</span> <a', $render( $posts['upcoming'], true ) );
		$this->assertStringNotContainsString( 'newspack-live-badge', $render( $posts['post'], true ) );
		// The post template passes no `queryId`: a title without it is still a listing.
		$this->assertStringContainsString( '>Live</span> <a', $render( $posts['live'], false ) );
		$this->go_to( get_permalink( $posts['live'] ) );
		$this->assertStringNotContainsString( 'newspack-live-badge', $render( $posts['live'], false ), "Not on the live's own page." );
	}

	/**
	 * Homepage Posts: the badge before the teaser title, in every state.
	 */
	public function test_homepage_posts(): void {
		$posts = $this->posts();
		foreach ( [
			'live'     => 'Live',
			'upcoming' => 'Upcoming live',
			'ended'    => 'Live ended',
		] as $name => $text ) {
			ob_start();
			do_action( 'newspack_blocks_content_loop_entry_wrapper_start', $posts[ $name ], [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack Blocks action.
			$this->assertStringContainsString( '>' . $text . '</span>', (string) ob_get_clean(), $name );
		}
		ob_start();
		Homepage_Posts::render_badge( $posts['post'] );
		$this->assertSame( '', ob_get_clean() );
	}
}
