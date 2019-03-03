<?php
/**
 * Taxonomy Abstract Class
 *
 * Assists in the creation and management of Taxonomies.
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\WP
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2019 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @version    1.0.0
 * @since      0.1.0
 */

namespace WPS\WP\Taxonomies;

use WPS;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Taxonomy' ) ) {
	/**
	 * Taxonomy class.
	 *
	 * @package WPS\WP
	 */
	class Taxonomy extends WPS\WP\Registerable {

		/**
		 * Taxonomy registered name
		 *
		 * @var string
		 */
		protected $taxonomy;

		/**
		 * Registered Taxonomy Object.
		 *
		 * @var \WP_Taxonomy
		 */
		protected $taxonomy_object;

		/**
		 * Object type.
		 *
		 * @var mixed
		 */
		protected $object_type;

		/**
		 * Singular Taxonomy registered name
		 *
		 * @var string
		 */
		protected $singular;

		/**
		 * Plural Taxonomy registered name
		 *
		 * @var string
		 */
		protected $plural;

		/**
		 * Taxonomy label.
		 *
		 * @var string
		 */
		protected $label;

		/**
		 * Taxonomy description.
		 *
		 * @var string
		 */
		protected $description;

		/**
		 * Default term object.
		 *
		 * @var \WP_Term
		 */
		protected $default;

		/**
		 * Taxonomy Registration Defaults.
		 *
		 * @var array
		 */
		protected $defaults = array();

		/**
		 * Limited terms.
		 *
		 * @var LimitNumPostsForTerm[]
		 */
		protected $limits = array();

		/**
		 * Key-Value Array of slug => term names.
		 *
		 * @var array|string $args        {
		 *     Optional. Array or string of arguments for inserting a term.
		 *
		 * @type string      $alias_of    Slug of the term to make this term an alias of.
		 *                               Default empty string. Accepts a term slug.
		 * @type string      $description The term description. Default empty string.
		 * @type int         $parent      The id of the parent term. Default 0.
		 * @type string      $slug        The term slug to use. Default empty string.
		 * }
		 */
		protected $terms_to_be_added = array();

		/**
		 * Array of registered terms.
		 *
		 * @var \WP_Term[]
		 */
		protected $terms = array();

		/**
		 * What metaboxes to remove.
		 *
		 * Supports:
		 *  ''
		 *
		 * @var array
		 */
		protected $remove_metaboxes = array();

		/**
		 * Whether to remove the taxonomy's metabox from its post types.
		 *
		 * @var bool
		 */
		protected $no_metabox = false;

		/**
		 * Whether this should be a single taxonomy.
		 *
		 * @var bool
		 */
		protected $single = false;

		/**
		 * Taxonomy constructor.
		 *
		 * @param string       $name        Taxonomy name/slug.
		 * @param array|string $object_type Object types taxonomy will be assigned.
		 * @param array        $args        Additional args to set at initialization.
		 */
		public function __construct( $name, $object_type = array(), $args = array() ) {

			// Defaults.
			$this->taxonomy = sanitize_title_with_dashes( $name );
			$this->plural   = $this->taxonomy;
			$this->singular = $this->taxonomy;

			// Set object type (e.g., post type).
			$this->object_type = (array) $object_type;

			// Process Args.
			if ( ! empty( $args ) ) {
				$this->plural     = isset( $args['plural'] ) ? $args['plural'] : $this->plural;
				$this->singular   = isset( $args['singular'] ) ? $args['singular'] : $this->singular;
				$this->label      = isset( $args['label'] ) ? $args['label'] : $this->label;
				$this->rewrite    = isset( $args['rewrite'] ) ? $args['rewrite'] : $this->rewrite;
				$this->defaults   = isset( $args['defaults'] ) ? $args['defaults'] : $this->defaults;
				$this->no_metabox = isset( $args['no_metabox'] ) ? $args['no_metabox'] : $this->no_metabox;

				// Process Default.
				if ( isset( $args['default'] ) && $args['default'] ) {
					$this->process_default( $args['default'] );
				}

				// Add terms.
				if ( isset( $args['terms'] ) && ! empty( $args['terms'] ) ) {
					foreach ( $args['terms'] as $term ) {
						$this->add_term( $term );
					}
					$this->activate();
				}

				// Set single args.
				if ( isset( $args['single'] ) && $args['single'] ) {
					$this->single = is_array( $args['single'] ) ? $args['single'] : true;
				}
			}

			// Maybe do activate.
//			$this->maybe_do_activate( __FILE__ );

			// Maybe run init method.
			if ( method_exists( $this, 'init' ) ) {
				$this->init();
			}

			$this->add_hooks();

		}

		/**
		 * Maybe do activate.
		 */
		protected function maybe_do_activate( $file ) {
			// Prepopulate terms.
			register_activation_hook( $file, array( $this, 'activate' ) );
			$activation_hook = 'activate_' . plugin_basename( $file );
			if ( did_action( $activation_hook ) || doing_action( $activation_hook ) ) {
				$this->activate();
			}
		}

		/**
		 * Activation method.
		 *
		 * Flushes rewrite rules.
		 */
		public function activate() {

			if ( ! empty( $this->terms_to_be_added ) ) {
				$this->terms = self::populate_taxonomy( $this->taxonomy, $this->terms_to_be_added );
			}

		}

		/**
		 * Hooks into WordPress.
		 */
		public function add_hooks() {
			// Set default terms.
			add_action( 'save_post', array( $this, 'set_default_object_term' ), 100, 2 );

			// Create the create_taxonomy.
			$this->add_action( 'init', array( $this, 'register_taxonomy' ), 0 );

			// Initialize fields for ACF.
			$this->add_action( 'plugins_loaded', array( $this, 'initialize_fields' ) );

			// Remove Taxonomy Metabox.
			if ( $this->no_metabox ) {
				add_action( 'add_meta_boxes', array( $this, 'remove_taxonomy_metaboxes' ), 10 );
			}

			// Maybe run methods.
			// Maybe create ACF fields.
			foreach ( array( 'core_acf_fields', 'admin_menu', 'admin_init', 'plugins_loaded' ) as $hook_method ) {
				if ( method_exists( $this, $hook_method ) ) {
					$this->add_action( $hook_method, array( $this, $hook_method ) );
				}
			}
		}

		/**
		 * Initializes ACF Fields on plugins_loaded hook.
		 *
		 * @private
		 */
		public function initialize_fields() {
			WPS\WP\Fields::get_instance();
		}

		/**
		 * Process default value for settings
		 *
		 * @param array $default Default value.
		 *
		 * @return \WP_Term|bool
		 */
		protected function process_default( $default = '' ) {

			if ( null === $default || '' === $default ) {
				return false;
			}
			if (
				( null !== $this->default && $this->default['name'] === $default ) ||
				( null !== $this->default && $this->default['slug'] === $default )
			) {
				return $this->default;
			}

			// Check for term by slug.
			$term = get_term_by( 'slug', $default, $this->taxonomy, ARRAY_A );

			// If slug fails, check for term by name.
			if ( false === $term ) {
				$term = get_term_by( 'name', $default, $this->taxonomy, ARRAY_A );
			}

			// If name also fails, populate taxonomy default.
			if ( false === $term ) {
				$term = $this->populate_taxonomy_default( $default );
			}

			if ( !is_wp_error($term)) {
				$this->default = $term;
				update_option( 'default_' . $this->taxonomy, $this->default['term_id'] );
			}

			return $this->default;
		}

		/**
		 * Get a term array from inputs.
		 *
		 * @param string $term_name Term name.
		 * @param string $term_slug Term slug.
		 *
		 * @return array
		 */
		protected function get_term_array( $term_name, $term_slug = '' ) {
			if ( is_array( $term_name ) ) {
				return $term_name;
			} elseif ( is_string( $term_name ) ) {
				$term_slug = '' !== $term_slug ? $term_slug : sanitize_title_with_dashes( $term_name );

				// Create default term array.
				return array(
					'name' => $term_name,
					'slug' => $term_slug,
				);
			}

			return array();
		}

		/**
		 * Removes term from terms array.
		 *
		 * @param string $term_name Term name.
		 *
		 * @return bool Whether term was removed or not.
		 */
		public function remove_term( $term_name ) {
			if ( isset( $this->terms_to_be_added[ $term_name ] ) ) {
				unset( $this->terms_to_be_added[ $term_name ] );

				return true;
			}

			foreach ( $this->terms_to_be_added as $key => $data ) {
				if ( isset( $data['slug'] ) && $term_name === $data['slug'] ) {
					unset( $this->terms_to_be_added[ $key ] );

					return true;
				}
			}

			return false;
		}

		/**
		 * Adds a term to populate taxonomy.
		 *
		 * @param string $term_name Term name.
		 * @param string $term_slug Term slug.
		 */
		public function add_term( $term_name, $term_slug = '' ) {
			$this->terms_to_be_added[ $term_name ] = $this->get_term_array( $term_name, $term_slug );
		}

		public function get_args() {
			$plural = ucwords( $this->get_word( $this->plural ) );

			return wp_parse_args( array(
				'label'       => __( $plural, 'wps' ),
				'description' => __( 'For ' . $plural, 'wps' ),
				'labels'      => $this->get_labels(),
				'rewrite'     => $this->get_rewrite(),
			), $this->get_defaults() );
		}

		/**
		 * Registers the post type helper method.
		 *
		 * @param array $args Array of post type args.
		 */
		public function register_taxonomy() {

			register_taxonomy( $this->taxonomy, $this->object_type, $this->get_args() );

			if ( $this->single ) {
				$this->make_single_term();
			}

		}

		private function get_single_defaults() {
			return array(
				// Priority of the metabox placement.
				'priority'        => 'low',

				// 'normal' to move it under the post content.
				'context'         => '',

				// Custom title for your metabox.
				'metabox_title'   => '',

				// Makes a selection required.
				'force_selection' => false,

				// Will keep radio elements from indenting for child-terms.
				'indented'        => true,

				// Allows adding of new terms from the metabox.
				'allow_new_terms' => true,

				// Set default value.
				'default'         => '',
			);
		}

		/**
		 * Make single term taxonomy.
		 */
		protected function make_single_term() {

			$args = is_array( $this->single ) ? wp_parse_args( $this->single, $this->get_single_defaults() ) : $this->get_single_defaults();

			if ( method_exists( $this, 'get_single_args' ) ) {
				$args = wp_parse_args( $this->get_single_args(), $args );
			}
			$type = isset( $args['type'] ) ? $args['type'] : 'radio';

			// Create taxonomy.
			$taxonomy = new SingleTermTaxonomy( $this->taxonomy, (array) $this->object_type, $type );

			if ( method_exists( $this, 'get_single_args' ) ) {
				foreach ( array_keys( $this->get_single_defaults() ) as $property ) {
					$taxonomy->set( $property, $args[ $property ] );
				}
			}
		}

		/**
		 * Gets rewrite args.
		 *
		 * @return array Array of rewrite post type args.
		 */
		protected function get_rewrite() {

			if ( ! empty( $this->rewrite ) ) {
				return $this->rewrite;
			}

			$this->rewrite = array(
				'slug'       => $this->taxonomy,
				'with_front' => true,
				'pages'      => true,
				'feeds'      => true,
			);

			return $this->rewrite;
		}

		/**
		 * Getter method for retrieving post type registration defaults.
		 *
		 * @link http://codex.wordpress.org/Function_Reference/register_taxonomy
		 */
		public function get_defaults() {

			if ( ! empty( $this->defaults ) ) {
				return $this->defaults;
			}

			$this->defaults = apply_filters( 'wps_taxonomy_defaults', array(
				'public'                => true,
				'publicly_queryable'    => true,
				'hierarchical'          => true,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_nav_menus'     => true,
				'show_in_rest'          => true,
				'rest_base'             => $this->taxonomy,
				'rest_controller_class' => 'WP_REST_Terms_Controller',
				'show_tagcloud'         => true,
				'show_in_quick_edit'    => true,
				'show_admin_column'     => true,
				'capabilities'          => array(),
				'sort'                  => '',
				'update_count_callback' => '_update_post_term_count', // _update_generic_term_count
				'query_var'             => $this->taxonomy,
			) );

			return $this->defaults;

		}

		/**
		 * Creates a capabilities array for taxonomy registration.
		 *
		 * @param string $capability_slug Capability slug.
		 */
		public function get_capabilities( $capability_slug ) {
			$capability_slug = sanitize_title_with_dashes( $capability_slug );
			array(
				'manage_terms' => "publish_{$capability_slug}",
				'edit_terms'   => "edit_{$capability_slug}",
				'delete_terms' => "delete_{$capability_slug}",
				'assign_terms' => "assign_{$capability_slug}",
			);
		}

		/**
		 * Removes taxonomy metabox.
		 *
		 * @param string $post_type Post type.
		 */
		public function remove_taxonomy_metaboxes( $post_type ) {
			if ( in_array( $this->taxonomy, get_object_taxonomies( $post_type ), true ) ) {
				remove_meta_box( $this->taxonomy . 'div', $post_type, 'side' );
			}
		}

		/**
		 * A helper function for generating the labels (taxonomy)
		 *
		 * @return array Labels array
		 */
		public function get_labels() {
			$singular = ucwords( $this->get_word( $this->singular ) );
			$plural   = ucwords( $this->get_word( $this->plural ) );

			return array(
				'name'              => __( $plural, 'Taxonomy General Name', 'wps' ),
				'singular_name'     => __( $singular, 'Taxonomy Singular Name', 'wps' ),
				'search_items'      => __( 'Search ' . $plural, 'wps' ),
				'all_items'         => __( 'All ' . $plural, 'wps' ),
				'parent_item'       => __( 'Parent ' . $singular, 'wps' ),
				'parent_item_colon' => __( 'Parent ' . $singular . ':', 'wps' ),
				'edit_item'         => __( 'Edit ' . $singular, 'wps' ),
				'update_item'       => __( 'Update ' . $singular, 'wps' ),
				'add_new_item'      => __( 'Add New ' . $singular, 'wps' ),
				'new_item_name'     => __( 'New ' . $singular . ' Name', 'wps' ),
				'menu_name'         => __( $plural, 'wps' ),
			);
		}

		/**
		 * Populate taxonomy and sets default taxonomy term if it exists.
		 */
		public static function populate_taxonomy( $taxonomy, $terms_to_be_added ) {
			$terms = array();

			// Populate with Terms.
			foreach ( $terms_to_be_added as $term => $data ) {
				if ( ! self::term_exists( $term, $taxonomy ) ) {
					$terms[] = wp_insert_term( $term, $taxonomy, $data );
				}
			}

			return $terms;

		}

//		/**
//		 * Populate taxonomy and sets default taxonomy term if it exists.
//		 */
//		public function populate_taxonomy() {
//			WPS\write_log( $this, 'populate_taxonomy' );
//			// Populate with Terms.
//			foreach ( $this->terms_to_be_added as $term => $data ) {
//				if ( ! term_exists( $term, $this->taxonomy ) ) {
//					$this->terms[] = wp_insert_term( $term, $this->taxonomy, $data );
//				}
//			}
//
//		}

		/**
		 * Populates a taxonomy with the default term.
		 */
		public function populate_taxonomy_default( $default ) {
			if ( is_string( $default ) ) {
				$term = wp_insert_term( $default, $this->taxonomy );

				if ( ! empty( $term ) && ! is_wp_error( $term ) ) {
					$this->default = get_term( $term['term_id'], $this->taxonomy, ARRAY_A );
					update_option( 'default_' . $this->taxonomy, $this->default['term_id'] );

					return $term;
				} else {
					return new \WP_Error( 'term-not-insert', __( 'Term could not be inserted.', 'wps' ) );
				}
			}

			return new \WP_Error( 'term-not-string', __( 'Term should be a string.', 'wps' ) );
		}

		/**
		 * Gets default term.
		 *
		 * @param string $object_type Object type.
		 *
		 * @return int
		 */
		public function get_default( $object_type = '' ) {

			if ( empty( $this->default ) ) {
				return intval( get_option( 'default_' . $this->taxonomy ) );
			}

			if ( isset( $this->default[ $object_type ] ) ) {
				return $this->default[ $object_type ];
			}

			return absint( $this->default[0] );

		}

		/**
		 * Define default terms for custom taxonomies
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 */
		public function set_default_object_term( $post_id, $post ) {

			// Bail out if running an autosave, ajax or a cron.
			if ( $this->should_bail() ) {
				return;
			}

			// Make sure we are dealing with an associated post & a taxonomy with a default term.
			$post_type = get_post_type( $post );
			if ( ! is_object_in_taxonomy( $post_type, $this->taxonomy ) ) {
				return;
			}

			// Now make sure we have the default term inserted.
			// Now we should have what we need.
			$default = $this->get_default( get_post_type( $post ) );
			if ( ! empty( $default ) ) {
				// Get set terms.
				$terms = wp_get_object_terms( $post_id, $this->taxonomy );

				$default_term_id = is_int( $default ) ? $default : ( is_array( $default ) && isset( $default['term_id'] ) ? $default['term_id'] : 0 );

				// If no terms are currently set, force default term.
				if ( empty( $terms ) && self::term_exists( $default_term_id, $this->taxonomy ) ) {
					wp_set_object_terms( $post_id, $default_term_id, $this->taxonomy );
				}
			}
		}

		/**
		 * Sort child terms.
		 *
		 * @param array $children Child terms.
		 *
		 * @return array
		 */
		public static function sort_child_terms( $children ) {
			$sorted = array();
			foreach ( $children as $child ) {
				$term                  = get_term( $child, get_queried_object()->taxonomy );
				$sorted[ $term->name ] = $term;
			}

			ksort( $sorted );

			return $sorted;
		}

		/**
		 * Set a term to have a limited number of objects.
		 *
		 * @param string $term  Term name.
		 * @param int    $limit Number of published posts.
		 */
		public function limit_num_posts_for_term( $term, $limit ) {
			$this->limits[] = new LimitNumPostsForTerm( $this->taxonomy, $term, $limit );
		}

		/**
		 * Cached version of term_exists()
		 *
		 * Term exists calls can pile up on a single pageload.
		 * This function adds a layer of caching to prevent lots of queries.
		 *
		 * @param int|string $term     The term to check can be id, slug or name.
		 * @param string     $taxonomy The taxonomy name to use
		 * @param int        $parent   Optional. ID of parent term under which to confine the exists search.
		 *
		 * @return mixed Returns null if the term does not exist. Returns the term ID
		 *               if no taxonomy is specified and the term ID exists. Returns
		 *               an array of the term ID and the term taxonomy ID the taxonomy
		 *               is specified and the pairing exists.
		 */
		public static function term_exists( $term, $taxonomy = '', $parent = null ) {
			// If $parent is not null, let's skip the cache.
			if ( null !== $parent ) {
				return term_exists( $term, $taxonomy, $parent );
			}
			if ( ! empty( $taxonomy ) ) {
				$cache_key = $term . '|' . $taxonomy;
			} else {
				$cache_key = $term;
			}
			$cache_value = wp_cache_get( $cache_key, 'term_exists' );
			// term_exists frequently returns null, but (happily) never false
			if ( false === $cache_value ) {
				$term_exists = term_exists( $term, $taxonomy );
				wp_cache_set( $cache_key, $term_exists, 'term_exists', 3 * HOUR_IN_SECONDS );
			} else {
				$term_exists = $cache_value;
			}
			if ( is_wp_error( $term_exists ) ) {
				$term_exists = null;
			}

			return $term_exists;
		}

		/**
		 * Gets the first term attached to the post.
		 *
		 * Heavily borrowed from Bill Erickson.
		 *
		 * @link https://github.com/billerickson/EA-Genesis-Child/
		 *
		 * @param \WP_Post|int $post_or_id The Post or the Post ID.
		 * @param string $taxonomy The taxonomy.
		 *
		 * @return array|bool|null|\WP_Error|\WP_Term
		 */
		public static function get_the_first_term( $post_or_id, $taxonomy = 'category' ) {

			if ( ! $post = get_post( $post_or_id ) ) {
				return false;
			}

			$term = false;

			// Use WP SEO Primary Term
			// from https://github.com/Yoast/wordpress-seo/issues/4038
			if ( class_exists( 'WPSEO_Primary_Term' ) ) {
				$term = get_term( ( new \WPSEO_Primary_Term( $taxonomy, $post->ID ) )->get_primary_term(), $taxonomy );
			}

			// Fallback on term with highest post count
			if ( ! $term || is_wp_error( $term ) ) {
				$terms = get_the_terms( $post->ID, $taxonomy );
				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					return false;
				}

				// If there's only one term, use that
				if ( 1 == count( $terms ) ) {
					$term = array_shift( $terms );

					// If there's more than one...
				} else {

					// Sort by term order if available
					// @uses WP Term Order plugin
					if ( isset( $terms[0]->order ) ) {
						$list = array();
						foreach ( $terms as $term ) {
							$list[ $term->order ] = $term;
						}

						ksort( $list, SORT_NUMERIC );

						// Or sort by post count
					} else {
						$list = array();
						foreach ( $terms as $term ) {
							$list[ $term->count ] = $term;
						}

						ksort( $list, SORT_NUMERIC );
						$list = array_reverse( $list );
					}
					$term = array_shift( $list );
				}
			}

			return $term;

		}

		/**
		 * Set the object properties.
		 *
		 * @param string $property Property in object.  Must be set in object.
		 * @param mixed  $value    Value of property.
		 *
		 * @return Taxonomy  Returns Taxonomy object, allows for chaining.
		 */
		public function set( $property, $value ) {

			if ( ! property_exists( $this, $property ) ) {
				return $this;
			}

			if ( 'default' === $property ) {
				// $this->$property is set within process_default().
				$value = $this->process_default( $value );
				return $this;
			}

			$this->$property = $value;

			return $this;
		}

		/**
		 * Magic getter for our object.
		 *
		 * @param  string $property Property in object to retrieve.
		 *
		 * @throws \Exception Throws an exception if the field is invalid.
		 *
		 * @return mixed     Property requested.
		 */
		public function __get( $property ) {

			if ( property_exists( $this, $property ) ) {
				return $this->{$property};
			}

			throw new \Exception( 'Invalid ' . __CLASS__ . ' property: ' . $property );
		}

	}
}
