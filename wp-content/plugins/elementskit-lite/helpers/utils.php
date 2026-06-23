<?php
namespace ElementsKit_Lite;

defined( 'ABSPATH' ) || exit;

/**
 * Global helper class.
 *
 * @since 1.0.0
 */

class Utils {

	/**
	 * Auto generate classname from path.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public static function make_classname( $dirname ) {
		$dirname    = pathinfo( $dirname, PATHINFO_FILENAME );
		$class_name = explode( '-', $dirname );
		$class_name = array_map( 'ucfirst', $class_name );
		$class_name = implode( '_', $class_name );

		return $class_name;
	}

	public static function google_fonts( $font_families = array() ) {
		$fonts_url = '';
		if ( $font_families ) {
			$query_args = array(
				'family' => urlencode( implode( '|', $font_families ) ),
			);

			$fonts_url = add_query_arg( $query_args, 'https://fonts.googleapis.com/css' );
		}

		return esc_url_raw( $fonts_url );
	}

	public static function get_kses_array(){
		return array(
			'a'                             => array(
				'class'  => array(),
				'href'   => array(),
				'rel'    => array(),
				'title'  => array(),
				'target' => array(),
				'style'  => array(),
			),
			'abbr'                          => array(
				'title' => array(),
			),
			'b'                             => array(
                'class' => array(),
            ),
			'blockquote'                    => array(
				'cite' => array(),
			),
			'cite'                          => array(
				'title' => array(),
			),
			'code'                          => array(),
			'pre'                           => array(),
			'del'                           => array(
				'datetime' => array(),
				'title'    => array(),
			),
			'dd'                            => array(),
			'div'                           => array(
				'class' => array(),
				'title' => array(),
				'style' => array(),
			),
			'dl'                            => array(),
			'dt'                            => array(),
			'em'                            => array(),
			'strong'                        => array(),
			'h1'                            => array(
				'class' => array(),
			),
			'h2'                            => array(
				'class' => array(),
			),
			'h3'                            => array(
				'class' => array(),
			),
			'h4'                            => array(
				'class' => array(),
			),
			'h5'                            => array(
				'class' => array(),
			),
			'h6'                            => array(
				'class' => array(),
			),
			'i'                             => array(
				'class' => array(),
			),
			'img'                           => array(
				'alt'		=> array(),
				'class'		=> array(),
				'height'	=> array(),
				'src'		=> array(),
				'width'		=> array(),
				'style'		=> array(),
				'title'		=> array(),
				'srcset'	=> array(),
				'loading'	=> array(),
				'sizes'		=> array(),
			),
			'figure'                        => array(
				'class'		=> array(),
			),
			'li'                            => array(
				'class' => array(),
			),
			'ol'                            => array(
				'class' => array(),
			),
			'p'                             => array(
				'class' => array(),
			),
			'q'                             => array(
				'cite'  => array(),
				'title' => array(),
			),
			'span'                          => array(
				'class' => array(),
				'title' => array(),
				'style' => array(),
			),
			'iframe'                        => array(
				'width'       => array(),
				'height'      => array(),
				'scrolling'   => array(),
				'frameborder' => array(),
				'allow'       => array(),
				'src'         => array(),
			),
			'strike'                        => array(),
			'br'                            => array(),
			'table'  => array(),
			'thead'  => array(),
			'tbody'  => array(),
			'tfoot'  => array(),
			'tr'     => array(),
			'th'     => array(
				'class'   => true,
				'colspan' => true,
				'rowspan' => true,
				'style'   => true,
				'id' 	=> true,
			),
			'td'     => array(
				'class'   => true,
				'colspan' => true,
				'rowspan' => true,
				'style'   => true,
				'id' 	=> true,
			),
			'caption'=> array(),
			'col'    => array(
				'span'    => true,
				'style'   => true,
			),
			'colgroup' => array(
				'span'    => true,
				'style'   => true,
			),
			'strong'                        => array(),
			'data-wow-duration'             => array(),
			'data-wow-delay'                => array(),
			'data-wallpaper-options'        => array(),
			'data-stellar-background-ratio' => array(),
			'ul'                            => array(
				'class' => array(),
			),
			'svg'                           => array(
				'class'           => true,
				'aria-hidden'     => true,
				'aria-labelledby' => true,
				'role'            => true,
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true, // <= Must be lower case!
                'preserveaspectratio' => true,
			),
			'g'                             => array( 'fill' => true ),
			'title'                         => array( 'title' => true ),
			'path'                          => array(
				'd'    => true,
				'fill' => true,
			),
			'input'							=> array(
				'class'		=> array(),
				'type'		=> array(),
				'value'		=> array()
			)
		);
	}

	public static function kses( $raw ) {

		$allowed_tags = self::get_kses_array();

		if ( function_exists( 'wp_kses' ) ) { // WP is here
			return wp_kses( $raw, $allowed_tags );
		} else {
			return $raw;
		}
	}

	public static function kspan( $text ) {
		return str_replace( array( '{', '}' ), array( '<span>', '</span>' ), $text );
	}

	public static function ekit_get__forms( $post_type ) {
		$wpuf_form_list = get_posts(
			array(
				'post_type' => $post_type,
				'showposts' => 999,
			)
		);

		$options = array();

		if ( ! empty( $wpuf_form_list ) && ! is_wp_error( $wpuf_form_list ) ) {
			$options[0] = esc_html__( 'Select Form', 'elementskit-lite' );
			foreach ( $wpuf_form_list as $post ) {
				$options[ $post->ID ] = $post->post_title;
			}
		} else {
			$options[0] = esc_html__( 'Create a form first', 'elementskit-lite' );
		}

		return $options;
	}

	public static function ekit_get_ninja_form() {
		$options = array();

		if ( class_exists( 'Ninja_Forms' ) ) {
			$contact_forms = Ninja_Forms()->form()->get_forms();

			if ( ! empty( $contact_forms ) && ! is_wp_error( $contact_forms ) ) {

				$options[0] = esc_html__( 'Select Ninja Form', 'elementskit-lite' );

				foreach ( $contact_forms as $form ) {
					$options[ $form->get_id() ] = $form->get_setting( 'title' );
				}
			}
		} else {
			$options[0] = esc_html__( 'Create a Form First', 'elementskit-lite' );
		}

		return $options;
	}

	public static function tablepress_table_list() {
		$table_options = array();

		if ( class_exists( 'TablePress' ) ) {
			$table_ids        = \TablePress::$model_table->load_all( false );
			$table_options[0] = esc_html__( 'Select Table', 'elementskit-lite' );

			foreach ( $table_ids as $table_id ) {
				// Load table, without table data, options, and visibility settings.
				$table = \TablePress::$model_table->load( $table_id, false, false );

				if ( '' === trim( $table['name'] ) ) {
					$table['name'] = __( '(no name)', 'elementskit-lite' );
				}

				$table_options[ $table['id'] ] = $table['name'];
			}
		} else {
			$table_options[0] = esc_html__( 'Create a Table First', 'elementskit-lite' );
		}

		return $table_options;
	}

	public static function ekit_do_shortcode( $tag, array $atts = array(), $content = null ) {
		global $shortcode_tags;
		if ( ! isset( $shortcode_tags[ $tag ] ) ) {
			return false;
		}
		return call_user_func( $shortcode_tags[ $tag ], $atts, $content, $tag );
	}

	public static function trim_words( $text, $num_words ) {
		return wp_trim_words( $text, $num_words, '' );
	}

	public static function array_push_assoc( $array, $key, $value ) {
		$array[ $key ] = $value;
		return $array;
	}

	public static function render_elementor_content_css( $content_id ) {
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css_file = new \Elementor\Core\Files\CSS\Post( $content_id );
			$css_file->enqueue();
		}
	}

	public static function render_elementor_content( $content_id, $has_css = false ) {
		$elementor_instance = \Elementor\Plugin::instance();

		if (
			\Elementor\Plugin::$instance->editor->is_edit_mode()
			&& ! wp_style_is( 'elementor-frontend', 'registered' )
		) {
			wp_register_style( 'elementor-frontend', false );
		}

		/**
		 * CSS Print Method Internal and Exteral option support for Header and Footer Builder.
		 */
		if ( ( 'internal' === get_option( 'elementor_css_print_method' ) ) || \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			$has_css = true;
		}

		return $elementor_instance->frontend->get_builder_content_for_display( $content_id, $has_css );
	}

	public static function render( $content ) {
		if ( stripos( $content, 'elementskit-has-lisence' ) !== false ) {
			return null;
		}

		return $content;
	}

	public static function render_tab_content( $content, $id ) {
		return str_replace( '.elementor-' . $id . ' ', '#elementor .elementor-' . $id . ' ', $content );
	}

	public static function img_meta( $id ) {
		$attachment = get_post( $id );
		if ( $attachment == null || $attachment->post_type != 'attachment' ) {
			return null;
		}
		return array(
			'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'href'        => get_permalink( $attachment->ID ),
			'src'         => $attachment->guid,
			'title'       => $attachment->post_title,
		);
	}

	public static function esc_options( $str, $options = array(), $default = '' ) {
		if ( ! in_array( $str, $options ) ) {
			return $default;
		}

		return $str;
	}

	public static function get_attachment_image_html( $settings, $image_key, $image_size_key = null, $image_attr = array() ) {
		if ( ! $image_key ) {
			$image_key = $image_size_key;
		}

		$image = $settings[ $image_key ];

		$size = $image_size_key;

		$html = '';
		if ( ! empty( $image['id'] ) && $image['id'] != '-1' && get_post($image['id'])) {
			$html .= wp_get_attachment_image( $image['id'], $size, false, $image_attr );
		} else {
			$html .= sprintf(
				'<img src="%s" title="%s" alt="%s" class="%s" />',
				esc_attr($image['url']),
				\Elementor\Control_Media::get_image_title($image),
				\Elementor\Control_Media::get_image_alt($image),
				(isset($image_attr['class']) ? esc_attr($image_attr['class']) : '')
			);
		}

		$html = preg_replace( array( '/max-width:[^"]*;/', '/width:[^"]*;/', '/height:[^"]*;/' ), '', $html );

		return $html;
	}

	public static function swiper_class() {
		return 'ekit-main-swiper swiper';
	}

	/**
	* Get a page/post by slug (new method).
	*
	* @since 3.6.1
	*
	* @param string $slug      Post slug.
	* @param string $post_type Post type. Default 'page'.
	* @return WP_Post|null     WP_Post object if found, null otherwise.
	*/
	public static function get_page_by_title( $slug, $post_type = 'page' ) {
		$posts = get_posts(
			[
				'name' => $slug,
				'post_type' => $post_type,
				'post_status' => 'publish',
				'numberposts' => 1,
				'no_found_rows' => true,
				'ignore_sticky_posts' => true,
			]
		);

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		return null;
	}

	public static function remove_special_chars($string) {
		return preg_replace('/[^A-Za-z0-9 ]/', '', $string);
	}

	/**
	 * Check whether a specific plugin is active.
	 *
	 * Works for both single-site and multisite installations
	 * (including network-activated plugins).
	 *
	 * @since 3.7.8
	 *
	 * @param string $plugin_file Plugin file path relative to the plugins directory.
	 * Example: 'elementskit/elementskit.php'.
	 *
	 * @return bool True if the plugin is active, false otherwise.
	 */
	public static function ekit_is_plugin_active( string $plugin_file ): bool {
		$active_plugins = (array) apply_filters(
			'active_plugins',
			get_option( 'active_plugins', [] )
		);

		// Include network-activated plugins for multisite installs.
		if ( is_multisite() ) {
			$network_plugins = array_keys(
				(array) get_site_option( 'active_sitewide_plugins', [] )
			);

			$active_plugins = array_merge( $active_plugins, $network_plugins );
		}

		return in_array( $plugin_file, $active_plugins, true );
	}

	/**
	 * Get the ElementsKit promo icon SVG markup.
	 *
	 * @since 3.8.1
	 * @access public
	 *
	 * @param string $fill  Fill color for the SVG paths. Default '#13151D'.
	 * @param int    $width  Width of the SVG. Default 16.
	 * @param int    $height Height of the SVG. Default 14.
	 *
	 * @return string SVG markup.
	 */
	public static function get_promo_icon( string $fill = 'var(--e-a-color-txt-accent)', int $width = 16, int $height = 14 ): string {
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 16 14" fill="currentColor" style="margin-bottom:-2px;margin-right:5px;">
				<path d="M0.51183 8.49042H4.93761C5.14836 8.49042 5.2989 8.36999 5.38922 8.21945C5.41933 8.15924 5.44944 8.06892 5.44944 7.97859V6.11192C5.44944 5.81085 5.20858 5.6001 4.93761 5.6001H2.37848H0.51183C0.210756 5.6001 0 5.84096 0 6.11192V7.97859C0 8.27967 0.240863 8.49042 0.51183 8.49042Z" fill="%3$s"/>
				<path d="M11.3201 6.98495C11.3201 6.74409 11.4104 6.50322 11.5609 6.29247L14.8426 2.37849C14.9631 2.22796 15.0233 2.04731 14.9932 1.89677C14.9932 1.74623 14.933 1.62581 14.8125 1.50538L13.548 0.210752C13.4577 0.120429 13.3373 0.0602123 13.2168 0.0301048C13.1566 0.0301048 13.1265 0 13.0663 0C12.8857 0 12.705 0.0903189 12.5846 0.240857L11.4706 1.5957L10.4771 2.76989L8.24911 5.44946C7.97814 5.78064 7.82761 6.14193 7.7674 6.53333C7.67707 7.16559 7.8276 7.82795 8.24911 8.36989L9.30287 9.63441L9.99535 10.4774L11.4706 12.2538L12.2835 13.2473L12.705 13.7591C12.8255 13.9097 13.0061 13.9699 13.1867 14C13.2771 14 13.3674 13.9699 13.4577 13.9398C13.548 13.9097 13.6082 13.8495 13.6684 13.7892L13.7287 13.729L14.9029 12.5849C15.1437 12.3441 15.1738 11.9527 14.933 11.7118L14.5717 11.2602L13.3072 9.72473L11.5308 7.6172C11.4104 7.46666 11.3201 7.22581 11.3201 6.98495ZM10.4771 2.86022C10.4771 2.89032 10.4771 2.89032 10.4771 2.86022V2.86022Z" fill="%3$s"/>
				<path d="M1.65511 13.6989H7.85723C8.15831 13.6989 8.36906 13.4581 8.36906 13.1871V13.0968V11.3204C8.36906 11.0193 8.1282 10.8086 7.85723 10.8086H7.46584H4.18412H0.571227C0.511012 10.8086 0.450805 10.8086 0.39059 10.8387C0.179838 10.8989 0.0292969 11.1097 0.0292969 11.3204V12.2538V13.1871C0.0292969 13.4882 0.27016 13.6989 0.541127 13.6989H0.571227H1.65511Z" fill="%3$s"/>
				<path d="M6.71396 0.391602H0.51183C0.210756 0.391602 0 0.632465 0 0.903433V0.993752V2.7701C0 3.07117 0.240863 3.28193 0.51183 3.28193H0.903223H4.18492H7.79782C7.85803 3.28193 7.91826 3.28192 7.97847 3.25182C8.18923 3.1916 8.33977 2.98085 8.33977 2.7701V1.83676V0.903433C8.33977 0.602358 8.0989 0.391602 7.82794 0.391602H7.79782H6.71396Z" fill="%3$s"/>
			</svg>',
			$width,
			$height,
			esc_attr( $fill )
		);
	}

}
