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
 * @package    WPS\Taxonomy
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2018 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @version    1.0.0
 * @since      0.1.0
 */

namespace WPS\Taxonomies;

use WPS;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPS\Taxonomies\Taxonomy' ) ) {
	/**
	 * Taxonomy class.
	 *
	 * @package WPS_Core
	 * @author  Travis Smith <t@wpsmith.net>
	 */
	abstract class Taxonomy extends WPS\Core\Singleton {

		/**
		 * Taxonomy registered name
		 *
		 * @var string
		 */
		public $taxonomy;

		/**
		 * Registered Taxonomy Object.
		 *
		 * @var \WP_Taxonomy
		 */
		public $taxonomy_object;

		/**
		 * Singular Taxonomy registered name
		 *
		 * @var string
		 */
		public $singular;

		/**
		 * Plural Taxonomy registered name
		 *
		 * @var string
		 */
		public $plural;

		/**
		 * Default term array.
		 *
		 * @var array|string $args        {
		 *     Optional. Array or string of arguments for inserting a term.
		 * @type string      $name        Name of the term.
		 * @type string      $alias_of    Slug of the term to make this term an alias of.
		 * Default empty string. Accepts a term slug.
		 * @type string      $description The term description. Default empty string.
		 * @type int         $parent      The id of the parent term. Default 0.
		 * @type string      $slug        The term slug to use. Default empty string.
		 * }
		 */
		private $_default_term = array();

		/**
		 * Default term object.
		 *
		 * @var \WP_Term
		 */
		private $default_term;

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
		public $_terms = array();

		/**
		 * Array of registered terms.
		 *
		 * @var \WP_Term[]
		 */
		public $terms = array();

		/**
		 * What metaboxes to remove.
		 *
		 * Supports:
		 *  ''
		 *
		 * @var array
		 */
		public $remove_metaboxes = array();

		/**
		 * Whether to remove the taxonomy's metabox from its post types.
		 *
		 * @var bool
		 */
		public $no_metabox = false;

		/**
		 * Constructor. Hooks all interactions to initialize the class.
		 *
		 * @since 1.0.0
		 */
		protected function __construct() {

			$this->plural   = $this->plural ? $this->plural : $this->taxonomy;
			$this->singular = $this->singular ? $this->singular : $this->taxonomy;

			// Set default terms.
			add_action( 'save_post', array( $this, 'set_default_object_term' ), 100, 2 );

			// Prepopulate terms.
			register_activation_hook( 'wps', array( $this, 'activate' ) );
			$activation_hook = 'activate_' . plugin_basename( 'wps' );
			if ( did_action( $activation_hook ) || doing_action( $activation_hook ) ) {
				$this->activate();
			}

			// Create the create_taxonomy.
			add_action( 'init', array( $this, 'create_taxonomy' ), 0 );
			add_action( 'init', array( $this, 'set_taxonomy_object' ), ~PHP_INT_MAX );

			// Maybe run init method.
			if ( method_exists( $this, 'init' ) ) {
				$this->init();
			}

			// Initialize fields for ACF.
			add_action( 'plugins_loaded', array( $this, 'initialize_fields' ) );

			// Maybe run methods.
			// Maybe create ACF fields.
			foreach ( array( 'core_acf_fields', 'admin_menu', 'admin_init', 'plugins_loaded' ) as $hook_method ) {
				if ( method_exists( $this, $hook_method ) ) {
					if ( did_action( $hook_method ) || doing_action( $hook_method ) ) {
						call_user_func( array( $this, $hook_method ) );
					} else {
						add_action( $hook_method, array( $this, $hook_method ) );
					}
				}
			}
			// Remove Taxonomy Metabox.
			if ( $this->no_metabox ) {
				add_action( 'add_meta_boxes', array( $this, 'remove_taxonomy_metaboxes' ), 10 );
			}

		}

		/**
		 * Activation method.
		 */
		public function activate() {
			if ( ! empty( $this->_default_term ) || ! empty( $this->_terms ) ) {
				$this->populate_taxonomy();
			}
		}

		/**
		 * Sets the taxonomy object.
		 */
		public function set_taxonomy_object() {
			$this->taxonomy_object = get_taxonomy( $this->taxonomy );
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
				$term_slug = '' !== $term_slug ? $term_slug : $term_name;

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
			if ( isset( $this->_terms[ $term_name ] ) ) {
				unset( $this->_terms[ $term_name ] );

				return true;
			}

			foreach ( $this->_terms as $key => $data ) {
				if ( isset( $data['slug'] ) && $term_name === $data['slug'] ) {
					unset( $this->_terms[ $key ] );

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
			$this->_terms[ $term_name ] = $this->get_term_array( $term_name, $term_slug );
		}

		/**
		 * Sets this object's default_term property.
		 *
		 * @param string $term_name Term Name.
		 * @param string $term_slug Term Slug.
		 */
		public function set_default_term( $term_name, $term_slug = '' ) {
			$this->_default_term = $this->get_term_array( $term_name, $term_slug );

		}

		/**
		 * Create Taxonomies
		 *
		 * @since 1.0.0
		 */
		abstract public function create_taxonomy();

		/**
		 * Registers the post type helper method.
		 *
		 * @param array $args Array of post type args.
		 */
		protected function register_taxonomy( $args = array() ) {
			$plural = ucwords( $this->get_word( $this->plural ) );

			$defaults = wp_parse_args( array(
				'label'       => __( $plural, 'wps' ),
				'description' => __( 'For ' . $plural, 'wps' ),
				'labels'      => $this->get_labels(),
				'rewrite'     => $this->get_rewrite(),
			), $this->get_defaults() );

			$args = wp_parse_args( $args, $defaults );
			if ( isset( $args['terms'] ) ) {
				foreach ( (array) $args['terms'] as $data ) {
					$this->add_term( $data );
				}
				unset( $args['terms'] );
			}
			if ( isset( $args['default_term'] ) ) {
				$this->set_default_term( $args['default_term'] );
				unset( $args['default_term'] );
			}

			register_taxonomy( $this->taxonomy, $args );

		}

		/**
		 * Gets rewrite args.
		 *
		 * @return array Array of rewrite post type args.
		 */
		protected function get_rewrite() {
			return array(
				'slug'       => $this->taxonomy,
				'with_front' => true,
				'pages'      => true,
				'feeds'      => true,
			);
		}

		/**
		 * Getter method for retrieving post type registration defaults.
		 *
		 * @link http://codex.wordpress.org/Function_Reference/register_taxonomy
		 */
		public function get_defaults() {

			return apply_filters( 'wps_taxonomy_defaults', array(

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
				'terms'                 => null,
				'default_term'          => null,
			) );

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
		 * Gets the post type as words
		 *
		 * @param string $str String to capitalize.
		 *
		 * @return string Capitalized string.
		 */
		protected function get_word( $str ) {
			return str_replace( '-', ' ', str_replace( '_', ' ', $str ) );
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
		public function populate_taxonomy() {
			// Populate with Default Term.
			$this->populate_taxonomy_default_term();

			// Populate with Terms.
			foreach ( $this->_terms as $term => $data ) {
				if ( ! term_exists( $term, $this->taxonomy ) ) {
					$this->terms[] = wp_insert_term( $term, $this->taxonomy, $data );
				}
			}
		}

		/**
		 * Populates a taxonomy with the default term.
		 */
		public function populate_taxonomy_default_term() {
			if ( ! empty( $this->_default_term ) ) {
				$this->default_term = wp_insert_term( $this->_default_term['name'], $this->taxonomy, $this->_default_term );
				update_option( 'default_' . $this->taxonomy, $this->default_term['term_id'] );
			}
		}

		/**
		 * Bail out if running an autosave, ajax or a cron
		 *
		 * @return bool
		 */
		private function should_bail() {
			return (
				( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
				( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
				( defined( 'DOING_CRON' ) && DOING_CRON )
			);
		}

		/**
		 * Define default terms for custom taxonomies
		 *
		 * @link http://wordpress.mfields.org/2010/set-default-terms-for-your-custom-taxonomies-in-wordpress-3-0/
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
			$post_type  = get_post_type( $post );
			$taxonomies = get_object_taxonomies( $post_type );
			if ( ! in_array( $this->taxonomy, $taxonomies, true ) || empty( $this->_default_term ) ) {
				return;
			}

			// Now make sure we have the default term inserted.
			if ( empty( $this->default_term ) && ! empty( $this->_default_term ) ) {
				$this->populate_taxonomy_default_term();
			}

			// Now we should have what we need.
			if ( ! empty( $this->default_term ) ) {
				// Get set terms.
				$terms = wp_get_object_terms( $post_id, $this->taxonomy );

				// If no terms are currently set, force default term.
				if ( empty( $terms ) && term_exists( $this->default_term['term_id'], $this->taxonomy ) ) {
					wp_set_object_terms( $post_id, $this->default_term['term_id'], $this->taxonomy );
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
		 * Hooks a function on to a specific action.
		 *
		 * Actions are the hooks that the WordPress core launches at specific points
		 * during execution, or when specific events occur. Plugins can specify that
		 * one or more of its PHP functions are executed at these points, using the
		 * Action API.
		 *
		 * @since 1.2.0
		 *
		 * @param string   $tag             The name of the action to which the $function_to_add is hooked.
		 * @param callable $function_to_add The name of the function you wish to be called.
		 * @param int      $priority        Optional. Used to specify the order in which the functions
		 *                                  associated with a particular action are executed. Default 10.
		 *                                  Lower numbers correspond with earlier execution,
		 *                                  and functions with the same priority are executed
		 *                                  in the order in which they were added to the action.
		 * @param int      $accepted_args   Optional. The number of arguments the function accepts. Default 1.
		 */
		public function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
			if ( did_action( $tag ) || doing_action( $tag ) ) {
				call_user_func_array( $function_to_add, array() );
			} else {
				add_action( $tag, $function_to_add, $priority, $accepted_args );
			}
		}

	}
}
