<?php
/**
 * Plugin Unsubscribe / Deactivation Feedback Handler
 *
 * Renders a feedback modal on plugin deactivation, collects user input,
 * and sends telemetry to the ElementsKit API.
 *
 * @package ElementsKit_Lite\Core
 * @since   3.9.5
 */

namespace ElementsKit_Lite\Core;

use ElementsKit_Lite\Libs\Framework\Classes\Utils;
use ElementsKit\Libs\Framework\Classes\License;

defined( 'ABSPATH' ) || exit;
class Plugin_Unsubscribe {

	use \ElementsKit_Lite\Traits\Singleton;

	/**
	 * Constructor.
	 *
	 * Registers all admin-side hooks required by this feature.
	 *
	 * @since 3.9.5
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_footer',          array( $this, 'render_modal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_elementskit_deactivation_feedback',     array( $this, 'handle_feedback' ) );
	}


	/**
	 * Enqueue CSS and JS assets for the deactivation feedback modal.
	 *
	 * Passes localised data (nonce, AJAX URL, plugin URL)
	 * to the front-end script via {@see wp_localize_script()}.
	 *
	 * @since 3.9.5
	 *
	 * @return void
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

		$plugin_url = \ElementsKit_Lite::plugin_url();
		$version    = \ElementsKit_Lite::version();

		wp_enqueue_style(
			'elementskit-deactivation-modal',
			$plugin_url . 'assets/css/deactivation-modal.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'elementskit-deactivation-modal',
			$plugin_url . 'assets/js/deactivation-modal.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'elementskit-deactivation-modal',
			'ElementsKitDeactivation',
			array(
				'nonce'      => wp_create_nonce( 'elementskit-deactivation' ),
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				'plugin_url' => $plugin_url,
			)
		);
	}


	/**
	 * Output the deactivation feedback modal markup in the admin footer.
	 *
	 * @since 3.9.5
	 *
	 * @return void
	 */
	public function render_modal() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$reasons = $this->get_deactivation_reasons();
		?>
		<div id="elementskit-deactivation-modal" class="elementskit-modal">
			<div class="elementskit-modal-content">

				<?php $this->render_modal_header(); ?>

				<div class="elementskit-modal-body">
					<div id="elementskit-error-message" class="elementskit-error-message" style="display: none;"></div>

					<h2 class="elementskit-modal-title">
						<?php esc_html_e( 'Before you go, what made you deactivate ElementsKit?', 'elementskit-lite' ); ?>
					</h2>

					<form id="elementskit-deactivation-form" class="elementskit-form">
						<?php wp_nonce_field( 'elementskit-deactivation', 'elementskit_nonce' ); ?>

						<div class="form-group">
							<div class="radio-group">
								<?php foreach ( $reasons as $reason ) : ?>
									<?php $this->render_reason_item( $reason ); ?>
								<?php endforeach; ?>
							</div>

							<?php $this->render_modal_footer(); ?>
						</div>
					</form>
				</div><!-- .elementskit-modal-body -->

			</div><!-- .elementskit-modal-content -->
		</div><!-- #elementskit-deactivation-modal -->
		<?php
	}


	/**
	 * Handle the AJAX feedback-submission request.
	 *
	 * Verifies the nonce and user capabilities, collects payload data,
	 * then dispatches the data to the remote API via {@see send_feedback_data()}.
	 *
	 * Sends a JSON error response on failure; a JSON success response otherwise.
	 *
	 * @since 3.9.5
	 *
	 * @return void Terminates execution via {@see wp_send_json_error()} or
	 *              {@see wp_send_json_success()}.
	 */
	public function handle_feedback() {
		$this->verify_request();

		$selected_reason = isset( $_POST['reason'] )
			? sanitize_text_field( wp_unslash( $_POST['reason'] ) )
			: '';

		$data = array(
			'plugin_slug'    => 'elementskit',
			'plugin_name'    => 'ElementsKit',
			'plugin_version' => \ElementsKit_Lite::version(),
			'user'           => array(
				'email' => $this->get_user_email(),
			),
			'feedback'       => array(
				'reason_key'   => isset( $_POST['reason_key'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_key'] ) ) : 'other',
				'reason_label' => isset( $_POST['reason_label'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_label'] ) ) : $selected_reason,
				'message'      => isset( $_POST['feedback'] )? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '',
			),
			'usage'          => array(
				'active_widgets' => $this->get_active_widgets(),
				'active_modules' => $this->get_active_modules(),
				'user_type'      => $this->get_user_type(),
				'active_days'    => $this->get_days_active(),
			),
			'environment'    => array(
				'multisite_status'   => is_multisite(),
				'wp_version'         => get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'elementor_version'  => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'site_url'           => get_site_url(),
			),
		);

		$response = $this->send_feedback_data( $data );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Failed to submit feedback.', 'elementskit-lite' ),
					'code'    => $response_code,
				)
			);
		}

		wp_send_json_success(
			array( 'message' => esc_html__( 'Thank you for your feedback!', 'elementskit-lite' ) )
		);
	}


	/**
	 * Output the modal header, including the ElementsKit logo SVG and title.
	 *
	 * @since 3.9.5
	 *
	 * @return void
	 */
	private function render_modal_header() {
		?>
		<div class="elementskit-modal-header">
			<h2>
				<svg xmlns="http://www.w3.org/2000/svg" width="22" height="20" viewBox="0 0 22 20" fill="none" aria-hidden="true" focusable="false">
					<path d="M0.773071 12.0093H7.08208C7.38251 12.0093 7.5971 11.8376 7.72586 11.623C7.76878 11.5372 7.8117 11.4084 7.8117 11.2797V8.61873C7.8117 8.18954 7.46835 7.88911 7.08208 7.88911H0.773071C0.343887 7.88911 0.043457 8.23246 0.043457 8.61873V11.2797C0.043457 11.7089 0.386805 12.0093 0.773071 12.0093Z" fill="#F8003C"/>
					<path d="M14.4639 6.17245C14.4639 5.39991 14.6356 4.6703 14.9789 4.02652C14.9789 3.98361 14.9789 3.98361 14.9789 3.94069L11.803 7.76043C11.4167 8.23253 11.2021 8.74755 11.1163 9.30549C10.9875 10.2068 11.2021 11.151 11.803 11.9235L13.3051 13.7261L14.2922 14.9278L16.3952 17.46L17.554 18.8763L18.1549 19.6059C18.3266 19.8205 18.5841 19.9064 18.8416 19.9493C18.9703 19.9493 19.0991 19.9064 19.2278 19.8634C19.3566 19.8205 19.4424 19.7347 19.5283 19.6488L19.6141 19.563L21.2879 17.9321C21.6313 17.5888 21.6742 17.0308 21.3308 16.6875L20.8158 16.0437L19.0133 13.8549L16.4811 10.8506C16.2665 10.5931 16.1377 10.2497 16.1377 9.90635C16.0948 9.86343 16.0948 9.82052 16.0519 9.7776C15.1077 8.87631 14.4639 7.58876 14.4639 6.17245Z" fill="#27334F"/>
					<path d="M21.4088 2.70386C21.4088 2.48927 21.323 2.3176 21.1513 2.14592L19.3488 0.300428C19.22 0.171672 19.0483 0.0858395 18.8767 0.0429211C18.7908 0.0429211 18.7479 0 18.6621 0C18.4046 0 18.147 0.128757 17.9754 0.343349L16.3874 2.27468L14.9711 3.9485C14.9711 3.99141 14.9711 3.99142 14.9711 4.03434C14.6277 4.67811 14.4561 5.40773 14.4561 6.18026C14.4561 7.63949 15.0998 8.92704 16.1299 9.78541C16.1728 9.82833 16.1728 9.87125 16.2157 9.91416C16.2157 9.57082 16.3445 9.22747 16.5591 8.92704L21.2372 3.34764C21.3659 3.17597 21.4088 2.91846 21.4088 2.70386Z" fill="#FFAA00"/>
					<path d="M11.167 15.2715C11.5533 15.2715 11.8975 15.5718 11.8975 16.001V18.6611C11.8975 19.0474 11.5962 19.3916 11.167 19.3916H0.730469C0.344203 19.3916 0 19.0903 0 18.6611V16.001C0 15.7006 0.215196 15.3993 0.515625 15.3135C0.601369 15.2707 0.687694 15.2715 0.773438 15.2715H11.167Z" fill="#F8003C"/>
					<path d="M11.0117 0.463882C11.4838 0.463882 11.8271 0.804004 11.8271 1.22853V3.86037C11.8271 4.15753 11.6129 4.45515 11.3125 4.54005C11.2267 4.58241 11.1405 4.58205 11.0547 4.58205H6.0127L6.00879 4.584H0.773438C0.387171 4.584 0.0429688 4.28369 0.0429688 3.85451V1.19337C0.0431609 0.80726 0.34439 0.463882 0.773438 0.463882H11.0117Z" fill="#0099AC"/>
				</svg>

				<?php esc_html_e( 'Quick Feedback', 'elementskit-lite' ); ?>
			</h2>

			<button type="button" class="elementskit-modal-close" aria-label="<?php esc_attr_e( 'Close', 'elementskit-lite' ); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div><!-- .elementskit-modal-header -->
		<?php
	}

	/**
	 * Output a single radio-option item inside the feedback form.
	 *
	 * Each item consists of a radio button, its label, hidden key/label inputs,
	 * and an optional follow-up textarea.
	 *
	 * @since 3.9.5
	 *
	 * @param array $reason {
	 *     Associative array describing a single deactivation reason.
	 *
	 *     @type string $value       The radio button value / display label.
	 *     @type string $key         Programmatic key sent with the AJAX request.
	 *     @type string $label       Human-readable label sent with the AJAX request.
	 *     @type string $placeholder Placeholder text for the follow-up textarea.
	 * }
	 * @return void
	 */
	private function render_reason_item( array $reason ) {
		$value       = esc_attr( $reason['value'] );
		$placeholder = esc_attr( $reason['placeholder'] );
		$show_textarea = 'temporary_deactivation' === $reason['key'];
		?>
		<div class="radio-item">
			<label class="radio-option">
				<input
					type="radio"
					name="reason"
					value="<?php echo $value; ?>"
					class="form-control-radio"
				>
				<span><?php echo esc_html( $reason['value'] ); ?></span>
			</label>
			<input type="hidden" class="reason-key"   value="<?php echo esc_attr( $reason['key'] ); ?>" />
			<input type="hidden" class="reason-label" value="<?php echo esc_attr( $reason['label'] ); ?>" />
			<?php if ( !$show_textarea ) : ?>
				<textarea
					class="radio-feedback"
					name="feedback_<?php echo $value; ?>"
					placeholder="<?php echo $placeholder; ?>"
					rows="2"
				></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Output the modal footer containing the action buttons.
	 *
	 * @since 3.9.5
	 *
	 * @return void
	 */
	private function render_modal_footer() {
		?>
		<div class="elementskit-modal-footer">
			<button type="button" class="btn btn-secondary elementskit-modal-skip ekit-modal-btn" data-deactivate-link="">
				<?php esc_html_e( 'Skip & Deactivate', 'elementskit-lite' ); ?>
			</button>
			<button type="submit" class="btn btn-primary elementskit-modal-submit ekit-modal-btn">
				<?php esc_html_e( 'Submit & Deactivate', 'elementskit-lite' ); ?>
			</button>
		</div><!-- .elementskit-modal-footer -->
		<?php
	}


	/**
	 * Verify AJAX request nonce and user capabilities.
	 *
	 * Sends a JSON error response and terminates execution if either check fails.
	 *
	 * @since 3.9.5
	 *
	 * @return void
	 */
	private function verify_request() {
		$nonce = isset( $_POST['elementskit_nonce'] )
			? sanitize_key( wp_unslash( $_POST['elementskit_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'elementskit-deactivation' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Security check failed', 'elementskit-lite' ) )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Insufficient permissions', 'elementskit-lite' ) )
			);
		}
	}





	/**
	 * Send collected feedback data to the ElementsKit remote API.
	 *
	 * @since 3.9.5
	 *
	 * @param array $data Associative array of feedback payload data.
	 * @return array|\WP_Error The raw HTTP response array, or a WP_Error on failure.
	 */
	private function send_feedback_data( array $data ) {
		$url = \ElementsKit_Lite::api_url() . 'plugin-unsubscribe/';
		return wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
			)
		);
	}

	/**
	 * Return the number of days the plugin has been active.
	 *
	 * Reads the `elementskit-lite_install_date` option and computes the
	 * difference between that date and the current server time.
	 *
	 * @since 3.9.5
	 *
	 * @return int Number of complete days since installation, or 0 if unknown.
	 */
	private function get_days_active() {
		$installed_time = get_option( 'elementskit-lite_install_date' );

		if ( ! $installed_time ) {
			return 0;
		}

		$installed_timestamp = strtotime( $installed_time );
		$current_time        = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		return (int) floor( ( $current_time - $installed_timestamp ) / DAY_IN_SECONDS );
	}

	/**
	 * Return the slugs of all currently active modules.
	 *
	 * @since 3.9.5
	 *
	 * @return string[] Indexed array of active module slugs.
	 */
	private function get_active_modules() {
		$module_list    = Utils::instance()->get_option( 'module_list', array() );
		$active_modules = array();

		foreach ( $module_list as $slug => $module ) {
			if ( isset( $module['status'] ) && 'active' === $module['status'] ) {
				$active_modules[] = $slug;
			}
		}

		return $active_modules;
	}

	/**
	 * Return the slugs of all currently active widgets.
	 *
	 * @since 3.9.5
	 *
	 * @return string[] Indexed array of active widget slugs.
	 */
	private function get_active_widgets() {
		$widget_list    = Utils::instance()->get_option( 'widget_list', array() );
		$active_widgets = array();

		foreach ( $widget_list as $slug => $widget ) {
			if ( isset( $widget['status'] ) && 'active' === $widget['status'] ) {
				$active_widgets[] = $slug;
			}
		}

		return $active_widgets;
	}

	/**
	 * Return the current user's license/subscription type.
	 *
	 * Possible return values:
	 * - `'pro_valid'`  – Pro plugin is active with a valid licence.
	 * - `'pro'`        – Pro plugin is installed but licence is missing or invalid.
	 * - `'free'`       – Only the Lite version is installed.
	 *
	 * @since 3.9.5
	 *
	 * @return string One of `'pro_valid'`, `'pro'`, or `'free'`.
	 */
	private function get_user_type() {
		if ( 'pro' !== \ElementsKit_Lite::package_type() ) {
			return 'free';
		}

		return 'valid' === \ElementsKit_Lite::license_status() ? 'pro_valid' : 'pro';
	}

	/**
	 * Return the admin email address stored in plugin options, if available.
	 *
	 * @since 3.9.5
	 *
	 * @return string A sanitized email address, or an empty string when not set.
	 */
	private function get_user_email() {
		$options = get_option( 'elementskit_options', array() );

		if ( empty( $options['settings']['newsletter_email'] ) ) {
			return '';
		}

		return sanitize_email( $options['settings']['newsletter_email'] );
	}


	/**
	 * Return the list of available deactivation reasons shown in the modal.
	 *
	 * Each entry is an associative array with the following keys:
	 * - `value`       (string) The user-visible radio-button label.
	 * - `key`         (string) The programmatic key sent to the API.
	 * - `label`       (string) The human-readable label sent to the API.
	 * - `placeholder` (string) Placeholder text for the follow-up textarea.
	 *
	 * @since 3.9.5
	 *
	 * @return array[] List of reason definition arrays.
	 */
	private function get_deactivation_reasons() {
		return array(
			array(
				'value'       => __( 'I no longer need the plugin', 'elementskit-lite' ),
				'key'         => 'no_longer_needed',
				'label'       => 'I no longer need the plugin',
				'placeholder' => __( 'Tell us more...', 'elementskit-lite' ),
			),
			array(
				'value'       => __( 'I found a better plugin', 'elementskit-lite' ),
				'key'         => 'found_better_plugin',
				'label'       => 'I found a better plugin',
				'placeholder' => __( 'Which plugin are you using instead?', 'elementskit-lite' ),
			),
			array(
				'value'       => __( "I couldn't get the plugin to work", 'elementskit-lite' ),
				'key'         => 'plugin_bug',
				'label'       => "I couldn't get the plugin to work",
				'placeholder' => __( 'What specific issue did you face?', 'elementskit-lite' ),
			),
			array(
				'value'       => __( "It's missing a specific feature", 'elementskit-lite' ),
				'key'         => 'missing_feature',
				'label'       => "It's missing a specific feature",
				'placeholder' => __( 'What feature do you need?', 'elementskit-lite' ),
			),
			array(
				'value'       => __( 'The plugin affects site performance', 'elementskit-lite' ),
				'key'         => 'performance_issue',
				'label'       => 'Slowing down my site',
				'placeholder' => __( 'Please share details about the performance issues you experienced.', 'elementskit-lite' ),
			),
			array(
				'value'       => __( "It's a temporary deactivation", 'elementskit-lite' ),
				'key'         => 'temporary_deactivation',
				'label'       => "It's a temporary deactivation",
				'placeholder' => __( 'When will you reactivate it?', 'elementskit-lite' ),
			),
			array(
				'value'       => __( 'Other', 'elementskit-lite' ),
				'key'         => 'other',
				'label'       => 'Other',
				'placeholder' => __( 'Please tell us why...', 'elementskit-lite' ),
			),
		);
	}
}