<?php 
namespace ElementsKit_Lite;

defined( 'ABSPATH' ) || exit;

class ElementsKit_Cpt_Api extends Core\Handler_Api {

	public function config() {
		$this->prefix = 'dynamic-content';
		$this->param  = '/(?P<type>\w+)/(?P<key>\w+(|[-]\w+))/';
	}

	public function get_content_editor() {

		if ( ! is_user_logged_in() ) {
			wp_die(
				esc_html__( 'You must be logged in.', 'elementskit-lite' ),
				401
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to access this page.', 'elementskit-lite' ),
				403
			);
		}

		$content_key        = sanitize_key( $this->request['key'] );
		$content_type       = sanitize_key( $this->request['type'] );
		$builder_post_title = 'dynamic-content-' . $content_type . '-' . $content_key;

		$builder_post_slug = Utils::get_page_by_title( $builder_post_title, 'elementskit_content' );

		if ( is_null( $builder_post_slug ) ) {

			// Contributors -> pending
			// Authors/Editors/Admins -> publish
			$post_status = current_user_can( 'publish_posts' )
				? 'publish'
				: 'pending';

			$builder_post_id = wp_insert_post(
				array(
					'post_content' => '',
					'post_title'   => $builder_post_title,
					'post_name'    => sanitize_title( $builder_post_title ),
					'post_status'  => $post_status,
					'post_type'    => 'elementskit_content',
					'post_author'  => get_current_user_id(),
				)
			);

			if ( ! is_wp_error( $builder_post_id ) ) {
				update_post_meta(
					$builder_post_id,
					'_wp_page_template',
					'elementor_canvas'
				);
			}

		} else {

			$builder_post_id = $builder_post_slug->ID;

			// Prevent users editing other users' content unless they have permission.
			if (
				! current_user_can( 'edit_others_posts' ) &&
				(int) get_post_field( 'post_author', $builder_post_id ) !== get_current_user_id()
			) {
				wp_die(
					esc_html__( 'You are not allowed to edit this content.', 'elementskit-lite' ),
					403
				);
			}
		}

		// WPML support.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$builder_post_id = $this->set_wpml_data( $builder_post_id );
		}

		$url = admin_url(
			'post.php?post=' . absint( $builder_post_id ) . '&action=elementor'
		);

		wp_safe_redirect( $url );
		exit;
	}

	public function set_wpml_data($builder_post_id) {
		global $sitepress;
		$default_language = $sitepress->get_default_language();
		$wpml_element_type = apply_filters( 'wpml_element_type', 'elementskit_content' );
		$trid = $sitepress->get_element_trid( $builder_post_id, $wpml_element_type );
		if( ! $trid ) {
			$sitepress->set_element_language_details( $builder_post_id, $wpml_element_type, false, $default_language, null, false );
		}

		// get wpml post by language code
		$referer = wp_get_referer();
		$referer = wp_parse_url($referer);
		$referer = !empty($referer['query']) ? $referer['query'] : '';
		$referer = parse_str($referer, $referer_args);

		if( !empty($referer_args['post']) ) {
			$language_details = apply_filters( 'wpml_post_language_details', NULL, $referer_args['post'] );
			if( !is_wp_error($language_details) ) {
				$builder_post_id = apply_filters( 'wpml_object_id', $builder_post_id, 'elementskit_content', true, $language_details['language_code'] );
			}
		}

		return $builder_post_id;
	}
}
new ElementsKit_Cpt_Api();
