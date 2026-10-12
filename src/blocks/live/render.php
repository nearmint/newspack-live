<?php
/**
 * Server render of the `newspack-live/live` block.
 *
 * @package Newspack_Live
 *
 * @var array     $attributes Block attributes.
 * @var WP_Block  $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$live_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();

if ( \Newspack_Live\Live::is_live( $live_id ) ) {
	echo \Newspack_Live\Renderer::render_live( $live_id, [ 'wrapper_attributes' => get_block_wrapper_attributes() ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes its output.
}
