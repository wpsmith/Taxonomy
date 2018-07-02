<?php
/**
 * Taxonomy Single Class
 *
 * Assists in the creation and management of Single Taxonomies.
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

if ( ! class_exists( 'WPS\Taxonomies\SingleTermTaxonomyWalker' ) && class_exists( 'Walker' ) ) :

	/**
	 * Walker to output an unordered list of taxonomy radio <input> elements.
	 *
	 * @see Walker
	 * @see wp_category_checklist()
	 * @see wp_terms_checklist()
	 * @since 0.1.2
	 */
	class SingleTermTaxonomyWalker extends \Walker {
		public $tree_type = 'category';
		public $db_fields = array( 'parent' => 'parent', 'id' => 'term_id' ); //TODO: decouple this

		public function __construct( $hierarchical, $input_element ) {
			$this->hierarchical = $hierarchical;
			$this->input_element = $input_element;
		}

		/**
		 * Starts the list before the elements are added.
		 *
		 * @see Walker:start_lvl()
		 *
		 * @since 0.1.2
		 *
		 * @param string $output Passed by reference. Used to append additional content.
		 * @param int    $depth  Depth of category. Used for tab indentation.
		 * @param array  $args   An array of arguments. @see wp_terms_checklist()
		 */
		public function start_lvl( &$output, $depth = 0, $args = array() ) {
			if ( 'radio' == $this->input_element ) {
				$indent = str_repeat("\t", $depth);
				$output .= "$indent<ul class='children'>\n";
			}
		}

		/**
		 * Ends the list of after the elements are added.
		 *
		 * @see Walker::end_lvl()
		 *
		 * @since 0.1.2
		 *
		 * @param string $output Passed by reference. Used to append additional content.
		 * @param int    $depth  Depth of category. Used for tab indentation.
		 * @param array  $args   An array of arguments. @see wp_terms_checklist()
		 */
		public function end_lvl( &$output, $depth = 0, $args = array() ) {
			if ( 'radio' == $this->input_element ) {
				$indent = str_repeat("\t", $depth);
				$output .= "$indent</ul>\n";
			}
		}

		/**
		 * Start the element output.
		 *
		 * @see Walker::start_el()
		 *
		 * @since 0.1.2
		 *
		 * @param string $output   Passed by reference. Used to append additional content.
		 * @param object $term The current term object.
		 * @param int    $depth    Depth of the term in reference to parents. Default 0.
		 * @param array  $args     An array of arguments. @see wp_terms_checklist()
		 * @param int    $id       ID of the current term.
		 */
		public function start_el( &$output, $term, $depth = 0, $args = array(), $id = 0 ) {

			$taxonomy = empty( $args['taxonomy'] ) ? 'category' : $args['taxonomy'];
			$name = $taxonomy == 'category' ? 'post_category' : 'tax_input['.$taxonomy.']';
			// input name
			$name = $this->hierarchical ? $name .'[]' : $name;
			// input value
			$value = $this->hierarchical ? $term->term_id : $term->slug;

			$selected_cats = empty( $args['selected_cats'] ) ? array() : $args['selected_cats'];
			$in_selected   = in_array( $term->term_id, $selected_cats );

			$args = array(
				'id'            => esc_attr( $taxonomy .'-'. $term->term_id ),
				'name'          => esc_attr( $name ),
				'value'         => esc_attr( $value ),
				'checked'       => checked( $in_selected, true, false ),
				'selected'      => selected( $in_selected, true, false ),
				'disabled'      => disabled( empty( $args['disabled'] ), false, false ),
				'label'         => esc_html( apply_filters('the_category', $term->name ) ),
				'depth'         => $depth,
			);

			$output .= 'radio' == $this->input_element
				? $this->start_el_radio( $args )
				: $this->start_el_select( $args );
		}

		/**
		 * Creates the opening markup for the radio input
		 *
		 * @since  0.2.0
		 *
		 * @param  array  $args Array of arguments for creating the element
		 *
		 * @return string       Opening li element and radio input
		 */
		public function start_el_radio( $args ) {
			return "\n".sprintf(
					'<li id="%s"><label class="selectit"><input value="%s" type="radio" name="%s" id="in-%s" %s %s/>%s</label>',
					$args['id'],
					$args['value'],
					$args['name'],
					$args['id'],
					$args['checked'],
					$args['disabled'],
					$args['label']
				);
		}

		/**
		 * Creates the opening markup for the select input
		 *
		 * @since  0.2.0
		 *
		 * @param  array  $args Array of arguments for creating the element
		 *
		 * @return string       Opening option element and option text
		 */
		public function start_el_select( $args ) {
			$pad = str_repeat('&nbsp;', $args['depth'] * 3);

			return "\n".sprintf(
					'<option %s %s id="%s" value="%s" class="class-single-term">%s',
					$args['selected'],
					$args['disabled'],
					$args['id'],
					$args['value'],
					$pad . $args['label']
				);
		}

		/**
		 * Ends the element output, if needed.
		 *
		 * @see Walker::end_el()
		 *
		 * @since 0.1.2
		 *
		 * @param string $output   Passed by reference. Used to append additional content.
		 * @param object $term The current term object.
		 * @param int    $depth    Depth of the term in reference to parents. Default 0.
		 * @param array  $args     An array of arguments. @see wp_terms_checklist()
		 */
		public function end_el( &$output, $term, $depth = 0, $args = array() ) {
			if ( 'radio' == $this->input_element ) {
				$output .= "</li>\n";
			} else {
				$output .= "</option>\n";
			}
		}

	}

endif; // class_exists check