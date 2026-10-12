<?php
/**
 * Live page renderer.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side rendering of the live layout, Context card, entries and aside.
 *
 * The same entry renderer feeds the page and the REST API, so HTML inserted by the view
 * module is identical to server-rendered HTML.
 */
final class Renderer {

	/**
	 * Object cache group.
	 */
	public const CACHE_GROUP = 'newspack_live';

	/**
	 * Version of the entry markup, part of the entry cache key: bump it when the markup changes so
	 * HTML cached by an older release isn't served with newer styles.
	 */
	public const ENTRY_MARKUP_VERSION = 6;

	/**
	 * Above this many waiting updates the "new updates" button reads "20+" and reloads the page.
	 * Mirrors `PENDING_CAP` in `src/blocks/live/pending.ts`.
	 */
	public const PENDING_CAP = 20;

	/**
	 * Current page view from the registered query vars. The feed is always newest first.
	 *
	 * @param int $live_id Live ID.
	 * @return array{type: string, cursor: int}
	 */
	public static function get_view( int $live_id ): array {
		$entry  = absint( get_query_var( 'live-entry' ) );
		$before = absint( get_query_var( 'live-before' ) );
		if ( $entry > 0 ) {
			return [
				'type'   => 'entry',
				'cursor' => $entry,
			];
		}
		if ( $before > 0 ) {
			return [
				'type'   => 'before',
				'cursor' => $before,
			];
		}
		return [
			'type'   => 'latest',
			'cursor' => 0,
		];
	}

	/**
	 * Plural forms of the view module's count strings, with the form of every count from 0 to 199
	 * (plural rules repeat every 100 above that), so the client follows the language's plural rule
	 * instead of a singular/plural pair. Built with `translate_plural()`, which every translation
	 * loader implements.
	 *
	 * @return array<string, array{forms: string[], table: int[]}>
	 */
	public static function plurals(): array {
		$strings = [
			/* translators: %d: number of new updates waiting, on the "new updates" button. */
			'newUpdates' => _n_noop( '%d new update', '%d new updates', 'newspack-live' ),
			/* translators: %d: number of new updates waiting, announced to screen readers. */
			'announce'   => _n_noop( '%d new update available', '%d new updates available', 'newspack-live' ),
		];
		$translations = get_translations_for_domain( 'newspack-live' );
		$plurals      = [];
		foreach ( $strings as $key => $noop ) {
			$forms = [];
			$table = [];
			for ( $count = 0; $count < 200; $count++ ) {
				$text  = (string) $translations->translate_plural( $noop['singular'], $noop['plural'], $count );
				$index = array_search( $text, $forms, true );
				if ( false === $index ) {
					$forms[] = $text;
					$index   = count( $forms ) - 1;
				}
				$table[] = (int) $index;
			}
			$plurals[ $key ] = [
				'forms' => $forms,
				'table' => $table,
			];
		}
		return $plurals;
	}

	/**
	 * Renders the full live layout.
	 *
	 * @param int   $live_id Live ID.
	 * @param array $options `show_title_media` (bool): render the title and header media. `wrapper_attributes` (string).
	 * @return string HTML.
	 */
	public static function render_live( int $live_id, array $options = [] ): string {
		if ( ! Live::is_live( $live_id ) ) {
			return '';
		}
		$options  = wp_parse_args(
			$options,
			[
				'show_title_media'   => true,
				'wrapper_attributes' => '',
			]
		);
		$state    = Live::get_state( $live_id );
		$view     = self::get_view( $live_id );
		$settings = Live::get_settings( $live_id );

		if ( post_password_required( $live_id ) ) {
			return self::render_password_form( $live_id, $state, $options );
		}
		$restricted = self::is_restricted( $live_id );
		$feed       = $restricted ? '' : self::render_feed( $live_id, $state, $view );
		self::interactivity_state( $live_id, $state, $view, $restricted );

		$classes = [ 'newspack-live', 'newspack-live--' . $state, 'newspack-live--view-' . $view['type'] ];
		ob_start();
		?>
		<div <?php echo $options['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes() or empty. ?>>
		<div
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			id="newspack-live"
			data-live-id="<?php echo esc_attr( (string) $live_id ); ?>"
			data-rev="<?php echo esc_attr( (string) Live::get_rev( $live_id ) ); ?>"
			data-state="<?php echo esc_attr( $state ); ?>"
			data-view="<?php echo esc_attr( $view['type'] ); ?>"
		>
			<?php if ( ! $restricted ) : ?>
				<a class="newspack-live__skip" href="#newspack-live-feed"><?php esc_html_e( 'Skip to latest updates', 'newspack-live' ); ?></a>
			<?php endif; ?>
			<?php echo self::render_header( $live_id, $state, (bool) $options['show_title_media'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_header(). ?>
			<?php $aside = self::render_aside( $live_id ); ?>
			<div class="newspack-live__layout<?php echo '' === $aside ? '' : ' newspack-live__layout--aside'; ?>">
				<div class="newspack-live__main">
					<?php
					if ( $restricted ) {
						/**
						 * Fires where the feed would be on a restricted live, so the Content Gate can render.
						 *
						 * @since 1.2.0
						 *
						 * @param int $live_id Live ID.
						 */
						do_action( 'newspack_live_restricted', $live_id );
					} else {
						// The description and the Context card are the live's own content: gated like the feed.
						echo self::render_description( $live_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_description().
						echo self::render_context( $live_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_context().
						echo self::render_ask_form( $live_id, $state, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_ask_form().
						echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_feed().
					}
					?>
				</div>
				<?php echo $aside; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_related() and by the ad placements. ?>
			</div>
		</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Initial state of the view module. Public pages carry no nonce or user data;
	 * gated lives, which are never cached publicly, get a REST nonce for signed-in readers.
	 *
	 * @param int    $live_id    Live ID.
	 * @param string $state      State.
	 * @param array  $view       View.
	 * @param bool   $restricted Whether the feed is restricted for this reader.
	 * @return void
	 */
	public static function interactivity_state( int $live_id, string $state, array $view, bool $restricted ): void {
		if ( ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}
		$gated = self::is_gated( $live_id );
		wp_interactivity_state(
			'newspack-live',
			[
				'liveId'          => $live_id,
				'rev'             => Live::get_rev( $live_id ),
				'liveState'       => $restricted ? Live::STATE_ENDED : $state,
				'pollInterval'    => REST\REST_Public::poll_interval( $live_id ),
				'restUrl'         => untrailingslashit( rest_url( REST\REST_Public::NAMESPACE ) ),
				// Signed-in readers only (their pages aren't publicly cached): registered-only questions and gated lives.
				'nonce'           => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'loggedIn'        => is_user_logged_in(),
				'nonceUrl'        => admin_url( 'admin-ajax.php?action=rest-nonce' ),
				'gated'           => $gated,
				'view'            => $view['type'],
				'perPage'         => Feed::per_page(),
				'pendingCount'    => 0,
				'hasPending'      => false,
				'newUpdatesLabel' => '',
				'connectionLost'  => false,
				'plurals'         => self::plurals(),
				// Site timezone (IANA name or "+02:00"), for the time labels the view module corrects.
				'timezone'        => wp_timezone_string(),
				'strings'         => [
					'startReached'     => __( "You've reached the start of this live.", 'newspack-live' ),
					/* translators: %d: the cap (20). On the "new updates" button above it, e.g. "20+ new updates". */
					'newUpdatesCapped' => sprintf( __( '%d+ new updates', 'newspack-live' ), self::PENDING_CAP ),
					/* translators: %d: the cap (20). Announced to screen readers above it, e.g. "20+ new updates available". */
					'announceCapped'   => sprintf( __( '%d+ new updates available', 'newspack-live' ), self::PENDING_CAP ),
					'loadOlder'        => __( 'Load older updates', 'newspack-live' ),
					'connection'       => __( 'Connection lost. Retrying…', 'newspack-live' ),
					/* translators: %s: time (e.g. "10:48 PM"). */
					'yesterday'        => __( 'Yesterday, %s', 'newspack-live' ),
					/* translators: %s: time (e.g. "10:48 PM"). Mid-sentence, after "Updated". */
					'yesterdayMid'     => _x( 'yesterday, %s', 'mid-sentence', 'newspack-live' ),
					/* translators: 1: short date (e.g. "Sep 27" or "Sep 27, 2025"), 2: time. */
					'dateTime'         => _x( '%1$s, %2$s', 'entry date and time', 'newspack-live' ),
					/* translators: %s: time (e.g. "8:57 am"). Header line of a live updated today. */
					'updated'          => __( 'Updated %s', 'newspack-live' ),
					/* translators: %s: time (e.g. "8:57 am"). */
					'updatedYesterday' => __( 'Updated yesterday at %s', 'newspack-live' ),
					/* translators: 1: short date (e.g. "Sep 27" or "Sep 27, 2025"), 2: time. */
					'updatedDate'      => __( 'Updated %1$s at %2$s', 'newspack-live' ),
					'copied'           => __( 'Link copied', 'newspack-live' ),
					'copyFailed'       => __( 'The link could not be copied.', 'newspack-live' ),
					'live'             => _x( 'Live', 'live badge', 'newspack-live' ),
					'ended'            => _x( 'Ended', 'live badge', 'newspack-live' ),
					'questionsClosed'  => __( 'Questions are closed.', 'newspack-live' ),
					/* translators: 1: characters typed, 2: maximum. */
					'askCounter'       => __( '%1$d/%2$d', 'newspack-live' ),
					'askSending'       => __( 'Sending…', 'newspack-live' ),
					'askSend'          => __( 'Send question', 'newspack-live' ),
					'askSuccess'       => __( "Thanks! Your question was sent to the newsroom. We can't answer every question — keep an eye on the live.", 'newspack-live' ),
					'askNetwork'       => __( 'Something went wrong. Please try again.', 'newspack-live' ),
					'askInvalidLength' => __( 'Your question must be between 1 and 500 characters, and your name between 1 and 50.', 'newspack-live' ),
				],
			]
		);
	}

	/**
	 * Header and the core password form, for password-protected lives.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $state   State.
	 * @param array  $options Render options.
	 * @return string
	 */
	private static function render_password_form( int $live_id, string $state, array $options ): string {
		return '<div ' . $options['wrapper_attributes'] . '><div class="newspack-live newspack-live--protected" id="newspack-live">'
			. self::render_header( $live_id, $state, (bool) $options['show_title_media'] )
			. get_the_password_form( $live_id )
			. '</div></div>';
	}

	/**
	 * Whether the live is restricted for the current reader (Content Gate).
	 *
	 * @param int $live_id Live ID.
	 * @return bool
	 */
	public static function is_restricted( int $live_id ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack's Content Gate filter.
		return (bool) apply_filters( 'newspack_is_post_restricted', false, $live_id );
	}

	/**
	 * Whether the live is gated for some readers, independently of the current one
	 * (Newspack's `newspack_post_has_restrictions`). Gated responses are never publicly cached.
	 *
	 * @param int $live_id Live ID.
	 * @return bool
	 */
	public static function is_gated( int $live_id ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Newspack's Content Gate filter.
		return (bool) apply_filters( 'newspack_post_has_restrictions', false, $live_id ) || self::is_restricted( $live_id );
	}

	/**
	 * Status badge HTML.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $state   State.
	 * @return string
	 */
	public static function render_badge( int $live_id, string $state ): string {
		switch ( $state ) {
			case Live::STATE_LIVE:
				return '<span class="newspack-live-badge newspack-live-badge--live"><span class="newspack-live-badge__dot" aria-hidden="true"></span>' . esc_html_x( 'Live', 'live badge', 'newspack-live' ) . '</span>';
			case Live::STATE_UPCOMING:
				return '<span class="newspack-live-badge newspack-live-badge--upcoming">' . esc_html( self::upcoming_label( $live_id ) ) . '</span>';
			case Live::STATE_ENDED:
				return '<span class="newspack-live-badge newspack-live-badge--ended">' . esc_html_x( 'Ended', 'live badge', 'newspack-live' ) . '</span>';
		}
		return '';
	}

	/**
	 * "Upcoming · Starts {time}".
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function upcoming_label( int $live_id ): string {
		/* translators: %s: start time. */
		return sprintf( __( 'Upcoming · Starts %s', 'newspack-live' ), Dates::start_label( Live::get_start( $live_id ) ) );
	}

	/**
	 * Header: badge, updated label, title, standfirst, byline, share, media.
	 *
	 * @param int    $live_id          Live ID.
	 * @param string $state            State.
	 * @param bool   $show_title_media Whether to render the title and the header media.
	 * @return string
	 */
	public static function render_header( int $live_id, string $state, bool $show_title_media = true ): string {
		$updated    = Live::get_last_update( $live_id );
		$standfirst = self::get_standfirst( $live_id );
		$byline     = self::render_byline( $live_id );
		$url        = (string) get_permalink( $live_id );
		ob_start();
		?>
		<header class="newspack-live__header">
			<div class="newspack-live__status" data-wp-interactive="newspack-live" data-wp-init="callbacks.init">
				<?php echo self::render_badge( $live_id, $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_badge(). ?>
				<?php
				if ( $updated > 0 ) {
					echo Dates::updated_element( $updated ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Dates::updated_element().
				}
				?>
			</div>
			<?php if ( $show_title_media ) : ?>
				<h1 class="newspack-live__title"><?php echo esc_html( get_the_title( $live_id ) ); ?></h1>
			<?php endif; ?>
			<?php if ( '' !== $standfirst ) : ?>
				<p class="newspack-live__standfirst"><?php echo esc_html( $standfirst ); ?></p>
			<?php endif; ?>
			<div class="newspack-live__meta">
				<?php echo $byline; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_byline(). ?>
				<?php echo self::render_share( $url, get_the_title( $live_id ), 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_share(). ?>
			</div>
			<?php
			if ( $show_title_media ) {
				echo self::render_header_media( $live_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_header_media().
			}
			?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Standfirst: Newspack subtitle, else the manual excerpt.
	 *
	 * @param int $live_id Live ID.
	 * @return string Plain text.
	 */
	public static function get_standfirst( int $live_id ): string {
		// Core hides the excerpt of password-protected posts.
		$excerpt_allowed = ! post_password_required( $live_id );
		$subtitle = trim( (string) get_post_meta( $live_id, 'newspack_post_subtitle', true ) );
		if ( '' !== $subtitle ) {
			return wp_strip_all_tags( $subtitle );
		}
		return $excerpt_allowed ? trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $live_id ) ) ) : '';
	}

	/**
	 * Joins names: "A", "A and B", "A, B and C".
	 *
	 * @param string[] $names Names (already escaped HTML fragments).
	 * @return string
	 */
	public static function join_names( array $names ): string {
		$names = array_values( $names );
		$count = count( $names );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $names[0];
		}
		$last = array_pop( $names );
		/* translators: 1: comma-separated names, 2: last name. */
		return sprintf( esc_html__( '%1$s and %2$s', 'newspack-live' ), implode( esc_html_x( ', ', 'list separator', 'newspack-live' ), $names ), $last );
	}

	/**
	 * "Live hosted by A, B and C" with links to the author archives.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_byline( int $live_id ): string {
		$links = [];
		foreach ( Integrations\Co_Authors_Plus::get_hosts( $live_id ) as $host ) {
			$links[] = '' !== $host['url']
				? '<a class="newspack-live__host" href="' . esc_url( $host['url'] ) . '">' . esc_html( $host['name'] ) . '</a>'
				: '<span class="newspack-live__host">' . esc_html( $host['name'] ) . '</span>';
		}
		if ( ! $links ) {
			return '';
		}
		/* translators: %s: host names. */
		return '<p class="newspack-live__byline">' . sprintf( esc_html__( 'Live hosted by %s', 'newspack-live' ), self::join_names( $links ) ) . '</p>';
	}

	/**
	 * Share control: a `<details>` disclosure listing the share links, so it works without JavaScript.
	 * The view module opens the Web Share sheet instead when the browser has one, reveals "Copy link",
	 * keeps one disclosure open at a time and closes it with Escape.
	 *
	 * @param string $url     URL to share.
	 * @param string $title   Title to share.
	 * @param string $context `header` or `entry`.
	 * @return string
	 */
	public static function render_share( string $url, string $title, string $context ): string {
		$is_entry = 'entry' === $context;
		$links    = [
			'x'        => 'X',
			'facebook' => 'Facebook',
			'linkedin' => 'LinkedIn',
			'whatsapp' => 'WhatsApp',
			'bluesky'  => 'Bluesky',
		];
		ob_start();
		?>
		<details class="newspack-live-share<?php echo $is_entry ? ' newspack-live-share--entry' : ''; ?>" name="newspack-live-share">
			<summary class="newspack-live-share__button<?php echo $is_entry ? ' newspack-live-entry__share' : ''; ?>" data-action="share" data-url="<?php echo esc_url( $url ); ?>" data-title="<?php echo esc_attr( $title ); ?>">
				<?php if ( $is_entry ) : ?>
					<?php // A share icon (three connected dots); the name stays "Share: {title}". ?>
					<svg class="newspack-live-share__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M8.2 10.8 15.8 6.3M8.2 13.2l7.6 4.5"/></svg>
					<span class="newspack-live__sr-only">
						<?php
						/* translators: %s: title of the update. */
						echo esc_html( sprintf( __( 'Share: %s', 'newspack-live' ), $title ) );
						?>
					</span>
				<?php else : ?>
					<span class="newspack-live-share__label"><?php esc_html_e( 'Share', 'newspack-live' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="newspack-live-share__popover">
				<?php /* Copying needs JavaScript: the view module reveals the button. */ ?>
				<button type="button" class="newspack-live-share__copy" data-action="copy-link" data-url="<?php echo esc_url( $url ); ?>" hidden>
					<span class="newspack-live-share__copy-label"><?php esc_html_e( 'Copy link', 'newspack-live' ); ?></span>
					<span class="newspack-live-share__copy-done" aria-hidden="true"><span class="newspack-live-share__check"></span><span class="newspack-live-share__copy-done-text"><?php esc_html_e( 'Link copied', 'newspack-live' ); ?></span></span>
				</button>
				<?php foreach ( $links as $network => $name ) : ?>
					<a class="newspack-live-share__link" data-network="<?php echo esc_attr( $network ); ?>" href="<?php echo esc_url( self::share_url( $network, $url, $title ) ); ?>" target="_blank" rel="nofollow noopener noreferrer"><?php echo esc_html( $name ); ?></a>
				<?php endforeach; ?>
				<span class="newspack-live-share__status newspack-live__sr-only" role="status"></span>
			</div>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Share URL of a network.
	 *
	 * @param string $network Network key.
	 * @param string $url     URL.
	 * @param string $title   Title.
	 * @return string
	 */
	public static function share_url( string $network, string $url, string $title ): string {
		$text = '' !== $title ? $title . ' ' . $url : $url;
		switch ( $network ) {
			case 'x':
				return add_query_arg(
					array_filter(
						[
							'url'  => rawurlencode( $url ),
							'text' => '' !== $title ? rawurlencode( $title ) : '',
						] 
					),
					'https://x.com/intent/post' 
				);
			case 'facebook':
				return add_query_arg( 'u', rawurlencode( $url ), 'https://www.facebook.com/sharer/sharer.php' );
			case 'linkedin':
				return add_query_arg( 'url', rawurlencode( $url ), 'https://www.linkedin.com/sharing/share-offsite/' );
			case 'whatsapp':
				return add_query_arg( 'text', rawurlencode( $text ), 'https://wa.me/' );
			case 'bluesky':
				return add_query_arg( 'text', rawurlencode( $text ), 'https://bsky.app/intent/compose' );
		}
		return $url;
	}

	/**
	 * Featured image with caption and credit.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_header_media( int $live_id ): string {
		$attachment_id = (int) get_post_thumbnail_id( $live_id );
		if ( ! $attachment_id ) {
			return '';
		}
		$image = wp_get_attachment_image(
			$attachment_id,
			'large',
			false,
			[
				'class'         => 'newspack-live__media-image',
				'loading'       => false,
				'fetchpriority' => 'high',
				'sizes'         => '(min-width: 1024px) 66vw, 100vw',
			]
		);
		if ( '' === $image ) {
			return '';
		}
		$caption = trim( (string) wp_get_attachment_caption( $attachment_id ) );
		$credit  = self::get_credit( $attachment_id );
		ob_start();
		?>
		<figure class="newspack-live__media">
			<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wp_get_attachment_image(). ?>
			<?php if ( '' !== $caption || '' !== $credit ) : ?>
				<figcaption class="newspack-live__media-caption">
					<?php echo wp_kses_post( $caption ); ?>
					<?php if ( '' !== $credit ) : ?>
						<span class="newspack-live__media-credit"><?php echo esc_html( $credit ); ?></span>
					<?php endif; ?>
				</figcaption>
			<?php endif; ?>
		</figure>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Image credit "Credit / Org" from the Newspack media credit meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Plain text.
	 */
	public static function get_credit( int $attachment_id ): string {
		$credit = trim( (string) get_post_meta( $attachment_id, '_media_credit', true ) );
		$org    = trim( (string) get_post_meta( $attachment_id, '_navis_media_credit_org', true ) );
		if ( '' !== $credit && '' !== $org ) {
			return $credit . ' / ' . $org;
		}
		return '' !== $credit ? $credit : $org;
	}

	/**
	 * Aside: related coverage, then what hooks into `newspack_live_aside_end` (the aside ad). Nothing
	 * at all when both are empty: the main column keeps its width (no empty column).
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_aside( int $live_id ): string {
		ob_start();
		echo self::render_related( $live_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_related().

		/**
		 * Fires at the end of the live aside (the aside ad placement hooks here).
		 *
		 * @since 1.2.0
		 *
		 * @param int $live_id Live ID.
		 */
		do_action( 'newspack_live_aside_end', $live_id );
		$content = trim( (string) ob_get_clean() );
		return '' === $content ? '' : '<div class="newspack-live__aside">' . $content . '</div>';
	}

	/**
	 * Entry permalink: `{live}?live-entry={id}#live-entry-{id}`.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $entry_id Entry ID.
	 * @return string
	 */
	public static function entry_permalink( int $live_id, int $entry_id ): string {
		return add_query_arg( 'live-entry', $entry_id, (string) get_permalink( $live_id ) ) . '#live-entry-' . $entry_id;
	}

	/**
	 * Displayed time of an entry.
	 *
	 * @param WP_Post $entry Entry.
	 * @return int UTC timestamp.
	 */
	public static function entry_time( WP_Post $entry ): int {
		return (int) strtotime( $entry->post_date_gmt . ' UTC' );
	}

	/**
	 * Ask-a-question form (behaviour in the view module).
	 *
	 * @param int    $live_id  Live ID.
	 * @param string $state    State.
	 * @param array  $settings Live settings.
	 * @return string
	 */
	public static function render_ask_form( int $live_id, string $state, array $settings ): string {
		$mode = $settings['questions_mode'];
		if ( 'off' === $mode ) {
			return '';
		}
		$open = in_array( $state, [ Live::STATE_UPCOMING, Live::STATE_LIVE ], true ) && $settings['questions_open'];
		if ( ! $open ) {
			return '<section class="newspack-live__ask newspack-live__ask--closed" id="newspack-live-ask"><p class="newspack-live__closed">' . esc_html__( 'Questions are closed.', 'newspack-live' ) . '</p></section>';
		}

		$title      = '' !== $settings['question_prompt'] ? $settings['question_prompt'] : __( 'Ask the newsroom a question', 'newspack-live' );
		$user       = wp_get_current_user();
		$needs_auth = 'registered' === $mode && ! $user->exists();
		$privacy    = (string) get_privacy_policy_url();
		$action     = rest_url( 'newspack-live/v1/lives/' . $live_id . '/questions' );
		ob_start();
		?>
		<section class="newspack-live__ask" id="newspack-live-ask" aria-labelledby="newspack-live-ask-title" data-mode="<?php echo esc_attr( $mode ); ?>">
			<?php echo self::collapsible_title( 'newspack-live__section-title', 'newspack-live-ask-title', 'newspack-live-ask-panel', $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in collapsible_title(). ?>
			<div class="newspack-live__panel" id="newspack-live-ask-panel">
				<form class="newspack-live-ask" method="post" action="<?php echo esc_url( $action ); ?>">
					<p class="newspack-live-ask__field">
						<label for="newspack-live-question"><?php esc_html_e( 'Your question', 'newspack-live' ); ?></label>
						<textarea id="newspack-live-question" name="question" rows="3" maxlength="<?php echo esc_attr( (string) Questions::MAX_LENGTH ); ?>" required aria-describedby="newspack-live-question-counter newspack-live-ask-error"></textarea>
						<span class="newspack-live-ask__counter" id="newspack-live-question-counter">
							<?php
							/* translators: 1: characters typed, 2: maximum. */
							echo esc_html( sprintf( __( '%1$d/%2$d', 'newspack-live' ), 0, Questions::MAX_LENGTH ) );
							?>
						</span>
					</p>
					<p class="newspack-live-ask__field">
						<label for="newspack-live-name"><?php esc_html_e( 'Name to display', 'newspack-live' ); ?></label>
						<input type="text" id="newspack-live-name" name="display_name" maxlength="<?php echo esc_attr( (string) Questions::MAX_NAME_LENGTH ); ?>" required autocomplete="nickname" aria-describedby="newspack-live-ask-error" value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>" />
					</p>
					<p class="newspack-live-ask__hp" aria-hidden="true">
						<label for="newspack-live-website"><?php esc_html_e( 'Website', 'newspack-live' ); ?></label>
						<input type="text" id="newspack-live-website" name="website" tabindex="-1" autocomplete="off" value="" />
					</p>
					<input type="hidden" name="ts" value="<?php echo esc_attr( Questions::sign_timestamp( $live_id, time() ) ); ?>" />
					<p class="newspack-live-ask__privacy">
						<?php esc_html_e( 'Your display name will be published if we answer your question.', 'newspack-live' ); ?>
						<?php if ( '' !== $privacy ) : ?>
							<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Privacy policy', 'newspack-live' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="newspack-live-ask__error" id="newspack-live-ask-error" role="alert"></p>
					<p class="newspack-live-ask__actions">
						<?php if ( $needs_auth ) : ?>
							<a class="newspack-live-ask__signin" data-action="signin" data-reader-activation="<?php echo esc_attr( Integrations\Reader_Activation::is_enabled() ? '1' : '0' ); ?>" href="<?php echo esc_url( wp_login_url( get_permalink( $live_id ) . '#newspack-live-ask' ) ); ?>"><?php esc_html_e( 'Sign in to ask a question', 'newspack-live' ); ?></a>
						<?php else : ?>
							<button type="submit" class="newspack-live-ask__submit"><?php esc_html_e( 'Send question', 'newspack-live' ); ?></button>
						<?php endif; ?>
					</p>
					<p class="newspack-live-ask__success" role="status"></p>
				</form>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Feed section: live region, new-updates button, pinned container, entries, pagination.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $state   State.
	 * @param array  $view    View from get_view().
	 * @return string
	 */
	public static function render_feed( int $live_id, string $state, array $view ): string {
		$pinned_id = 0;
		$has_newer = false;
		if ( 'entry' === $view['type'] ) {
			$window = Feed::get_window( $live_id, $view['cursor'] );
			if ( null === $window ) {
				// Unknown, pinned or unpublished target: fall back to the latest entries.
				$view   = [
					'type'   => 'entry',
					'cursor' => 0,
				];
				$page   = Feed::get_page( $live_id );
				$window = [
					'ids'       => $page['ids'],
					'has_more'  => $page['has_more'],
					'has_newer' => false,
				];
				$pinned_id = Feed::get_pinned_id( $live_id );
			}
			$ids       = $window['ids'];
			$has_more  = $window['has_more'];
			$has_newer = $window['has_newer'];
		} else {
			$page     = Feed::get_page( $live_id, 'desc', $view['cursor'] );
			$ids      = $page['ids'];
			$has_more = $page['has_more'];
			if ( 'latest' === $view['type'] ) {
				$pinned_id = Feed::get_pinned_id( $live_id );
			}
		}
		$is_latest = 'latest' === $view['type'];
		$live_url  = (string) get_permalink( $live_id );
		$show_auth = (bool) Live::get_settings( $live_id )['show_entry_author'];
		self::prime_entries( array_filter( array_merge( $ids, [ $pinned_id ] ) ) );

		ob_start();
		?>
		<section class="newspack-live__feed" id="newspack-live-feed" aria-labelledby="newspack-live-feed-title">
			<h2 class="newspack-live__sr-only" id="newspack-live-feed-title"><?php esc_html_e( 'Live updates', 'newspack-live' ); ?></h2>
			<div class="newspack-live__announcer newspack-live__sr-only" role="status" aria-live="polite"></div>
			<p class="newspack-live__connection" role="status" data-wp-interactive="newspack-live" data-wp-text="state.connectionText"></p>
			<?php if ( ! $is_latest ) : ?>
				<p class="newspack-live__back"><a href="<?php echo esc_url( $live_url ); ?>"><?php esc_html_e( 'Back to latest', 'newspack-live' ); ?></a></p>
			<?php endif; ?>
			<div class="newspack-live__new-updates-bar">
				<?php if ( $is_latest ) : ?>
					<button type="button" class="newspack-live__new-updates" data-wp-interactive="newspack-live" data-wp-bind--hidden="!state.hasPending" data-wp-on--click="actions.showPending" hidden><span class="newspack-live__new-updates-arrow" aria-hidden="true">↑</span> <span data-wp-text="state.newUpdatesLabel"></span></button>
				<?php else : ?>
					<a class="newspack-live__new-updates newspack-live__new-updates--cursor" href="<?php echo esc_url( $live_url ); ?>" hidden><?php esc_html_e( 'New updates — back to latest', 'newspack-live' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="newspack-live__pinned"<?php echo $pinned_id ? '' : ' hidden'; ?>>
				<?php if ( $pinned_id ) : ?>
					<?php echo self::render_entry( $pinned_id, [ 'show_author' => $show_auth ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_entry(). ?>
				<?php endif; ?>
			</div>
			<div class="newspack-live__entries" data-has-newer="<?php echo $has_newer ? '1' : '0'; ?>">
				<?php
				// Editorial entries only.
				foreach ( $ids as $entry_id ) {
					echo self::render_entry( $entry_id, [ 'show_author' => $show_auth ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_entry().
				}
				?>
			</div>
			<?php if ( ! $ids && ! $pinned_id ) : ?>
				<p class="newspack-live__empty"><?php echo esc_html( self::empty_message( $live_id, $state ) ); ?></p>
			<?php endif; ?>
			<div class="newspack-live__more">
				<?php if ( $has_more && $ids ) : ?>
					<a class="newspack-live__load-older" data-action="load-older" href="<?php echo esc_url( self::cursor_url( $live_id, (int) end( $ids ) ) ); ?>"><?php esc_html_e( 'Load older updates', 'newspack-live' ); ?></a>
				<?php elseif ( $ids ) : ?>
					<p class="newspack-live__start"><?php esc_html_e( "You've reached the start of this live.", 'newspack-live' ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Primes post, meta, term and author caches of entries and their answered questions.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return void
	 */
	public static function prime_entries( array $ids ): void {
		if ( ! $ids ) {
			return;
		}
		_prime_post_caches( $ids, true, true );
		$authors   = [];
		$questions = [];
		foreach ( $ids as $id ) {
			$authors[] = (int) get_post_field( 'post_author', $id );
			$questions = array_merge( $questions, Entries::get_question_ids( (int) $id ) );
		}
		cache_users( array_unique( array_filter( $authors ) ) );
		if ( $questions ) {
			_prime_post_caches( array_unique( $questions ), false, true );
		}
	}

	/**
	 * URL continuing the feed after an entry (no-JS fallback).
	 *
	 * @param int $live_id  Live ID.
	 * @param int $entry_id Last displayed entry.
	 * @return string
	 */
	public static function cursor_url( int $live_id, int $entry_id ): string {
		return add_query_arg( 'live-before', $entry_id, (string) get_permalink( $live_id ) );
	}

	/**
	 * Empty-state copy.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $state   State.
	 * @return string
	 */
	public static function empty_message( int $live_id, string $state ): string {
		if ( Live::STATE_UPCOMING === $state ) {
			$start = Live::get_start( $live_id );
			/* translators: 1: date, 2: time. */
			return sprintf( __( 'Coverage starts %1$s at %2$s. Check back then.', 'newspack-live' ), wp_date( Dates::date_format(), $start ), Dates::time( $start ) );
		}
		return __( 'First updates coming soon.', 'newspack-live' );
	}

	/**
	 * Renders one entry frame, cached per entry revision.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $options  `show_author` (bool).
	 * @return string
	 */
	public static function render_entry( int $entry_id, array $options = [] ): string {
		$entry = get_post( $entry_id );
		if ( ! Entries::is_entry( $entry ) ) {
			return '';
		}
		$show_author = ! empty( $options['show_author'] );
		// The time label depends on the current day, the author line on the live setting, the bylines
		// and Q&A bubbles on people's names.
		$key = sprintf(
			'entry_v%d_%d_%d_%s_%d_%s',
			self::ENTRY_MARKUP_VERSION,
			$entry_id,
			Entries::get_rev( $entry_id ),
			Dates::day( time() ),
			$show_author ? 1 : 0,
			wp_cache_get_last_changed( Post_Types::PEOPLE_CACHE )
		);
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['html'], $cached['assets'] ) ) {
			// Re-enqueue what the entry needed, so pages and REST responses list the same assets.
			Assets::enqueue( $cached['assets'] );
			return (string) $cached['html'];
		}
		$before = Assets::snapshot();
		$html   = self::build_entry( $entry, $show_author );
		// The cache is only filled once `wp_enqueue_scripts` has run (page renders after `wp_head`, REST
		// routes after Assets::register_front_assets()): before it (block themes render the template
		// before `wp_head`, other REST routes), a handle enqueued before its registration isn't in the
		// queue, so the recorded list would lack it. Hits are always used: re-enqueuing a handle that
		// isn't registered yet queues it until it is, like a fresh render.
		if ( ! Assets::can_cache_recordings() ) {
			return $html;
		}
		wp_cache_set(
			$key,
			[
				'html'   => $html,
				'assets' => Assets::diff( $before ),
			],
			self::CACHE_GROUP,
			HOUR_IN_SECONDS
		);
		return $html;
	}

	/**
	 * Builds the entry markup.
	 *
	 * @param WP_Post $entry       Entry.
	 * @param bool    $show_author Whether to show the author line (always shown on Q&A).
	 * @return string
	 */
	private static function build_entry( WP_Post $entry, bool $show_author ): string {
		$live_id      = (int) $entry->post_parent;
		$time         = self::entry_time( $entry );
		$pinned       = Entries::is_pinned( $entry->ID );
		$question_ids = Entries::get_question_ids( $entry->ID );
		$is_qa        = [] !== $question_ids;
		$label        = Labels::resolve( $entry->ID );
		$permalink    = self::entry_permalink( $live_id, $entry->ID );
		$share_title  = '' !== $entry->post_title ? $entry->post_title : Content::plain_text( $entry->post_content, 80 );

		$classes = [ 'newspack-live-entry' ];
		if ( $is_qa ) {
			$classes[] = 'newspack-live-entry--qa';
		}
		if ( $pinned ) {
			$classes[] = 'newspack-live-entry--pinned';
		}
		if ( $label && Labels::KEY_POINT === $label['slug'] ) {
			$classes[] = 'newspack-live-entry--key-point';
		}

		$published_at = (int) get_post_meta( $entry->ID, Entries::META_PUBLISHED_AT, true );
		$modified     = (int) strtotime( $entry->post_modified_gmt . ' UTC' );
		$show_updated = $published_at > 0 && $modified - $published_at > 5 * MINUTE_IN_SECONDS;
		$author       = get_userdata( (int) $entry->post_author );

		ob_start();
		?>
		<article class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" id="live-entry-<?php echo esc_attr( (string) $entry->ID ); ?>" data-entry-id="<?php echo esc_attr( (string) $entry->ID ); ?>" data-rev="<?php echo esc_attr( (string) Entries::get_rev( $entry->ID ) ); ?>" data-time="<?php echo esc_attr( (string) $time ); ?>">
			<div class="newspack-live-entry__card">
			<header class="newspack-live-entry__meta">
				<?php
				// The card's top row: the time, its label, "Pinned", the share icon at the end edge.
				echo Dates::time_element( $time, 'newspack-live-entry__time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Dates::time_element().
				echo self::render_label( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_label().
				?>
				<?php if ( $pinned ) : ?>
					<span class="newspack-live-entry__pinned"><?php esc_html_e( 'Pinned', 'newspack-live' ); ?></span>
				<?php endif; ?>
				<?php echo self::render_share( $permalink, $share_title, 'entry' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_share(). ?>
			</header>
			<?php if ( '' !== $entry->post_title ) : ?>
				<h3 class="newspack-live-entry__title"><?php echo esc_html( $entry->post_title ); ?></h3>
			<?php endif; ?>
			<?php
			if ( $is_qa ) {
				echo self::render_questions( $question_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_questions().
			}
			?>
			<div class="newspack-live-entry__content"><?php echo Content::kses( Content::render( $entry->post_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Content::kses() (post allowlist + oEmbed elements). ?></div>
			<?php if ( $author && ( $show_author || $is_qa ) ) : ?>
				<p class="newspack-live-entry__author"><?php echo esc_html( $author->display_name ); ?></p>
			<?php endif; ?>
			<?php if ( $show_updated ) : ?>
				<p class="newspack-live-entry__updated">
					<?php
					/* translators: %s: time. */
					printf( esc_html__( 'Updated %s', 'newspack-live' ), Dates::time_element( $modified, '', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Dates::time_element().
					?>
				</p>
			<?php endif; ?>
			</div>
		</article>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Entry label: real text read after the time ("10:48 am, Breaking"), uppercase through CSS.
	 *
	 * @param array|null $label Label from Labels::resolve().
	 * @return string
	 */
	public static function render_label( ?array $label ): string {
		if ( ! $label ) {
			return '';
		}
		$classes = [ 'newspack-live-entry__label', 'newspack-live-entry__label--' . sanitize_html_class( $label['slug'] ) ];
		if ( $label['accent'] ) {
			$classes[] = 'newspack-live-entry__label--accent';
		}
		return sprintf(
			'<span class="%1$s"><span class="newspack-live__sr-only">%2$s</span>%3$s</span>',
			esc_attr( implode( ' ', $classes ) ),
			esc_html_x( ', ', 'separator between an entry time and its label', 'newspack-live' ),
			esc_html( $label['text'] )
		);
	}

	/**
	 * Q&A bubbles, in selection order.
	 *
	 * @param int[] $question_ids Question IDs.
	 * @return string
	 */
	public static function render_questions( array $question_ids ): string {
		$items = '';
		foreach ( $question_ids as $question_id ) {
			$question = get_post( $question_id );
			if ( ! Questions::is_question( $question ) ) {
				continue;
			}
			$name   = Questions::get_display_name( $question_id );
			$items .= '<div class="newspack-live-qa__item">'
				. '<p class="newspack-live-qa__question">'
				/* translators: %s: display name. */
				. '<span class="newspack-live__sr-only">' . esc_html( sprintf( __( 'Question from %s:', 'newspack-live' ), $name ) ) . ' </span>'
				// Plain text: URLs are not linked.
				. nl2br( esc_html( $question->post_content ) )
				. '</p>'
				. '<p class="newspack-live-qa__name" aria-hidden="true">' . esc_html( $name ) . '</p>'
				. '</div>';
		}
		return '' !== $items ? '<div class="newspack-live-qa">' . $items . '</div>' : '';
	}

	/**
	 * Related coverage box.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_related( int $live_id ): string {
		$related = Related::get( $live_id );
		if ( ! $related ) {
			return '';
		}
		_prime_post_caches( $related, false, true );
		$thumbnails = array_filter( array_map( 'get_post_thumbnail_id', $related ) );
		if ( $thumbnails ) {
			_prime_post_caches( array_map( 'intval', $thumbnails ), false, true );
		}
		ob_start();
		?>
		<section class="newspack-live__related" aria-labelledby="newspack-live-related-title">
			<h2 class="newspack-live__section-title" id="newspack-live-related-title"><?php esc_html_e( 'Related coverage', 'newspack-live' ); ?></h2>
			<ul class="newspack-live__related-list">
				<?php foreach ( $related as $post_id ) : ?>
					<li class="newspack-live__related-item">
						<a class="newspack-live__related-link" href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>">
							<?php
							echo get_the_post_thumbnail(
								$post_id,
								'thumbnail',
								[
									'class' => 'newspack-live__related-image',
									'alt'   => '',
								] 
							);
							?>
							<span class="newspack-live__related-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "{n} min read" (words ÷ 250, at least 1).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function reading_time_label( int $post_id ): string {
		$words   = str_word_count( Content::plain_text( (string) get_post_field( 'post_content', $post_id ) ) );
		$minutes = max( 1, (int) round( $words / 250 ) );
		/* translators: %d: minutes. */
		return sprintf( _n( '%d min read', '%d min read', $minutes, 'newspack-live' ), $minutes );
	}

	/**
	 * Description: the live's post content, under the header like an article intro, through the
	 * plugin's pipeline (never `the_content`). Nothing when empty. It changes on page load only (no
	 * revision bump).
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_description( int $live_id ): string {
		$content = (string) get_post_field( 'post_content', $live_id );
		if ( '' === trim( wp_strip_all_tags( $content ) ) && ! Entries::has_body( $content ) ) {
			return '';
		}
		return '<div class="newspack-live__description">' . Content::kses( Content::render( $content ) ) . '</div>';
	}

	/**
	 * Title of a collapsible section (Context card, ask form): an `h2` holding a disclosure button
	 * with a chevron at the end edge, expanded by default. The view module toggles the panel;
	 * without JavaScript the panel stays open.
	 *
	 * @param string $class    Heading class.
	 * @param string $id       Heading ID.
	 * @param string $panel_id ID of the panel the button controls.
	 * @param string $title    Title (plain text).
	 * @return string
	 */
	public static function collapsible_title( string $class, string $id, string $panel_id, string $title ): string {
		return sprintf(
			'<h2 class="%1$s" id="%2$s"><button type="button" class="newspack-live__expander" data-action="toggle" aria-expanded="true" aria-controls="%3$s"><span class="newspack-live__expander-label">%4$s</span><svg class="newspack-live__chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M6.5 9.5 12 15l5.5-5.5"/></svg></button></h2>',
			esc_attr( $class ),
			esc_attr( $id ),
			esc_attr( $panel_id ),
			esc_html( $title )
		);
	}

	/**
	 * Context card: the live's Context field, above the ask form. A hidden empty section
	 * stands in when it is empty, so a delta can fill it in place.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function render_context( int $live_id ): string {
		$context = Context::render( $live_id );
		if ( '' === $context ) {
			return '<section class="newspack-live__context" id="newspack-live-context" hidden></section>';
		}
		ob_start();
		?>
		<section class="newspack-live__context" id="newspack-live-context" aria-labelledby="newspack-live-context-title">
			<?php echo self::collapsible_title( 'newspack-live__context-title', 'newspack-live-context-title', 'newspack-live-context-panel', __( 'Context', 'newspack-live' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in collapsible_title(). ?>
			<div class="newspack-live__context-content" id="newspack-live-context-panel"><?php echo wp_kses( $context, Context::ALLOWED_HTML ); ?></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Article card (block `newspack-live/article-card`).
	 *
	 * @param int $post_id Linked post ID.
	 * @return string Empty when the post isn't published.
	 */
	public static function render_article_card( int $post_id ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || post_password_required( $post ) ) {
			return '';
		}
		$url      = (string) get_permalink( $post );
		$category = Related::get_primary_category( $post->ID );
		$authors  = [];
		foreach ( Integrations\Co_Authors_Plus::get_hosts( $post->ID ) as $host ) {
			$authors[] = esc_html( $host['name'] );
		}
		$excerpt = wp_trim_words( '' !== $post->post_excerpt ? $post->post_excerpt : Content::plain_text( $post->post_content ), 25 );
		ob_start();
		?>
		<div class="newspack-live-card">
			<?php if ( has_post_thumbnail( $post ) ) : ?>
				<a class="newspack-live-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo get_the_post_thumbnail( $post, 'medium_large', [ 'alt' => '' ] ); ?>
				</a>
			<?php endif; ?>
			<div class="newspack-live-card__body">
				<?php if ( $category ) : ?>
					<p class="newspack-live-card__category"><?php echo esc_html( $category->name ); ?></p>
				<?php endif; ?>
				<h4 class="newspack-live-card__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></h4>
				<?php if ( $authors ) : ?>
					<p class="newspack-live-card__authors"><?php echo self::join_names( $authors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- names escaped above. ?></p>
				<?php endif; ?>
				<?php if ( '' !== $excerpt ) : ?>
					<p class="newspack-live-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>
				<p class="newspack-live-card__footer">
					<span class="newspack-live-card__read-time"><?php echo esc_html( self::reading_time_label( $post->ID ) ); ?></span>
					<a class="newspack-live-card__cta" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Read the story', 'newspack-live' ); ?><span class="newspack-live__sr-only">: <?php echo esc_html( get_the_title( $post ) ); ?></span></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
