<?php 
namespace ElementsKit_Lite\Modules\Layout_Manager;

defined( 'ABSPATH' ) || exit;

class Layout_List_Api extends \ElementsKit_Lite\Core\Handler_Api {

	public function config() {
		$this->prefix = 'layout-manager-api';
	}

	public function get_layout_list() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', esc_html__( 'You do not have permission to access this resource', 'elementskit-lite' ), array( 'status' => 403 ) );
		}

		$param = array_merge( \ElementsKit_Lite::license_data(), $_GET, array( 'action' => 'get_layout_list' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Passed in elementor's hook for get url which has processed the nonce already.

		$response = wp_remote_get( 
			\ElementsKit_Lite::api_url() . 'layout-manager-api/?' . http_build_query( $param ),
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		return json_decode( wp_remote_retrieve_body( $response ) );
	}
   
}
