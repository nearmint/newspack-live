<?php
/**
 * Stubs of the Newspack Collections module (newspack-plugin) used by the plugin:
 * module check, collection post type, collection taxonomy (registered for `post`) and the
 * collection → term link. The module is active when `$GLOBALS['newspack_live_test_collections']`
 * is true.
 *
 * @package Newspack_Live
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- test stubs of several upstream classes.

namespace Newspack\Optional_Modules {

	/**
	 * Collections module.
	 */
	class Collections {

		/**
		 * Whether the module is active.
		 *
		 * @return bool
		 */
		public static function is_module_active() {
			return ! empty( $GLOBALS['newspack_live_test_collections'] );
		}
	}
}

namespace Newspack\Collections {

	/**
	 * Collection post type.
	 */
	class Post_Type {

		/**
		 * Post type.
		 *
		 * @return string
		 */
		public static function get_post_type() {
			return 'newspack_collection';
		}
	}

	/**
	 * Collection taxonomy (on posts).
	 */
	class Collection_Taxonomy {

		/**
		 * Taxonomy.
		 *
		 * @return string
		 */
		public static function get_taxonomy() {
			return 'newspack_collection_taxonomy';
		}
	}

	/**
	 * Collection ↔ term link.
	 */
	class Sync {

		/**
		 * Term linked to a collection (`_newspack_collection_term_id` meta, as upstream).
		 *
		 * @param int $collection_id Collection ID.
		 * @return int|null
		 */
		public static function get_term_linked_to_collection( $collection_id ) {
			$term_id = get_post_meta( $collection_id, '_newspack_collection_term_id', true );
			return $term_id ? (int) $term_id : null;
		}
	}
}

namespace {

	add_action(
		'init',
		static function () {
			register_post_type( 'newspack_collection', [ 'public' => true ] );
			register_taxonomy( 'newspack_collection_taxonomy', [ 'post' ], [ 'public' => false ] );
		}
	);
}
