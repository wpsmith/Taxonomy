<?php
/**
 * Taxonomy Limit Number of Published Posts Class
 *
 * Limits the number of published posts for a specific taxonomy.
 *
 * You may copy, distribute and modify the software as long as you track
 * changes/dates in source files. Any modifications to or software including
 * (via compiler) GPL-licensed code must also be made available under the GPL
 * along with build & install instructions.
 *
 * PHP Version 7.2
 *
 * @package    WPS\WP
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2018-2019 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://wpsmith.net/
 * @since      0.0.1
 */

namespace WPS\WP\Taxonomies;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\LimitNumPostsForTerm' ) ) {
	/**
	 * Class LimitNumPostsForTerm
	 *
	 * @package WPS\WP
	 */
	class LimitNumPostsForTerm {

		/**
		 * Taxonomy Name.
		 *
		 * @var string
		 */
		private $taxonomy;

		/**
		 * Term object, name, or slug.
		 *
		 * @var string|\WP_Term
		 */
		private $term;

		/**
		 * Limit of terms.
		 *
		 * @var int
		 */
		private $limit;

		/**
		 * LimitNumPostsForTerm constructor.
		 *
		 * @param string          $taxonomy        Taxonomy Name.
		 * @param string|\WP_Term $term            Term.
		 * @param int             $limit           Limit of posts assigned.
		 */
		public function __construct( $taxonomy, $term, $limit ) {
			$this->limit    = absint( $limit );
			$this->term     = sanitize_text_field( $term );
			$this->taxonomy = sanitize_text_field( $taxonomy );

			add_action( 'save_post', array( $this, 'save_post' ), ~PHP_INT_MAX, 2 );
		}

		/**
		 * Gets the term.
		 *
		 * @return \WP_Error|\WP_Term
		 */
		protected function get_term() {
			if ( is_object( $this->term ) ) {
				return $this->term;
			}

			if ( ! is_string( $this->term ) ) {
				return new \WP_Error( 'invalid-term-name', __( 'Term name is invalid.', 'wps' ), $this );
			}

			$term = get_term_by( 'name', $this->term, $this->taxonomy );
			if ( false === $this->term ) {
				$term = get_term_by( 'slug', sanitize_title_with_dashes( $this->term ), $this->taxonomy );
			}

			$this->term = $term;
			if ( false === $term ) {
				return new \WP_Error( 'term-does-not-exist', __( 'Term does not exist.', 'wps' ), $this );
			}

			return $this->term;
		}

		/**
		 * Ensures that there is only one post for the specific taxonomy.
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 */
		public function save_post( $post_id, $post ) {
			$term = $this->get_term();
			if ( is_wp_error( $term ) || 'publish' !== get_post_status( $post_id ) ) {
				return;
			}

			// Make sure the post type is assigned to the taxonomy.
			$post_type = get_post_type( $post );
			if ( ! is_object_in_taxonomy( $post_type, $this->taxonomy ) || false === $this->term ) {
				return;
			}

			$objects = get_objects_in_term( $this->term->term_id, $this->taxonomy );
			if ( ! is_wp_error( $objects ) && count( $objects ) > $this->limit ) {
				$args  = array(
					'post__in'    => $objects,
					'post_type'   => $post_type,
					'post_status' => array( 'publish' ),
				);
				$query = new \WP_Query( $args );
				$posts = $query->get_posts();
				if ( count( $posts ) > $this->limit ) {
					$to_be_removed = array_pop( $posts );
					if ( $to_be_removed->ID !== $post_id ) {
						$this->remove_term( $to_be_removed->ID );
					}
				}
			}
		}

		/**
		 * Removes term from object and sets default term.
		 *
		 * @param int $post_id Post ID.
		 */
		protected function remove_term( $post_id ) {
			wp_remove_object_terms( $post_id, $this->term->term_id, $this->taxonomy );
			$default_term = absint( get_option( 'default_' . $this->taxonomy ) );
			if ( 0 !== $default_term ) {
				wp_set_object_terms( $post_id, $default_term, $this->taxonomy );
			}
		}
	}
}
