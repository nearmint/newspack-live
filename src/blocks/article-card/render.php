<?php
/**
 * Server render of the `newspack-live/article-card` block.
 *
 * @package Newspack_Live
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$card = \Newspack_Live\Renderer::render_article_card( isset( $attributes['postId'] ) ? (int) $attributes['postId'] : 0 );
if ( '' !== $card ) {
	echo '<div ' . get_block_wrapper_attributes() . '>' . $card . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes() and the renderer.
}
