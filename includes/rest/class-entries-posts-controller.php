<?php
/**
 * Core REST controller for the entry post type.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\REST;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `/wp/v2/newspack-live-entries`: requires `newspack_live_post_entries` for every method, including
 * reads, so entries never leak to anonymous users. Writes are scoped to the target live.
 */
final class Entries_Posts_Controller extends WP_REST_Posts_Controller {

	/**
	 * Whether the current user may use the route at all.
	 *
	 * @return true|WP_Error
	 */
	private function check_base_permission() {
		if ( current_user_can( 'newspack_live_post_entries' ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access live entries.', 'newspack-live' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Checks the parent live of a create or update request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $current Current parent (updates), 0 for creates.
	 * @return true|WP_Error
	 */
	private function check_parent( WP_REST_Request $request, int $current ) {
		$parent = isset( $request['parent'] ) ? (int) $request['parent'] : $current;
		if ( $current > 0 && $parent !== $current ) {
			return new WP_Error( 'newspack_live_invalid_parent', __( 'An entry cannot be moved to another live.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( ! Live::is_live( $parent ) ) {
			return new WP_Error( 'newspack_live_invalid_parent', __( 'An entry must belong to a live.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( ! current_user_can( 'newspack_live_post_to_live', $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to post to this live.', 'newspack-live' ), [ 'status' => rest_authorization_required_code() ] );
		}
		if ( 'publish' === $request['status'] && ! current_user_can( 'newspack_live_publish_to_live', $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to publish to this live.', 'newspack-live' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * Adds the `parent` field (the live), which core only exposes for hierarchical post types.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$schema = parent::get_item_schema();

		$schema['properties']['parent'] = [
			'description' => __( 'The ID of the live the entry belongs to.', 'newspack-live' ),
			'type'        => 'integer',
			'context'     => [ 'view', 'edit' ],
		];

		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Adds the live as the post parent of new entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \stdClass|WP_Error
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared = parent::prepare_item_for_database( $request );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( empty( $prepared->ID ) && isset( $request['parent'] ) ) {
			$prepared->post_parent = (int) $request['parent'];
		}
		// Entries are never scheduled: refuse future dates beyond the clock-skew tolerance.
		if ( ! empty( $prepared->post_date_gmt ) && '0000-00-00 00:00:00' !== $prepared->post_date_gmt ) {
			$time = (int) strtotime( $prepared->post_date_gmt . ' UTC' );
			if ( $time > time() + \Newspack_Live\Entries::FUTURE_TOLERANCE ) {
				return new WP_Error( 'newspack_live_validation_future', __( "The time can't be in the future.", 'newspack-live' ), [ 'status' => 400 ] );
			}
			if ( $time > time() ) {
				$prepared->post_date_gmt = gmdate( 'Y-m-d H:i:s' );
				$prepared->post_date     = get_date_from_gmt( $prepared->post_date_gmt );
			}
		}
		return $prepared;
	}

	/**
	 * Adds the live ID to the response.
	 *
	 * @param \WP_Post        $item    Entry.
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$response = parent::prepare_item_for_response( $item, $request );
		$data     = $response->get_data();
		if ( is_array( $data ) ) {
			$data['parent'] = (int) $item->post_parent;
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Permission check for listing entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$check = $this->check_base_permission();
		return true === $check ? parent::get_items_permissions_check( $request ) : $check;
	}

	/**
	 * Permission check for reading an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$check = $this->check_base_permission();
		return true === $check ? parent::get_item_permissions_check( $request ) : $check;
	}

	/**
	 * Permission check for creating an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$check = $this->check_base_permission();
		if ( true === $check ) {
			$check = $this->check_parent( $request, 0 );
		}
		return true === $check ? parent::create_item_permissions_check( $request ) : $check;
	}

	/**
	 * Permission check for updating an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$check = $this->check_base_permission();
		if ( true === $check ) {
			$post  = $this->get_post( $request['id'] );
			$check = is_wp_error( $post ) ? $post : $this->check_parent( $request, (int) $post->post_parent );
		}
		return true === $check ? parent::update_item_permissions_check( $request ) : $check;
	}

	/**
	 * Permission check for deleting an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$check = $this->check_base_permission();
		return true === $check ? parent::delete_item_permissions_check( $request ) : $check;
	}
}
