<?php
/**
 * Date and time labels.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Labels in the site timezone with the site's date and time formats.
 */
final class Dates {

	/**
	 * Site time format.
	 *
	 * @return string
	 */
	public static function time_format(): string {
		$format = (string) get_option( 'time_format' );
		return '' !== $format ? $format : 'g:i a';
	}

	/**
	 * Site date format.
	 *
	 * @return string
	 */
	public static function date_format(): string {
		$format = (string) get_option( 'date_format' );
		return '' !== $format ? $format : 'F j, Y';
	}

	/**
	 * Calendar day (site timezone) of a timestamp, as Y-m-d.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function day( int $timestamp ): string {
		return (string) wp_date( 'Y-m-d', $timestamp );
	}

	/**
	 * Whether a timestamp falls on today (site timezone).
	 *
	 * @param int      $timestamp UTC timestamp.
	 * @param int|null $now       Current timestamp.
	 * @return bool
	 */
	public static function is_today( int $timestamp, ?int $now = null ): bool {
		return self::day( $timestamp ) === self::day( null === $now ? time() : $now );
	}

	/**
	 * Time only.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function time( int $timestamp ): string {
		return (string) wp_date( self::time_format(), $timestamp );
	}

	/**
	 * Site date (`Y-m-d`) of the day before a timestamp's day, in the site timezone.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function previous_day( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' );
	}

	/**
	 * Short date: "Sep 27", or "Sep 27, 2025" outside the current year.
	 *
	 * @param int  $timestamp UTC timestamp.
	 * @param bool $year      Whether to include the year.
	 * @return string
	 */
	public static function short_date( int $timestamp, bool $year = false ): string {
		return (string) ( $year
			? wp_date( _x( 'M j, Y', 'short date format with year', 'newspack-live' ), $timestamp )
			: wp_date( _x( 'M j', 'short date format', 'newspack-live' ), $timestamp ) );
	}

	/**
	 * Entry time label, in the site timezone and time format: "10:48 PM" today, "Yesterday,
	 * 10:48 PM", "Sep 27, 10:48 PM" earlier, "Sep 27, 2025, 10:48 PM" in another year.
	 *
	 * @param int      $timestamp UTC timestamp.
	 * @param int|null $now       Current UTC timestamp.
	 * @param bool     $mid       Whether the label goes mid-sentence ("Updated yesterday, 10:48 PM").
	 * @return string
	 */
	public static function entry_label( int $timestamp, ?int $now = null, bool $mid = false ): string {
		$now  = null === $now ? time() : $now;
		$time = self::time( $timestamp );
		$day  = self::day( $timestamp );
		if ( self::day( $now ) === $day ) {
			return $time;
		}
		if ( self::previous_day( $now ) === $day ) {
			return sprintf(
				$mid
					/* translators: %s: time (e.g. "10:48 PM"). Mid-sentence, after "Updated". */
					? _x( 'yesterday, %s', 'mid-sentence', 'newspack-live' )
					/* translators: %s: time (e.g. "10:48 PM"). Start of the entry time label. */
					: __( 'Yesterday, %s', 'newspack-live' ),
				$time
			);
		}
		$other_year = wp_date( 'Y', $timestamp ) !== wp_date( 'Y', $now );
		/* translators: 1: short date (e.g. "Sep 27" or "Sep 27, 2025"), 2: time. */
		return sprintf( _x( '%1$s, %2$s', 'entry date and time', 'newspack-live' ), self::short_date( $timestamp, $other_year ), $time );
	}

	/**
	 * Entry `<time>` element: the visible label (corrected by the view module when the day changes
	 * while the page is open) and the full date for assistive technology.
	 *
	 * @param int    $timestamp UTC timestamp.
	 * @param string $class     CSS class.
	 * @param bool   $mid       Whether the label goes mid-sentence (lowercase "yesterday").
	 * @return string
	 */
	public static function time_element( int $timestamp, string $class = '', bool $mid = false ): string {
		/* translators: 1: date, 2: time. */
		$full = sprintf( _x( '%1$s at %2$s', 'date at time', 'newspack-live' ), wp_date( self::date_format(), $timestamp ), self::time( $timestamp ) );
		return sprintf(
			'<time%1$s datetime="%2$s" data-day="%3$s" data-clock="%4$s" data-date="%5$s" data-date-year="%6$s"%9$s><span class="newspack-live-time" aria-hidden="true">%7$s</span><span class="newspack-live__sr-only">%8$s</span></time>',
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
			esc_attr( self::iso( $timestamp ) ),
			esc_attr( self::day( $timestamp ) ),
			esc_attr( self::time( $timestamp ) ),
			esc_attr( self::short_date( $timestamp ) ),
			esc_attr( self::short_date( $timestamp, true ) ),
			esc_html( self::entry_label( $timestamp, null, $mid ) ),
			esc_html( $full ),
			$mid ? ' data-mid="1"' : ''
		);
	}

	/**
	 * Header "Updated" label, in the site timezone and time format: "Updated 8:57 am" today,
	 * "Updated yesterday at 8:57 am", "Updated Sep 27 at 8:57 am" earlier, with the year when it
	 * differs ("Updated Sep 27, 2025 at 8:57 am").
	 *
	 * @param int      $timestamp UTC timestamp.
	 * @param int|null $now       Current timestamp.
	 * @return string
	 */
	public static function updated_label( int $timestamp, ?int $now = null ): string {
		$now  = null === $now ? time() : $now;
		$time = self::time( $timestamp );
		$day  = self::day( $timestamp );
		if ( self::day( $now ) === $day ) {
			/* translators: %s: time (e.g. "8:57 am"). Header line of a live updated today. */
			return sprintf( __( 'Updated %s', 'newspack-live' ), $time );
		}
		if ( self::previous_day( $now ) === $day ) {
			/* translators: %s: time (e.g. "8:57 am"). */
			return sprintf( __( 'Updated yesterday at %s', 'newspack-live' ), $time );
		}
		$other_year = wp_date( 'Y', $timestamp ) !== wp_date( 'Y', $now );
		/* translators: 1: short date (e.g. "Sep 27" or "Sep 27, 2025"), 2: time. */
		return sprintf( __( 'Updated %1$s at %2$s', 'newspack-live' ), self::short_date( $timestamp, $other_year ), $time );
	}

	/**
	 * The parts of a time the view module needs to recompute its label.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return array{iso: string, day: string, clock: string, date: string, date_year: string}
	 */
	public static function parts( int $timestamp ): array {
		return [
			'iso'       => self::iso( $timestamp ),
			'day'       => self::day( $timestamp ),
			'clock'     => self::time( $timestamp ),
			'date'      => self::short_date( $timestamp ),
			'date_year' => self::short_date( $timestamp, true ),
		];
	}

	/**
	 * Header "Updated" `<time>`: the visible label (corrected by the view module after midnight) and
	 * the full date for assistive technology.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function updated_element( int $timestamp ): string {
		$parts = self::parts( $timestamp );
		return sprintf(
			'<time class="newspack-live__updated" datetime="%1$s" data-day="%2$s" data-clock="%3$s" data-date="%4$s" data-date-year="%5$s" data-format="updated"><span class="newspack-live-time" aria-hidden="true">%6$s</span><span class="newspack-live__sr-only">%7$s</span></time>',
			esc_attr( $parts['iso'] ),
			esc_attr( $parts['day'] ),
			esc_attr( $parts['clock'] ),
			esc_attr( $parts['date'] ),
			esc_attr( $parts['date_year'] ),
			esc_html( self::updated_label( $timestamp ) ),
			esc_html( self::updated_full( $timestamp ) )
		);
	}

	/**
	 * Header "Updated" line read by assistive technology: the full date.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function updated_full( int $timestamp ): string {
		/* translators: 1: date, 2: time. */
		return sprintf( __( 'Updated %1$s at %2$s', 'newspack-live' ), wp_date( self::date_format(), $timestamp ), self::time( $timestamp ) );
	}

	/**
	 * Upcoming badge start label: the time today, else date and time.
	 *
	 * @param int      $timestamp UTC timestamp.
	 * @param int|null $now       Current timestamp.
	 * @return string
	 */
	public static function start_label( int $timestamp, ?int $now = null ): string {
		if ( self::is_today( $timestamp, $now ) ) {
			return self::time( $timestamp );
		}
		/* translators: 1: date, 2: time. */
		return sprintf( _x( '%1$s at %2$s', 'date at time', 'newspack-live' ), wp_date( self::date_format(), $timestamp ), self::time( $timestamp ) );
	}

	/**
	 * ISO 8601 date for `<time datetime>`.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function iso( int $timestamp ): string {
		return gmdate( 'c', $timestamp );
	}
}
