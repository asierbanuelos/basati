<?php
/*
Plugin Name: WPC Fly Cart for WooCommerce
Plugin URI: https://wpclever.net/
Description: WPC Fly Cart is an interactive mini cart for WooCommerce. It allows users to update product quantities or remove products without reloading the page.
Version: 5.9.9
Author: WPClever
Author URI: https://wpclever.net
Text Domain: woo-fly-cart
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.9
WC requires at least: 3.0
WC tested up to: 10.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WOOFC_VERSION' ) && define( 'WOOFC_VERSION', '5.9.9' );
! defined( 'WOOFC_LITE' ) && define( 'WOOFC_LITE', __FILE__ );
! defined( 'WOOFC_FILE' ) && define( 'WOOFC_FILE', __FILE__ );
! defined( 'WOOFC_URI' ) && define( 'WOOFC_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WOOFC_DIR' ) && define( 'WOOFC_DIR', plugin_dir_path( __FILE__ ) );
! defined( 'WOOFC_REVIEWS' ) && define( 'WOOFC_REVIEWS', 'https://wordpress.org/support/plugin/woo-fly-cart/reviews/' );
! defined( 'WOOFC_CHANGELOG' ) && define( 'WOOFC_CHANGELOG', 'https://wordpress.org/plugins/woo-fly-cart/#developers' );
! defined( 'WOOFC_DISCUSSION' ) && define( 'WOOFC_DISCUSSION', 'https://wordpress.org/support/plugin/woo-fly-cart' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WOOFC_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! class_exists( 'Walker_PageDropdown_Multiple' ) ) {
    class Walker_PageDropdown_Multiple extends Walker_PageDropdown {
        function start_el( &$output, $data_object, $depth = 0, $args = [], $current_object_id = 0 ) {
            $page = $data_object;
            $pad  = str_repeat( $args['pad'] ?? '--', $depth );

            $output .= "\t<option class=\"level-$depth\" value=\"$page->ID\"";

            if ( in_array( $page->ID, (array) $args['selected'] ) ) {
                $output .= ' selected="selected"';
            }

            $output .= '>';
            $title  = apply_filters( 'list_pages', $page->post_title, $page );
            $output .= $pad . ' ' . esc_html( $title );
            $output .= "</option>\n";
        }
    }
}

if ( ! function_exists( 'woofc_init' ) ) {
    add_action( 'plugins_loaded', 'woofc_init', 11 );

    function woofc_init() {
        if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
            add_action( 'admin_notices', 'woofc_notice_wc' );

            return null;
        }

        if ( ! class_exists( 'WPCleverWoofc' ) && class_exists( 'WC_Product' ) ) {
            class WPCleverWoofc {
                protected static $settings = [];
                protected static $localization = [];
                protected static $instance = null;

                public static function instance() {
                    if ( is_null( self::$instance ) ) {
                        self::$instance = new self();
                    }

                    return self::$instance;
                }

                function __construct() {
                    self::$settings     = (array) get_option( 'woofc_settings', [] );
                    self::$localization = (array) get_option( 'woofc_localization', [] );

                    if ( empty( self::$localization ) ) {
                        // version < 5.2
                        self::$localization = (array) get_option( 'woofc_localization', [] );
                    }

                    add_action( 'init', [ $this, 'init' ] );
                    add_action( 'wp_footer', [ $this, 'footer' ] );
                    add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
                    add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
                    add_action( 'admin_init', [ $this, 'register_settings' ] );
                    add_action( 'admin_menu', [ $this, 'admin_menu' ] );
                    add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
                    add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
                    add_filter( 'wp_nav_menu_items', [ $this, 'nav_menu_items' ], 99, 2 );
                    add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'cart_fragment' ] );
                    add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'cart_fragment' ] );
                    add_filter( 'wpcsm_locations', [ $this, 'wpcsm_locations' ] );

                    // shortcode
                    add_shortcode( 'woofc_link', [ $this, 'shortcode_cart_link' ] );
                    add_shortcode( 'woofc_cart_link', [ $this, 'shortcode_cart_link' ] );

                    // ajax
                    add_action( 'wc_ajax_woofc_update_qty', [ $this, 'ajax_update_qty' ] );
                    add_action( 'wc_ajax_woofc_remove_item', [ $this, 'ajax_remove_item' ] );
                    add_action( 'wc_ajax_woofc_undo_remove', [ $this, 'ajax_undo_remove' ] );
                    add_action( 'wc_ajax_woofc_empty_cart', [ $this, 'ajax_empty_cart' ] );

                    // nonce check
                    add_filter( 'woofc_disable_nonce_check', function ( $check, $context ) {
                        return apply_filters( 'woofc_disable_security_check', $check, $context );
                    }, 10, 2 );
                }

                function init() {
                    // load text-domain
                    load_plugin_textdomain( 'woo-fly-cart', false, basename( WOOFC_DIR ) . '/languages/' );
                }

                public static function get_settings() {
                    return apply_filters( 'woofc_get_settings', self::$settings );
                }

                public static function get_setting( $name, $default = false ) {
                    if ( ! empty( self::$settings ) ) {
                        if ( isset( self::$settings[ $name ] ) ) {
                            $setting = self::$settings[ $name ];
                        } else {
                            $setting = $default;
                        }
                    } else {
                        $setting = get_option( 'woofc_' . $name, $default );
                    }

                    return apply_filters( 'woofc_get_setting', $setting, $name, $default );
                }

                public static function localization( $key = '', $default = '' ) {
                    $str = '';

                    if ( ! empty( $key ) && ! empty( self::$localization[ $key ] ) ) {
                        $str = self::$localization[ $key ];
                    } elseif ( ! empty( $default ) ) {
                        $str = $default;
                    }

                    return apply_filters( 'woofc_localization_' . $key, $str );
                }

                function enqueue_scripts() {
                    if ( self::disable() ) {
                        return null;
                    }

                    // hint css
                    wp_enqueue_style( 'hint', WOOFC_URI . 'assets/hint/hint.min.css' );

                    // perfect scrollbar
                    if ( apply_filters( 'woofc_perfect_scrollbar', self::get_setting( 'perfect_scrollbar', 'yes' ) ) === 'yes' ) {
                        wp_enqueue_style( 'perfect-scrollbar', WOOFC_URI . 'assets/perfect-scrollbar/css/perfect-scrollbar.min.css' );
                        wp_enqueue_style( 'perfect-scrollbar-wpc', WOOFC_URI . 'assets/perfect-scrollbar/css/custom-theme.css' );
                        wp_enqueue_script( 'perfect-scrollbar', WOOFC_URI . 'assets/perfect-scrollbar/js/perfect-scrollbar.jquery.min.js', [ 'jquery' ], WOOFC_VERSION, true );
                    }

                    // slick
                    if ( ( ( apply_filters( 'woofc_slick', self::get_setting( 'suggested_carousel', 'yes' ), 'suggested' ) === 'yes' ) && ! empty( self::get_setting( 'suggested', [] ) ) ) || ( ( self::get_setting( 'upsell_funnel', 'yes' ) === 'yes' ) && class_exists( 'Wpcuf' ) && ( self::get_setting( 'upsell_funnel_carousel', 'yes' ) === 'yes' ) ) ) {
                        wp_enqueue_style( 'slick', WOOFC_URI . 'assets/slick/slick.css' );
                        wp_enqueue_script( 'slick', WOOFC_URI . 'assets/slick/slick.min.js', [ 'jquery' ], WOOFC_VERSION, true );
                    }

                    // main
                    if ( ! apply_filters( 'woofc_disable_font_icon', false ) ) {
                        wp_enqueue_style( 'woofc-fonts', WOOFC_URI . 'assets/css/fonts.css' );
                    }

                    // css
                    wp_enqueue_style( 'woofc-frontend', WOOFC_URI . 'assets/css/frontend.css', [], WOOFC_VERSION );
                    $color      = self::get_setting( 'color', '#cc6055' );
                    $bg_image   = self::get_setting( 'bg_image', '' ) !== '' ? wp_get_attachment_url( self::get_setting( 'bg_image', '' ) ) : '';
                    $inline_css = ".woofc-area.woofc-style-01 .woofc-inner, .woofc-area.woofc-style-03 .woofc-inner, .woofc-area.woofc-style-02 .woofc-area-bot .woofc-action .woofc-action-inner > div a:hover, .woofc-area.woofc-style-04 .woofc-area-bot .woofc-action .woofc-action-inner > div a:hover {
                            background-color: {$color};
                        }

                        .woofc-area.woofc-style-01 .woofc-area-bot .woofc-action .woofc-action-inner > div a, .woofc-area.woofc-style-02 .woofc-area-bot .woofc-action .woofc-action-inner > div a, .woofc-area.woofc-style-03 .woofc-area-bot .woofc-action .woofc-action-inner > div a, .woofc-area.woofc-style-04 .woofc-area-bot .woofc-action .woofc-action-inner > div a {
                            outline: none;
                            color: {$color};
                        }

                        .woofc-area.woofc-style-02 .woofc-area-bot .woofc-action .woofc-action-inner > div a, .woofc-area.woofc-style-04 .woofc-area-bot .woofc-action .woofc-action-inner > div a {
                            border-color: {$color};
                        }

                        .woofc-area.woofc-style-05 .woofc-inner{
                            background-color: {$color};
                            background-image: url('{$bg_image}');
                            background-size: cover;
                            background-position: center;
                            background-repeat: no-repeat;
                        }
                        
                        .woofc-count span {
                            background-color: {$color};
                        }";
                    wp_add_inline_style( 'woofc-frontend', $inline_css );

                    $show_cart = 'no';
                    $requests  = apply_filters( 'woofc_auto_show_requests', [
                            'add-to-cart',
                            'product_added_to_cart',
                            'added_to_cart',
                            'set_cart',
                            'fill_cart'
                    ] );

                    if ( is_array( $requests ) && ! empty( $requests ) ) {
                        foreach ( $requests as $request ) {
                            if ( isset( $_REQUEST[ $request ] ) ) {
                                $show_cart = 'yes';
                                break;
                            }
                        }
                    }

                    $show_checkout     = 'no';
                    $checkout_requests = apply_filters( 'woofc_auto_show_checkout_requests', [] );

                    if ( is_array( $checkout_requests ) && ! empty( $checkout_requests ) ) {
                        foreach ( $checkout_requests as $checkout_request ) {
                            if ( isset( $_REQUEST[ $checkout_request ] ) ) {
                                $show_checkout = 'yes';
                                break;
                            }
                        }
                    }

                    // js
                    wp_enqueue_script( 'woofc-frontend', WOOFC_URI . 'assets/js/frontend.js', [
                            'jquery',
                            'wc-cart-fragments'
                    ], WOOFC_VERSION, true );
                    wp_localize_script( 'woofc-frontend', 'woofc_vars', apply_filters( 'woofc_vars', [
                                    'wc_ajax_url'             => WC_AJAX::get_endpoint( '%%endpoint%%' ),
                                    'nonce'                   => wp_create_nonce( 'woofc-security' ),
                                    'scrollbar'               => self::get_setting( 'perfect_scrollbar', 'yes' ),
                                    'auto_show'               => self::get_setting( 'auto_show_ajax', 'yes' ),
                                    'auto_show_normal'        => self::get_setting( 'auto_show_normal', 'yes' ),
                                    'show_cart'               => esc_attr( $show_cart ),
                                    'show_checkout'           => esc_attr( $show_checkout ),
                                    'delay'                   => (int) apply_filters( 'woofc_delay', 300 ),
                                    'undo_remove'             => self::get_setting( 'undo_remove', 'yes' ),
                                    'confirm_remove'          => self::get_setting( 'confirm_remove', 'no' ),
                                    'instant_checkout'        => self::get_setting( 'instant_checkout', 'no' ),
                                    'instant_checkout_open'   => self::get_setting( 'instant_checkout_open', 'no' ),
                                    'confirm_empty'           => self::get_setting( 'confirm_empty', 'no' ),
                                    'confirm_empty_text'      => self::localization( 'empty_confirm', esc_html__( 'Do you want to empty the cart?', 'woo-fly-cart' ) ),
                                    'confirm_remove_text'     => self::localization( 'remove_confirm', esc_html__( 'Do you want to remove this item?', 'woo-fly-cart' ) ),
                                    'undo_remove_text'        => self::localization( 'remove_undo', esc_html__( 'Undo?', 'woo-fly-cart' ) ),
                                    'removed_text'            => self::localization( 'removed', /* translators: product */ esc_html__( '%s was removed.', 'woo-fly-cart' ) ),
                                    'manual_show'             => self::get_setting( 'manual_show', '' ),
                                    'reload'                  => self::get_setting( 'reload', 'no' ),
                                    'suggested_carousel'      => apply_filters( 'woofc_slick', self::get_setting( 'suggested_carousel', 'yes' ), 'suggested' ) === 'yes',
                                    'save_for_later_carousel' => apply_filters( 'woofc_slick', self::get_setting( 'save_for_later_carousel', 'yes' ), 'save_for_later' ) === 'yes',
                                    'upsell_funnel_carousel'  => self::get_setting( 'upsell_funnel_carousel', 'yes' ) === 'yes',
                                    'slick_params'            => apply_filters( 'woofc_slick_params', json_encode( apply_filters( 'woofc_slick_params_arr', [
                                            'slidesToShow'   => 1,
                                            'slidesToScroll' => 1,
                                            'dots'           => true,
                                            'arrows'         => false,
                                            'autoplay'       => false,
                                            'autoplaySpeed'  => 3000,
                                            'rtl'            => is_rtl()
                                    ] ) ) ),
                                    'is_cart'                 => is_cart(),
                                    'is_checkout'             => is_checkout(),
                                    'cart_url'                => self::disable() ? wc_get_cart_url() : '',
                                    'hide_count_empty'        => self::get_setting( 'count_hide_empty', 'no' ),
                                    'wc_checkout_js'          => defined( 'WC_PLUGIN_FILE' ) ? plugins_url( 'assets/js/frontend/checkout.js', WC_PLUGIN_FILE ) : '',
                            ] )
                    );
                }

                function admin_enqueue_scripts( $hook ) {
                    if ( apply_filters( 'woofc_ignore_backend_scripts', false, $hook ) ) {
                        return null;
                    }

                    wp_enqueue_style( 'woofc-backend', WOOFC_URI . 'assets/css/backend.css', [], WOOFC_VERSION );

                    if ( strpos( $hook, 'woofc' ) ) {
                        add_thickbox();
                        wp_enqueue_media();
                        wp_enqueue_style( 'wp-color-picker' );
                        wp_enqueue_style( 'fonticonpicker', WOOFC_URI . 'assets/fonticonpicker/css/jquery.fonticonpicker.css' );
                        wp_enqueue_script( 'fonticonpicker', WOOFC_URI . 'assets/fonticonpicker/js/jquery.fonticonpicker.min.js', [ 'jquery' ] );
                        wp_enqueue_style( 'woofc-fonts', WOOFC_URI . 'assets/css/fonts.css' );
                        wp_enqueue_script( 'woofc-backend', WOOFC_URI . 'assets/js/backend.js', [
                                'jquery',
                                'wp-color-picker'
                        ] );
                    }
                }

                function action_links( $links, $file ) {
                    static $plugin;

                    if ( ! isset( $plugin ) ) {
                        $plugin = plugin_basename( __FILE__ );
                    }

                    if ( $plugin === $file ) {
                        $settings             = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woofc&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'woo-fly-cart' ) . '</a>';
                        $links['wpc-premium'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woofc&tab=premium' ) ) . '">' . esc_html__( 'Premium Version', 'woo-fly-cart' ) . '</a>';
                        array_unshift( $links, $settings );
                    }

                    return (array) $links;
                }

                function row_meta( $links, $file ) {
                    static $plugin;

                    if ( ! isset( $plugin ) ) {
                        $plugin = plugin_basename( __FILE__ );
                    }

                    if ( $plugin === $file ) {
                        $row_meta = [
                                'support' => '<a href="' . esc_url( WOOFC_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'woo-fly-cart' ) . '</a>',
                        ];

                        return array_merge( $links, $row_meta );
                    }

                    return (array) $links;
                }

                function register_settings() {
                    // settings
                    register_setting( 'woofc_settings', 'woofc_settings', [
                            'type'              => 'array',
                            'sanitize_callback' => [ $this, 'sanitize_array' ],
                    ] );

                    // localization
                    register_setting( 'woofc_localization', 'woofc_localization', [
                            'type'              => 'array',
                            'sanitize_callback' => [ $this, 'sanitize_array' ],
                    ] );
                }

                function admin_menu() {
                    add_submenu_page( 'wpclever', esc_html__( 'WPC Fly Cart', 'woo-fly-cart' ), esc_html__( 'Fly Cart', 'woo-fly-cart' ), 'manage_options', 'wpclever-woofc', [
                            $this,
                            'admin_menu_content'
                    ] );
                }

                function admin_menu_content() {
                    $active_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
                    ?>
                    <div class="wpclever_settings_page wrap">
                        <div class="wpclever_settings_page_header">
                            <a class="wpclever_settings_page_header_logo" href="https://wpclever.net/"
                               target="_blank" title="Visit wpclever.net"></a>
                            <div class="wpclever_settings_page_header_text">
                                <div class="wpclever_settings_page_title"><?php echo esc_html__( 'WPC Fly Cart', 'woo-fly-cart' ) . ' ' . esc_html( WOOFC_VERSION ) . ' ' . ( defined( 'WOOFC_PREMIUM' ) ? '<span class="premium" style="display: none">' . esc_html__( 'Premium', 'woo-fly-cart' ) . '</span>' : '' ); ?></div>
                                <div class="wpclever_settings_page_desc about-text">
                                    <p>
                                        <?php printf( /* translators: stars */ esc_html__( 'Thank you for using our plugin! If you are satisfied, please reward it a full five-star %s rating.', 'woo-fly-cart' ), '<span style="color:#ffb900">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' ); ?>
                                        <br/>
                                        <a href="<?php echo esc_url( WOOFC_REVIEWS ); ?>"
                                           target="_blank"><?php esc_html_e( 'Reviews', 'woo-fly-cart' ); ?></a> |
                                        <a href="<?php echo esc_url( WOOFC_CHANGELOG ); ?>"
                                           target="_blank"><?php esc_html_e( 'Changelog', 'woo-fly-cart' ); ?></a> |
                                        <a href="<?php echo esc_url( WOOFC_DISCUSSION ); ?>"
                                           target="_blank"><?php esc_html_e( 'Discussion', 'woo-fly-cart' ); ?></a>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <h2></h2>
                        <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) { ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'woo-fly-cart' ); ?></p>
                            </div>
                        <?php } ?>
                        <div class="wpclever_settings_page_nav">
                            <h2 class="nav-tab-wrapper">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woofc&tab=settings' ) ); ?>"
                                   class="<?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
                                    <?php esc_html_e( 'Settings', 'woo-fly-cart' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woofc&tab=localization' ) ); ?>"
                                   class="<?php echo esc_attr( $active_tab === 'localization' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
                                    <?php esc_html_e( 'Localization', 'woo-fly-cart' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woofc&tab=premium' ) ); ?>"
                                   class="<?php echo esc_attr( $active_tab === 'premium' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>"
                                   style="color: #c9356e">
                                    <?php esc_html_e( 'Premium Version', 'woo-fly-cart' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>"
                                   class="nav-tab">
                                    <?php esc_html_e( 'Essential Kit', 'woo-fly-cart' ); ?>
                                </a>
                            </h2>
                        </div>
                        <div class="wpclever_settings_page_content">
                            <?php if ( $active_tab === 'settings' ) {
                                $default_style           = apply_filters( 'woofc_default_style', '01' );
                                $auto_show_ajax          = self::get_setting( 'auto_show_ajax', 'yes' );
                                $auto_show_normal        = self::get_setting( 'auto_show_normal', 'yes' );
                                $reverse_items           = self::get_setting( 'reverse_items', 'yes' );
                                $overlay_layer           = self::get_setting( 'overlay_layer', 'yes' );
                                $perfect_scrollbar       = self::get_setting( 'perfect_scrollbar', 'yes' );
                                $position                = self::get_setting( 'position', '05' );
                                $effect                  = self::get_setting( 'effect', 'yes' );
                                $rounded                 = self::get_setting( 'rounded', 'no' );
                                $style                   = self::get_setting( 'style', $default_style );
                                $close                   = self::get_setting( 'close', 'yes' );
                                $link                    = self::get_setting( 'link', 'yes' );
                                $price                   = self::get_setting( 'price', 'price' );
                                $data                    = self::get_setting( 'data', 'no' );
                                $estimated_delivery_date = self::get_setting( 'estimated_delivery_date', 'no' );
                                $plus_minus              = self::get_setting( 'plus_minus', 'yes' );
                                $remove                  = self::get_setting( 'remove', 'yes' );
                                $save_for_later          = self::get_setting( 'save_for_later', 'yes' );
                                $subtotal                = self::get_setting( 'subtotal', 'yes' );
                                $coupon                  = self::get_setting( 'coupon', 'no' );
                                $coupon_listing          = self::get_setting( 'coupon_listing', 'no' );
                                $shipping_cost           = self::get_setting( 'shipping_cost', 'no' );
                                $shipping_calculator     = self::get_setting( 'shipping_calculator', 'no' );
                                $free_shipping_bar       = self::get_setting( 'free_shipping_bar', 'yes' );
                                $tax                     = self::get_setting( 'tax', 'no' );
                                $total                   = self::get_setting( 'total', 'yes' );
                                $buttons                 = self::get_setting( 'buttons', '01' );
                                $instant_checkout        = self::get_setting( 'instant_checkout', 'no' );
                                $instant_checkout_open   = self::get_setting( 'instant_checkout_open', 'no' );
                                $suggested               = self::get_setting( 'suggested', [] );
                                $suggested_empty         = self::get_setting( 'suggested_empty', 'no' );
                                $suggested_carousel      = self::get_setting( 'suggested_carousel', 'yes' );
                                $upsell_funnel           = self::get_setting( 'upsell_funnel', 'yes' );
                                $upsell_funnel_carousel  = self::get_setting( 'upsell_funnel_carousel', 'yes' );
                                $empty                   = self::get_setting( 'empty', 'no' );
                                $confirm_empty           = self::get_setting( 'confirm_empty', 'no' );
                                $share                   = self::get_setting( 'share', 'yes' );
                                $continue                = self::get_setting( 'continue', 'yes' );
                                $confirm_remove          = self::get_setting( 'confirm_remove', 'no' );
                                $undo_remove             = self::get_setting( 'undo_remove', 'yes' );
                                $reload                  = self::get_setting( 'reload', 'no' );
                                $hide_pages              = self::get_setting( 'hide_pages', [] );
                                $count                   = self::get_setting( 'count', 'yes' );
                                $count_position          = self::get_setting( 'count_position', 'bottom-left' );
                                $count_hide_empty        = self::get_setting( 'count_hide_empty', 'no' );
                                ?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th><?php esc_html_e( 'General', 'woo-fly-cart' ); ?></th>
                                            <td><?php esc_html_e( 'General settings for the fly cart.', 'woo-fly-cart' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Open on AJAX add to cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[auto_show_ajax]">
                                                        <option value="yes" <?php selected( $auto_show_ajax, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $auto_show_ajax, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php printf( /* translators: link */ esc_html__( 'The fly cart will be opened immediately after whenever click to AJAX Add to cart buttons? See %1$s "Add to cart behaviour" setting %2$s', 'woo-fly-cart' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=products&section=display' ) ) . '" target="_blank">', '</a>.' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Open on normal add to cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[auto_show_normal]">
                                                        <option value="yes" <?php selected( $auto_show_normal, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $auto_show_normal, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'The fly cart will be opened immediately after whenever click to normal Add to cart buttons (AJAX is not enable) or Add to cart button in single product page?', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Reverse items', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[reverse_items]">
                                                        <option value="yes" <?php selected( $reverse_items, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $reverse_items, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Overlay layer', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[overlay_layer]">
                                                        <option value="yes" <?php selected( $overlay_layer, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $overlay_layer, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'If you hide the overlay layer, the buyer still can work on your site when the fly cart is opening.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Use perfect-scrollbar', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[perfect_scrollbar]">
                                                        <option value="yes" <?php selected( $perfect_scrollbar, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $perfect_scrollbar, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php printf( /* translators: link */ esc_html__( 'Read more about %s', 'woo-fly-cart' ), '<a href="https://github.com/mdbootstrap/perfect-scrollbar" target="_blank">perfect-scrollbar</a>' ); ?>.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Position', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[position]">
                                                        <option value="01" <?php selected( $position, '01' ); ?>><?php esc_html_e( 'Right', 'woo-fly-cart' ); ?></option>
                                                        <option value="02" <?php selected( $position, '02' ); ?>><?php esc_html_e( 'Left', 'woo-fly-cart' ); ?></option>
                                                        <option value="03" <?php selected( $position, '03' ); ?>><?php esc_html_e( 'Top', 'woo-fly-cart' ); ?></option>
                                                        <option value="04" <?php selected( $position, '04' ); ?>><?php esc_html_e( 'Bottom', 'woo-fly-cart' ); ?></option>
                                                        <option value="05" <?php selected( $position, '05' ); ?>><?php esc_html_e( 'Center', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Effect', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[effect]">
                                                        <option value="yes" <?php selected( $effect, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $effect, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Enable/disable slide effect.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Style', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <?php
                                                $styles = apply_filters( 'woofc_styles', [
                                                        '01' => esc_html__( 'Color background', 'woo-fly-cart' ),
                                                        '02' => esc_html__( 'White background', 'woo-fly-cart' ),
                                                        '03' => esc_html__( 'Color background, no thumbnail', 'woo-fly-cart' ),
                                                        '04' => esc_html__( 'White background, no thumbnail', 'woo-fly-cart' ),
                                                        '05' => esc_html__( 'Background image', 'woo-fly-cart' ),
                                                ] );

                                                echo '<select name="woofc_settings[style]" class="woofc_style">';

                                                foreach ( $styles as $k => $s ) {
                                                    echo '<option value="' . esc_attr( $k ) . '" ' . selected( $style, $k, false ) . '>' . esc_html( $s ) . '</option>';
                                                }

                                                echo '</select>';
                                                ?>
                                            </td>
                                        </tr>
                                        <tr class="woofc_hide_if_style woofc_show_if_style_01 woofc_show_if_style_02 woofc_show_if_style_03 woofc_show_if_style_04">
                                            <th><?php esc_html_e( 'Color', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label for="woofc_color"></label><input type="text"
                                                                                        name="woofc_settings[color]"
                                                                                        id="woofc_color"
                                                                                        value="<?php echo self::get_setting( 'color', '#cc6055' ); ?>"
                                                                                        class="woofc_color_picker"/>
                                                <span class="description"><?php printf( /* translators: color */ esc_html__( 'Background or text color of selected style, default %s', 'woo-fly-cart' ), '<code>#cc6055</code>' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="woofc_hide_if_style woofc_show_if_style_05">
                                            <th><?php esc_html_e( 'Background image', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <div class="woofc_image_preview" id="woofc_image_preview">
                                                    <?php if ( self::get_setting( 'bg_image', '' ) !== '' ) {
                                                        echo '<img src="' . wp_get_attachment_url( self::get_setting( 'bg_image', '' ) ) . '"/>';
                                                    } ?>
                                                </div>
                                                <input id="woofc_upload_image_button" type="button" class="button"
                                                       value="<?php esc_html_e( 'Upload image', 'woo-fly-cart' ); ?>"/>
                                                <input type="hidden" name="woofc_settings[bg_image]"
                                                       id="woofc_image_attachment_url"
                                                       value="<?php echo self::get_setting( 'bg_image', '' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Rounded', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[rounded]">
                                                        <option value="yes" <?php selected( $rounded, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $rounded, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Enable/disable rounded style for elements.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Close button', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[close]">
                                                        <option value="yes" <?php selected( $close, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $close, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the close button.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Link to individual product', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[link]">
                                                        <option value="yes" <?php selected( $link, 'yes' ); ?>><?php esc_html_e( 'Yes, open in the same tab', 'woo-fly-cart' ); ?></option>
                                                        <option value="yes_blank" <?php selected( $link, 'yes_blank' ); ?>><?php esc_html_e( 'Yes, open in the new tab', 'woo-fly-cart' ); ?></option>
                                                        <option value="yes_popup" <?php selected( $link, 'yes_popup' ); ?>><?php esc_html_e( 'Yes, open quick view popup', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $link, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">If you choose "Open quick view popup", please install <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-quick-view&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Smart Quick View">WPC Smart Quick View</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Item data', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[data]">
                                                        <option value="yes" <?php selected( $data, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $data, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the item data under title.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Item price', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[price]">
                                                        <option value="no" <?php selected( $price, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                        <option value="price" <?php selected( $price, 'price' ); ?>><?php esc_html_e( 'Price', 'woo-fly-cart' ); ?></option>
                                                        <option value="subtotal" <?php selected( $price, 'subtotal' ); ?>><?php esc_html_e( 'Subtotal', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the item price or subtotal under title.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Item estimated delivery date', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[estimated_delivery_date]">
                                                        <option value="yes" <?php selected( $estimated_delivery_date, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $estimated_delivery_date, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the item estimated delivery date.', 'woo-fly-cart' ); ?> Please install <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-estimated-delivery-date&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Estimated Delivery Date">WPC Estimated Delivery Date</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Plus/minus button', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[plus_minus]">
                                                        <option value="yes" <?php selected( $plus_minus, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $plus_minus, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the plus/minus button.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Item remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[remove]">
                                                        <option value="yes" <?php selected( $remove, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $remove, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the remove button for each item.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Save for later', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[save_for_later]">
                                                        <option value="yes" <?php selected( $save_for_later, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $save_for_later, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">Show/hide the save for later button for each product. If enable this option, please install and activate <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wc-save-for-later&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Save For Later">WPC Save For Later</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Subtotal', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[subtotal]">
                                                        <option value="yes" <?php selected( $subtotal, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $subtotal, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Coupon', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[coupon]">
                                                        <option value="yes" <?php selected( $coupon, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $coupon, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description" style="color: #c9356e">This feature is available for Premium Version only.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Coupon listing', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[coupon_listing]">
                                                        <option value="yes" <?php selected( $coupon_listing, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $coupon_listing, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">If enable this option, please install and activate <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-coupon-listing&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Coupon Listing">WPC Coupon Listing</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Shipping cost', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[shipping_cost]">
                                                        <option value="yes" <?php selected( $shipping_cost, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $shipping_cost, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description" style="color: #c9356e">This feature is available for Premium Version only.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Shipping calculator', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[shipping_calculator]">
                                                        <option value="yes" <?php selected( $shipping_calculator, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $shipping_calculator, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description" style="color: #c9356e">This feature is available for Premium Version only.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Free shipping bar', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[free_shipping_bar]">
                                                        <option value="yes" <?php selected( $free_shipping_bar, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $free_shipping_bar, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">If enable this option, please install and activate <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-free-shipping-bar&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Free Shipping Bar">WPC Free Shipping Bar</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Tax', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[tax]">
                                                        <option value="yes" <?php selected( $tax, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $tax, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span
                                                        class="description"><?php esc_html_e( 'It requires enabling tax and excluding tax from display prices during cart and checkout.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Total', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[total]">
                                                        <option value="yes" <?php selected( $total, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $total, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Action buttons', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[buttons]">
                                                        <option value="01" <?php selected( $buttons, '01' ); ?>><?php esc_html_e( 'Cart & Checkout', 'woo-fly-cart' ); ?></option>
                                                        <option value="02" <?php selected( $buttons, '02' ); ?>><?php esc_html_e( 'Cart only', 'woo-fly-cart' ); ?></option>
                                                        <option value="03" <?php selected( $buttons, '03' ); ?>><?php esc_html_e( 'Checkout only', 'woo-fly-cart' ); ?></option>
                                                        <option value="hide" <?php selected( $buttons, 'hide' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Instant checkout', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <select name="woofc_settings[instant_checkout]"
                                                            class="woofc_instant_checkout">
                                                        <option value="yes" <?php selected( $instant_checkout, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $instant_checkout, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'If enable this option, buyer can checkout directly on the fly cart.', 'woo-fly-cart' ); ?></span>
                                                <span class="description" style="color: #c9356e">This feature is available for Premium Version only.</span>
                                            </td>
                                        </tr>
                                        <tr class="woofc_hide_if_instant_checkout woofc_show_if_instant_checkout_yes">
                                            <th><?php esc_html_e( 'Open instant checkout immediately', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[instant_checkout_open]">
                                                        <option value="yes" <?php selected( $instant_checkout_open, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $instant_checkout_open, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Open instant checkout form immediately after adding a product to the cart.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Suggested products', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <?php
                                                // backward compatibility before 5.2.2
                                                if ( ! is_array( $suggested ) ) {
                                                    switch ( (string) $suggested ) {
                                                        case 'cross_sells':
                                                            $suggested = [ 'cross_sells' ];
                                                            break;
                                                        case 'related':
                                                            $suggested = [ 'related' ];
                                                            break;
                                                        case 'both':
                                                            $suggested = [ 'related', 'cross_sells' ];
                                                            break;
                                                        case 'none':
                                                            $suggested = [];
                                                            break;
                                                        default:
                                                            $suggested = [];
                                                    }
                                                }
                                                ?>
                                                <ul>
                                                    <li>
                                                        <label><input type="checkbox" name="woofc_settings[suggested][]"
                                                                      value="related" <?php echo esc_attr( in_array( 'related', $suggested ) ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Related', 'woo-fly-cart' ); ?>
                                                        </label></li>
                                                    <li>
                                                        <label><input type="checkbox" name="woofc_settings[suggested][]"
                                                                      value="up_sells" <?php echo esc_attr( in_array( 'up_sells', $suggested ) ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Upsells', 'woo-fly-cart' ); ?>
                                                        </label></li>
                                                    <li>
                                                        <label><input type="checkbox" name="woofc_settings[suggested][]"
                                                                      value="cross_sells" <?php echo esc_attr( in_array( 'cross_sells', $suggested ) ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Cross-sells', 'woo-fly-cart' ); ?>
                                                        </label></li>
                                                    <li>
                                                        <label><input type="checkbox" name="woofc_settings[suggested][]"
                                                                      value="wishlist" <?php echo esc_attr( in_array( 'wishlist', $suggested ) ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Wishlist', 'woo-fly-cart' ); ?>
                                                        </label> <span class="description">(from
                                                            <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-wishlist&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                               class="thickbox" title="WPC Smart Wishlist">WPC Smart Wishlist</a>)</span>
                                                    </li>
                                                    <li>
                                                        <label><input type="checkbox" name="woofc_settings[suggested][]"
                                                                      value="compare" <?php echo esc_attr( in_array( 'compare', $suggested ) ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Compare', 'woo-fly-cart' ); ?>
                                                        </label> <span class="description">(from
                                                        <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-compare&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                           class="thickbox"
                                                           title="WPC Smart Compare">WPC Smart Compare</a>)</span>
                                                    </li>
                                                </ul>
                                                <span class="description">You can use
													<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-custom-related-products&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                       class="thickbox" title="WPC Custom Related Products">WPC Custom Related Products</a> or
														<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-smart-linked-products&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                           class="thickbox" title="WPC Smart Linked Products">WPC Smart Linked Products</a> plugin to configure related/upsells/cross-sells in bulk with smart conditions.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Suggested for empty cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[suggested_empty]">
                                                        <option value="no" <?php selected( $suggested_empty, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                        <option value="recent" <?php selected( $suggested_empty, 'recent' ); ?>><?php esc_html_e( 'Recent products', 'woo-fly-cart' ); ?></option>
                                                        <option value="onsale" <?php selected( $suggested_empty, 'onsale' ); ?>><?php esc_html_e( 'On-sale products', 'woo-fly-cart' ); ?></option>
                                                        <option value="featured" <?php selected( $suggested_empty, 'featured' ); ?>><?php esc_html_e( 'Featured products', 'woo-fly-cart' ); ?></option>
                                                        <option value="random" <?php selected( $suggested_empty, 'random' ); ?>><?php esc_html_e( 'Random products', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Suggested products limit', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="number" min="1" step="1"
                                                           name="woofc_settings[suggested_limit]"
                                                           value="<?php echo esc_attr( self::get_setting( 'suggested_limit', 10 ) ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Suggested products carousel', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[suggested_carousel]">
                                                        <option value="yes" <?php selected( $suggested_carousel, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $suggested_carousel, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Upsell funnel products', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[upsell_funnel]">
                                                        <option value="yes" <?php selected( $upsell_funnel, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $upsell_funnel, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">Show upsell funnel products from <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-smart-upsell-funnel&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Smart Upsell Funnel">WPC Smart Upsell Funnel</a>.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Upsell funnel products carousel', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[upsell_funnel_carousel]">
                                                        <option value="yes" <?php selected( $upsell_funnel_carousel, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $upsell_funnel_carousel, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Empty cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[empty]">
                                                        <option value="yes" <?php selected( $empty, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $empty, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the empty cart button under the product list.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Confirm empty', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[confirm_empty]">
                                                        <option value="yes" <?php selected( $confirm_empty, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $confirm_empty, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Enable/disable confirm before emptying the cart.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Share cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[share]">
                                                        <option value="yes" <?php selected( $share, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $share, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label> <span class="description">If enable this option, please install and activate <a
                                                            href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wpc-share-cart&TB_iframe=true&width=800&height=550' ) ); ?>"
                                                            class="thickbox" title="WPC Share Cart">WPC Share Cart</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Continue shopping', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[continue]">
                                                        <option value="yes" <?php selected( $continue, 'yes' ); ?>><?php esc_html_e( 'Show', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $continue, 'no' ); ?>><?php esc_html_e( 'Hide', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Show/hide the continue shopping button at the end of fly cart.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Continue shopping URL', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="url" class="regular-text code"
                                                           name="woofc_settings[continue_url]"
                                                           value="<?php echo self::get_setting( 'continue_url', '' ); ?>"/>
                                                </label>
                                                <span class="description"><?php esc_html_e( 'Custom URL for "continue shopping" button. By default, only close the fly cart when clicking on this button.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Confirm remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[confirm_remove]">
                                                        <option value="yes" <?php selected( $confirm_remove, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $confirm_remove, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Enable/disable confirm before removing a product.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Undo remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[undo_remove]">
                                                        <option value="yes" <?php selected( $undo_remove, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $undo_remove, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Enable/disable undo after removing a product.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Reload the cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[reload]">
                                                        <option value="yes" <?php selected( $reload, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $reload, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'The cart will be reloaded when opening the page? If you use the cache for your site, please turn on this option.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Hide on pages', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <?php
                                                if ( isset( self::$settings['hide_cart_checkout'] ) && ( self::$settings['hide_cart_checkout'] === 'yes' ) ) {
                                                    $hide_pages[] = wc_get_page_id( 'cart' );
                                                    $hide_pages[] = wc_get_page_id( 'checkout' );
                                                }

                                                $args = [
                                                        'echo'     => 0,
                                                        'name'     => 'woofc_settings[hide_pages][]',
                                                        'walker'   => new Walker_PageDropdown_Multiple(),
                                                        'selected' => $hide_pages
                                                ];
                                                echo str_replace( '<select', '<select multiple="multiple"', wp_dropdown_pages( $args ) );
                                                ?>
                                                <p class="description"><?php esc_html_e( 'Hide the fly cart on these pages.', 'woo-fly-cart' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr class="heading">
                                            <th><?php esc_html_e( 'Bubble', 'woo-fly-cart' ); ?></th>
                                            <td><?php esc_html_e( 'Settings for the bubble.', 'woo-fly-cart' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Enable', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[count]">
                                                        <option value="yes" <?php selected( $count, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $count, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Position', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[count_position]">
                                                        <option value="top-left" <?php selected( $count_position, 'top-left' ); ?>><?php esc_html_e( 'Top Left', 'woo-fly-cart' ); ?></option>
                                                        <option value="top-right" <?php selected( $count_position, 'top-right' ); ?>><?php esc_html_e( 'Top Right', 'woo-fly-cart' ); ?></option>
                                                        <option value="bottom-left" <?php selected( $count_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'woo-fly-cart' ); ?></option>
                                                        <option value="bottom-right" <?php selected( $count_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Icon', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label for="woofc_count_icon"></label><select id="woofc_count_icon"
                                                                                              name="woofc_settings[count_icon]">
                                                    <?php
                                                    for ( $i = 1; $i <= 16; $i ++ ) {
                                                        if ( self::get_setting( 'count_icon', 'woofc-icon-cart7' ) === 'woofc-icon-cart' . $i ) {
                                                            echo '<option value="woofc-icon-cart' . $i . '" selected>woofc-icon-cart' . $i . '</option>';
                                                        } else {
                                                            echo '<option value="woofc-icon-cart' . $i . '">woofc-icon-cart' . $i . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Hide if empty', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label> <select name="woofc_settings[count_hide_empty]">
                                                        <option value="yes" <?php selected( $count_hide_empty, 'yes' ); ?>><?php esc_html_e( 'Yes', 'woo-fly-cart' ); ?></option>
                                                        <option value="no" <?php selected( $count_hide_empty, 'no' ); ?>><?php esc_html_e( 'No', 'woo-fly-cart' ); ?></option>
                                                    </select> </label>
                                                <span class="description"><?php esc_html_e( 'Hide the bubble if the cart is empty?', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="heading">
                                            <th><?php esc_html_e( 'Menu', 'woo-fly-cart' ); ?></th>
                                            <td><?php esc_html_e( 'Settings for cart menu item.', 'woo-fly-cart' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Menu(s)', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <?php
                                                $nav_menus = get_terms( [
                                                        'taxonomy'   => 'nav_menu',
                                                        'hide_empty' => false,
                                                        'fields'     => 'id=>name',
                                                ] );

                                                if ( $nav_menus ) {
                                                    echo '<ul>';
                                                    $saved_menus = self::get_setting( 'menus', [] );

                                                    foreach ( $nav_menus as $nav_id => $nav_name ) {
                                                        echo '<li><label><input type="checkbox" name="woofc_settings[menus][]" value="' . esc_attr( $nav_id ) . '" ' . ( is_array( $saved_menus ) && in_array( $nav_id, $saved_menus ) ? 'checked' : '' ) . '/> ' . esc_html( $nav_name ) . '</label></li>';
                                                    }

                                                    echo '</ul>';
                                                } else {
                                                    echo '<p>' . esc_html__( 'Haven\'t any menu yet. Please go to Appearance > Menus to create one.', 'woo-fly-cart' ) . '</p>';
                                                }
                                                ?>
                                                <span class="description"><?php esc_html_e( 'Choose the menu(s) you want to add the cart at the end.', 'woo-fly-cart' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Custom menu', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_settings[manual_show]"
                                                           value="<?php echo self::get_setting( 'manual_show', '' ); ?>"
                                                           placeholder="<?php esc_attr_e( 'button class or id', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                                <span class="description"><?php printf( /* translators: selector */ esc_html__( 'The class or id of the custom menu. When clicking on it, the fly cart will show up. Example %1$s or %2$s', 'woo-fly-cart' ), '<code>.fly-cart-btn</code>', '<code>#fly-cart-btn</code>' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
                                                <?php settings_fields( 'woofc_settings' ); ?><?php submit_button(); ?>
                                                <a style="display: none;" class="wpclever_export"
                                                   data-key="woofc_settings"
                                                   data-name="settings"
                                                   href="#"><?php esc_html_e( 'import / export', 'woo-fly-cart' ); ?></a>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
                            <?php } elseif ( $active_tab === 'localization' ) { ?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th scope="row"><?php esc_html_e( 'Localization', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <?php esc_html_e( 'Leave blank to use the default text and its equivalent translation in multiple languages.', 'woo-fly-cart' ); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Cart heading', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[heading]"
                                                           value="<?php echo esc_attr( self::localization( 'heading' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Shopping cart', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Close', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[close]"
                                                           value="<?php echo esc_attr( self::localization( 'close' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Close', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[remove]"
                                                           value="<?php echo esc_attr( self::localization( 'remove' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Remove', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Confirm remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[remove_confirm]"
                                                           value="<?php echo esc_attr( self::localization( 'remove_confirm' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Do you want to remove this item?', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Undo remove', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[remove_undo]"
                                                           value="<?php echo esc_attr( self::localization( 'remove_undo' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Undo?', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Removed', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[removed]"
                                                           value="<?php echo esc_attr( self::localization( 'removed' ) ); ?>"
                                                           placeholder="<?php /* translators: product */
                                                           esc_attr_e( '%s was removed.', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Empty cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[empty]"
                                                           value="<?php echo esc_attr( self::localization( 'empty' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Empty cart', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Confirm empty', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[empty_confirm]"
                                                           value="<?php echo esc_attr( self::localization( 'empty_confirm' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Do you want to empty the cart?', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Share cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[share]"
                                                           value="<?php echo esc_attr( self::localization( 'share' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Share cart', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Subtotal', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[subtotal]"
                                                           value="<?php echo esc_attr( self::localization( 'subtotal' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Subtotal', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Coupon code', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[coupon_code]"
                                                           value="<?php echo esc_attr( self::localization( 'coupon_code' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Coupon code', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Coupon apply', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[coupon_apply]"
                                                           value="<?php echo esc_attr( self::localization( 'coupon_apply' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Apply', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Shipping', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[shipping]"
                                                           value="<?php echo esc_attr( self::localization( 'shipping' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Shipping', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Total', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[total]"
                                                           value="<?php echo esc_attr( self::localization( 'total' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Total', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Cart', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[cart]"
                                                           value="<?php echo esc_attr( self::localization( 'cart' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Cart', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Checkout', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[checkout]"
                                                           value="<?php echo esc_attr( self::localization( 'checkout' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Checkout', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Continue shopping', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[continue]"
                                                           value="<?php echo esc_attr( self::localization( 'continue' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'Continue shopping', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Suggested products', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[suggested]"
                                                           value="<?php echo esc_attr( self::localization( 'suggested' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'You may be interested in&hellip;', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'There are no products', 'woo-fly-cart' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="text" class="regular-text"
                                                           name="woofc_localization[no_products]"
                                                           value="<?php echo esc_attr( self::localization( 'no_products' ) ); ?>"
                                                           placeholder="<?php esc_attr_e( 'There are no products in the cart!', 'woo-fly-cart' ); ?>"/>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
                                                <?php settings_fields( 'woofc_localization' ); ?><?php submit_button(); ?>
                                                <a style="display: none;" class="wpclever_export"
                                                   data-key="woofc_localization"
                                                   data-name="settings"
                                                   href="#"><?php esc_html_e( 'import / export', 'woo-fly-cart' ); ?></a>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
                            <?php } elseif ( $active_tab === 'premium' ) { ?>
                                <div class="wpclever_settings_page_content_text">
                                    <p>Get the Premium Version just $29!
                                        <a href="https://wpclever.net/downloads/fly-cart?utm_source=pro&utm_medium=woofc&utm_campaign=wporg"
                                           target="_blank">https://wpclever.net/downloads/fly-cart</a>
                                    </p>
                                    <p><strong>Extra features for Premium Version:</strong></p>
                                    <ul style="margin-bottom: 0">
                                        <li>- Enable coupon form.</li>
                                        <li>- Enable shipping cost and shipping calculator.</li>
                                        <li>- Enable instant checkout.</li>
                                        <li>- Get lifetime update & premium support.</li>
                                    </ul>
                                </div>
                            <?php } ?>
                        </div><!-- /.wpclever_settings_page_content -->
                        <div class="wpclever_settings_page_suggestion">
                            <div class="wpclever_settings_page_suggestion_label">
                                <span class="dashicons dashicons-yes-alt"></span> Suggestion
                            </div>
                            <div class="wpclever_settings_page_suggestion_content">
                                <div>
                                    To display custom engaging real-time messages on any wished positions, please
                                    install
                                    <a href="https://wordpress.org/plugins/wpc-smart-messages/" target="_blank">WPC
                                        Smart Messages</a> plugin. It's free!
                                </div>
                                <div>
                                    Wanna save your precious time working on variations? Try our brand-new free plugin
                                    <a href="https://wordpress.org/plugins/wpc-variation-bulk-editor/" target="_blank">WPC
                                        Variation Bulk Editor</a> and
                                    <a href="https://wordpress.org/plugins/wpc-variation-duplicator/" target="_blank">WPC
                                        Variation Duplicator</a>.
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }

                function ajax_update_qty() {
                    if ( ! apply_filters( 'woofc_disable_nonce_check', false, 'update_qty' ) ) {
                        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woofc-security' ) ) {
                            die( 'Permissions check failed!' );
                        }
                    }

                    if ( isset( $_POST['cart_item_qty'] ) && ! empty( $_POST['cart_item_key'] ) ) {
                        if ( $cart_item = WC()->cart->get_cart_item( sanitize_text_field( $_POST['cart_item_key'] ) ) ) {
                            $qty = (float) sanitize_text_field( $_POST['cart_item_qty'] );

                            if ( ( $max_purchase = $cart_item['data']->get_max_purchase_quantity() ) && ( $max_purchase > 0 ) && ( $qty > $max_purchase ) ) {
                                $qty = $max_purchase;
                            }

                            if ( $qty > 0 ) {
                                WC()->cart->set_quantity( sanitize_text_field( $_POST['cart_item_key'] ), $qty );
                            } else {
                                WC()->cart->remove_cart_item( sanitize_text_field( $_POST['cart_item_key'] ) );
                            }
                        }

                        wp_send_json( [ 'action' => 'update_qty' ] );
                    }

                    wp_die();
                }

                function ajax_remove_item() {
                    if ( ! apply_filters( 'woofc_disable_nonce_check', false, 'remove_item' ) ) {
                        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woofc-security' ) ) {
                            die( 'Permissions check failed!' );
                        }
                    }

                    if ( isset( $_POST['cart_item_key'] ) ) {
                        WC()->cart->remove_cart_item( sanitize_text_field( $_POST['cart_item_key'] ) );
                        WC_AJAX::get_refreshed_fragments();
                    }

                    wp_die();
                }

                function ajax_undo_remove() {
                    if ( ! apply_filters( 'woofc_disable_nonce_check', false, 'undo_remove' ) ) {
                        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woofc-security' ) ) {
                            die( 'Permissions check failed!' );
                        }
                    }

                    if ( isset( $_POST['item_key'] ) ) {
                        if ( WC()->cart->restore_cart_item( sanitize_text_field( $_POST['item_key'] ) ) ) {
                            echo 'true';
                        } else {
                            echo 'false';
                        }
                    }

                    wp_die();
                }

                function ajax_empty_cart() {
                    if ( ! apply_filters( 'woofc_disable_nonce_check', false, 'empty_cart' ) ) {
                        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woofc-security' ) ) {
                            die( 'Permissions check failed!' );
                        }
                    }

                    WC()->cart->empty_cart();
                    WC_AJAX::get_refreshed_fragments();

                    wp_die();
                }

                function get_cart_area() {
                    if ( ! isset( WC()->cart ) ) {
                        return '';
                    }

                    // settings
                    $link               = self::get_setting( 'link', 'yes' );
                    $plus_minus         = self::get_setting( 'plus_minus', 'yes' ) === 'yes';
                    $remove             = self::get_setting( 'remove', 'yes' ) === 'yes';
                    $suggested          = self::get_setting( 'suggested', [] );
                    $suggested_products = $cart_products = [];

                    // backward compatibility before 5.2.2
                    if ( ! is_array( $suggested ) ) {
                        switch ( (string) $suggested ) {
                            case 'cross_sells':
                                $suggested = [ 'cross_sells' ];
                                break;
                            case 'related':
                                $suggested = [ 'related' ];
                                break;
                            case 'both':
                                $suggested = [ 'related', 'cross_sells' ];
                                break;
                            case 'none':
                                $suggested = [];
                                break;
                            default:
                                $suggested = [];
                        }
                    }

                    if ( in_array( 'wishlist', $suggested ) && class_exists( 'WPCleverWoosw' ) ) {
                        $suggested_products = array_merge( $suggested_products, array_keys( WPCleverWoosw::get_products() ) );
                    }

                    if ( in_array( 'compare', $suggested ) && class_exists( 'WPCleverWoosc' ) ) {
                        if ( method_exists( 'WPCleverWoosc', 'get_products' ) ) {
                            // from woosc 6.1.4
                            $compare_products   = WPCleverWoosc::get_products();
                            $suggested_products = array_merge( $suggested_products, $compare_products );
                        } else {
                            $cookie = 'woosc_products_' . md5( 'woosc' . get_current_user_id() );

                            if ( ! empty( $_COOKIE[ $cookie ] ) ) {
                                $compare_products   = explode( ',', sanitize_text_field( $_COOKIE[ $cookie ] ) );
                                $suggested_products = array_merge( $suggested_products, $compare_products );
                            }
                        }
                    }

                    ob_start();

                    // global product
                    global $product;
                    $global_product = $product;

                    echo '<div class="woofc-inner woofc-cart-area" data-nonce="' . esc_attr( wp_create_nonce( 'woofc-security' ) ) . '">';

                    do_action( 'woofc_above_area' );
                    echo apply_filters( 'woofc_above_area_content', '' );

                    echo '<div class="woofc-area-top"><span class="woofc-area-heading">' . self::localization( 'heading', esc_html__( 'Shopping cart', 'woo-fly-cart' ) ) . '<span class="woofc-area-count">' . WC()->cart->get_cart_contents_count() . '</span></span>';

                    if ( self::get_setting( 'close', 'yes' ) === 'yes' ) {
                        echo '<div class="woofc-close hint--left" aria-label="' . esc_attr( self::localization( 'close', esc_html__( 'Close', 'woo-fly-cart' ) ) ) . '"><i class="woofc-icon-icon10"></i></div>';
                    }

                    echo '</div><!-- woofc-area-top -->';
                    echo '<div class="woofc-area-mid woofc-items">';

                    do_action( 'woofc_above_items' );
                    echo apply_filters( 'woofc_above_items_content', '' );

                    // notices
                    if ( apply_filters( 'woofc_show_notices', true ) ) {
                        $notices = wc_print_notices( true );

                        if ( ! empty( $notices ) ) {
                            echo '<div class="woofc-notices">' . $notices . '</div>';
                        }
                    }

                    $items = WC()->cart->get_cart();

                    if ( is_array( $items ) && ( count( $items ) > 0 ) ) {
                        if ( apply_filters( 'woofc_cart_items_reverse', self::get_setting( 'reverse_items', 'yes' ) === 'yes' ) ) {
                            $items = array_reverse( $items );
                        }

                        foreach ( $items as $cart_item_key => $cart_item ) {
                            if ( ! isset( $cart_item['bundled_by'] ) && apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
                                $product      = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                                $product_id   = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
                                $product_link = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
                                $item_class   = $remove ? 'woofc-item woofc-item-has-remove' : 'woofc-item woofc-item-has-not-remove';

                                // add suggested products
                                if ( is_array( $suggested ) && ! empty( $suggested ) ) {
                                    $cart_products[] = $product_id;

                                    if ( in_array( 'related', $suggested ) ) {
                                        $suggested_products = array_merge( $suggested_products, wc_get_related_products( $product_id ) );
                                    }

                                    if ( in_array( 'cross_sells', $suggested ) ) {
                                        $suggested_products = array_merge( $suggested_products, $product->get_cross_sell_ids() );
                                    }

                                    if ( in_array( 'up_sells', $suggested ) ) {
                                        $suggested_products = array_merge( $suggested_products, $product->get_upsell_ids() );
                                    }
                                }

                                echo '<div class="' . esc_attr( apply_filters( 'woocommerce_cart_item_class', $item_class, $cart_item, $cart_item_key ) ) . '" data-key="' . esc_attr( $cart_item_key ) . '" data-name="' . esc_attr( $product->get_name() ) . '">';

                                do_action( 'woofc_above_item', $cart_item );
                                echo apply_filters( 'woofc_above_item_inner', '', $cart_item );

                                echo '<div class="woofc-item-inner">';
                                echo '<div class="woofc-item-thumb">';

                                if ( ( $link !== 'no' ) && ! empty( $product_link ) ) {
                                    $cart_item_thumbnail = sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-link" data-id="' . esc_attr( $product_id ) . '" data-context="woofc"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', esc_url( $product_link ), $product->get_image() );
                                } else {
                                    $cart_item_thumbnail = $product->get_image();
                                }

                                echo apply_filters( 'woocommerce_cart_item_thumbnail', $cart_item_thumbnail, $cart_item, $cart_item_key );
                                echo '</div><!-- /.woofc-item-thumb -->';

                                echo '<div class="woofc-item-info">';

                                do_action( 'woofc_above_item_info', $product, $cart_item );
                                //echo apply_filters( 'woofc_above_item_info', '', $product, $cart_item );

                                do_action( 'woofc_above_item_name', $product, $cart_item );

                                echo '<span class="woofc-item-title">';

                                if ( ( $link !== 'no' ) && ! empty( $product_link ) ) {
                                    $cart_item_name = sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-link" data-id="' . esc_attr( $product_id ) . '" data-context="woofc"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', esc_url( $product_link ), $product->get_name() );
                                } else {
                                    $cart_item_name = $product->get_name();
                                }

                                echo apply_filters( 'woocommerce_cart_item_name', $cart_item_name, $cart_item, $cart_item_key );
                                echo '</span><!-- /.woofc-item-title -->';

                                do_action( 'woofc_below_item_name', $product, $cart_item );

                                if ( self::get_setting( 'data', 'no' ) === 'yes' ) {
                                    echo apply_filters( 'woofc_cart_item_data', '<span class="woofc-item-data">' . wc_get_formatted_cart_item_data( $cart_item, apply_filters( 'woofc_cart_item_data_flat', true ) ) . '</span>', $cart_item );
                                }

                                if ( self::get_setting( 'price', 'price' ) === 'price' ) {
                                    echo '<span class="woofc-item-price">' . apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $product ), $cart_item, $cart_item_key ) . '</span>';
                                } elseif ( self::get_setting( 'price', 'price' ) === 'subtotal' ) {
                                    echo '<span class="woofc-item-price">' . apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ), $cart_item, $cart_item_key ) . '</span>';
                                }

                                if ( ( self::get_setting( 'estimated_delivery_date', 'no' ) === 'yes' ) && class_exists( 'WPCleverWpced' ) ) {
                                    echo apply_filters( 'woofc_cart_item_estimated_delivery_date', '<span class="woofc-item-estimated-delivery-date">' . do_shortcode( '[wpced]' ) . '</span>', $cart_item );
                                }

                                if ( ( self::get_setting( 'save_for_later', 'yes' ) === 'yes' ) && class_exists( 'WPCleverWoosl' ) ) {
                                    if ( $cart_item['data']->is_type( 'variation' ) && is_array( $cart_item['variation'] ) ) {
                                        $variation = htmlspecialchars( json_encode( $cart_item['variation'] ), ENT_QUOTES, 'UTF-8' );
                                    } else {
                                        $variation = '';
                                    }

                                    echo '<span class="woofc-item-save">' . do_shortcode( '[woosl_btn product_id="' . $cart_item['product_id'] . '" variation_id="' . $cart_item['variation_id'] . '" price="' . $cart_item['data']->get_price() . '" variation="' . $variation . '" cart_item_key="' . $cart_item_key . '" context="woofc"]' ) . '</span>';
                                }

                                do_action( 'woofc_below_item_info', $product, $cart_item );
                                //echo apply_filters( 'woofc_below_item_info', '', $product, $cart_item );

                                echo '</div><!-- /.woofc-item-info -->';

                                $min_value = apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product );
                                $max_value = apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product );

                                if ( $product->is_sold_individually() || ( $max_value && $min_value === $max_value ) || ! empty( $cart_item['woosb_parent_id'] ) || ! empty( $cart_item['wooco_parent_id'] ) || ! empty( $cart_item['woofs_parent_id'] ) ) {
                                    $cart_item_quantity = $cart_item['quantity'];
                                } else {
                                    $cart_item_qty            = isset( $cart_item['quantity'] ) ? wc_stock_amount( wp_unslash( $cart_item['quantity'] ) ) : $product->get_min_purchase_quantity();
                                    $cart_item_quantity_input = woocommerce_quantity_input( [
                                            'classes'     => [ 'input-text', 'woofc-qty', 'qty', 'text' ],
                                            'input_name'  => 'woofc_qty_' . $cart_item_key,
                                            'input_value' => $cart_item_qty,
                                            'min_value'   => $min_value,
                                            'max_value'   => $max_value,
                                            'woofc_qty'   => [
                                                    'input_value' => $cart_item_qty,
                                                    'min_value'   => $min_value,
                                                    'max_value'   => $max_value
                                            ]
                                    ], $product, false );

                                    if ( $plus_minus ) {
                                        $cart_item_quantity = '<span class="woofc-item-qty-minus">-</span>' . $cart_item_quantity_input . '<span class="woofc-item-qty-plus">+</span>';
                                    } else {
                                        $cart_item_quantity = $cart_item_quantity_input;
                                    }
                                }

                                echo '<div class="woofc-item-qty ' . ( $plus_minus ? 'woofc-item-qty-plus-minus' : '' ) . '"><div class="woofc-item-qty-inner">' . apply_filters( 'woocommerce_cart_item_quantity', $cart_item_quantity, $cart_item_key, $cart_item ) . '</div></div><!-- /.woofc-item-qty -->';

                                // always keep .woofc-item-remove to compatible with themes -  can hide it by CSS
                                echo apply_filters( 'woocommerce_cart_item_remove_link', '<span class="woofc-item-remove" aria-label="' . esc_attr( sprintf( /* translators: product */ esc_html__( 'Remove %s from cart', 'woo-fly-cart' ), wp_strip_all_tags( $product->get_name() ) ) ) . '" data-product_id="' . esc_attr( $product_id ) . '" data-product_sku="' . esc_attr( $product->get_sku() ) . '"><span class="hint--left" aria-label="' . esc_attr( self::localization( 'remove', esc_html__( 'Remove', 'woo-fly-cart' ) ) ) . '"><i class="woofc-icon-icon10"></i></span></span>', $cart_item_key );

                                echo '</div><!-- /.woofc-item-inner -->';

                                do_action( 'woofc_below_item', $cart_item );
                                echo apply_filters( 'woofc_below_item_inner', '', $cart_item );

                                echo '</div><!-- /.woofc-item -->';
                            }
                        }
                    } else {
                        echo '<div class="woofc-no-item">' . wp_kses_post( apply_filters( 'woofc_empty_message', self::localization( 'no_products', esc_html__( 'There are no products in the cart!', 'woo-fly-cart' ) ) ) ) . '</div>';

                        if ( ( self::get_setting( 'save_for_later', 'yes' ) === 'yes' ) && class_exists( 'WPCleverWoosl' ) ) {
                            echo '<div class="woofc-save-for-later">' . do_shortcode( '[woosl_list context="woofc"]' ) . '</div>';
                        }

                        $suggested_empty = self::get_setting( 'suggested_empty', 'no' );

                        if ( $suggested_empty !== 'no' ) {
                            $suggested_empty_args = [
                                    'status' => 'publish',
                                    'limit'  => (int) self::get_setting( 'suggested_limit', 10 ),
                                    'return' => 'ids',
                            ];

                            switch ( $suggested_empty ) {
                                case 'recent':
                                    $suggested_empty_args['orderby'] = 'ID';
                                    $suggested_empty_args['order']   = 'DESC';
                                    break;
                                case 'onsale':
                                    $suggested_empty_args['include'] = wc_get_product_ids_on_sale();
                                    break;
                                case 'featured':
                                    $suggested_empty_args['include'] = wc_get_featured_product_ids();
                                    break;
                                case 'random':
                                    $suggested_empty_args['orderby'] = 'rand';
                                    break;
                            }

                            $suggested_empty_products = wc_get_products( apply_filters( 'woofc_suggested_empty_args', $suggested_empty_args ) );
                            $suggested_empty_products = apply_filters( 'woofc_suggested_empty_products', $suggested_empty_products );

                            if ( is_array( $suggested_empty_products ) && ! empty( $suggested_empty_products ) ) {
                                self::get_suggested_products( $suggested_empty_products, $link );
                            }
                        }
                    }

                    do_action( 'woofc_below_items' );
                    echo apply_filters( 'woofc_below_items_content', '' );

                    echo '</div><!-- woofc-area-mid -->';

                    echo '<div class="woofc-area-bot">';

                    do_action( 'woofc_above_bottom' );
                    echo apply_filters( 'woofc_above_bottom_content', '' );

                    if ( ! empty( $items ) ) {
                        if ( self::get_setting( 'empty', 'no' ) === 'yes' || self::get_setting( 'share', 'yes' ) === 'yes' ) {
                            // enable empty or share
                            echo '<div class="woofc-link">';

                            if ( self::get_setting( 'empty', 'no' ) === 'yes' ) {
                                echo '<div class="woofc-empty"><span class="woofc-empty-cart">' . self::localization( 'empty', esc_html__( 'Empty cart', 'woo-fly-cart' ) ) . '</span></div>';
                            }

                            if ( self::get_setting( 'share', 'yes' ) === 'yes' ) {
                                echo '<div class="woofc-share"><span class="woofc-share-cart wpcss-btn" data-hash="' . esc_attr( WC()->cart->get_cart_hash() ) . '">' . self::localization( 'share', esc_html__( 'Share cart', 'woo-fly-cart' ) ) . '</span></div>';
                            }

                            echo '</div>';
                        }

                        if ( self::get_setting( 'subtotal', 'yes' ) === 'yes' ) {
                            echo apply_filters( 'woofc_above_subtotal_content', '' );
                            echo '<div class="woofc-subtotal woofc-data"><div class="woofc-data-left">' . self::localization( 'subtotal', esc_html__( 'Subtotal', 'woo-fly-cart' ) ) . '</div><div id="woofc-subtotal" class="woofc-data-right">' . apply_filters( 'woofc_get_subtotal', WC()->cart->get_cart_subtotal() ) . '</div></div>';
                            echo apply_filters( 'woofc_below_subtotal_content', '' );
                        }

                        if ( class_exists( 'WPCleverWpcfb' ) && ( self::get_setting( 'free_shipping_bar', 'yes' ) === 'yes' ) ) {
                            echo '<div class="woofc-free-shipping-bar woofc-data">' . do_shortcode( '[wpcfb]' ) . '</div>';
                        }

                        if ( ( self::get_setting( 'tax', 'no' ) === 'yes' ) && wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) {
                            $taxable_address = WC()->customer->get_taxable_address();
                            $estimated_text  = '';

                            if ( WC()->customer->is_customer_outside_base() && ! WC()->customer->has_calculated_shipping() ) {
                                /* translators: %s location. */
                                $estimated_text = sprintf( ' <small>' . esc_html__( '(estimated for %s)', 'woo-fly-cart' ) . '</small>', WC()->countries->estimated_for_prefix( $taxable_address[0] ) . WC()->countries->countries[ $taxable_address[0] ] );
                            }

                            if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
                                foreach ( WC()->cart->get_tax_totals() as $code => $tax ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                                    ?>
                                    <div class="woofc-tax woofc-data woofc-tax-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
                                        <div class="woofc-data-left"><?php echo esc_html( $tax->label ) . $estimated_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                        <div class="woofc-data-right"><?php echo wp_kses_post( $tax->formatted_amount ); ?></div>
                                    </div>
                                    <?php
                                }
                            } else {
                                ?>
                                <div class="woofc-tax-total woofc-data">
                                    <div class="woofc-data-left"><?php echo esc_html( WC()->countries->tax_or_vat() ) . $estimated_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                    <div class="woofc-data-right"><?php wc_cart_totals_taxes_total_html(); ?></div>
                                </div>
                                <?php
                            }
                        }

                        if ( self::get_setting( 'total', 'yes' ) === 'yes' ) {
                            echo apply_filters( 'woofc_above_total_content', '' );
                            echo '<div class="woofc-total woofc-data"><div class="woofc-data-left">' . self::localization( 'total', esc_html__( 'Total', 'woo-fly-cart' ) ) . '</div><div id="woofc-total" class="woofc-data-right">' . apply_filters( 'woofc_get_total', WC()->cart->get_total() ) . '</div></div>';
                            echo apply_filters( 'woofc_below_total_content', '' );
                        }

                        do_action( 'woofc_above_buttons' );

                        if ( self::get_setting( 'buttons', '01' ) === '01' ) {
                            // both buttons
                            echo '<div class="woofc-action"><div class="woofc-action-inner"><div class="woofc-action-left"><a class="woofc-action-cart" href="' . wc_get_cart_url() . '">' . self::localization( 'cart', esc_html__( 'Cart', 'woo-fly-cart' ) ) . '</a></div><div class="woofc-action-right"><a class="woofc-action-checkout" href="' . wc_get_checkout_url() . '">' . self::localization( 'checkout', esc_html__( 'Checkout', 'woo-fly-cart' ) ) . '</a></div></div></div>';
                        } else {
                            if ( self::get_setting( 'buttons', '01' ) === '02' ) {
                                // cart
                                echo '<div class="woofc-action"><div class="woofc-action-inner"><div class="woofc-action-full"><a class="woofc-action-cart" href="' . wc_get_cart_url() . '">' . self::localization( 'cart', esc_html__( 'Cart', 'woo-fly-cart' ) ) . '</a></div></div></div>';
                            }

                            if ( self::get_setting( 'buttons', '01' ) === '03' ) {
                                // checkout
                                echo '<div class="woofc-action"><div class="woofc-action-inner"><div class="woofc-action-full"><a class="woofc-action-checkout" href="' . wc_get_checkout_url() . '">' . self::localization( 'checkout', esc_html__( 'Checkout', 'woo-fly-cart' ) ) . '</a></div></div></div>';
                            }
                        }

                        do_action( 'woofc_below_buttons' );

                        if ( ( self::get_setting( 'save_for_later', 'yes' ) === 'yes' ) && class_exists( 'WPCleverWoosl' ) ) {
                            echo '<div class="woofc-save-for-later">' . do_shortcode( '[woosl_list context="woofc"]' ) . '</div>';
                        }

                        if ( ! empty( $suggested ) ) {
                            $suggested_products = array_unique( $suggested_products );
                            $suggested_products = apply_filters( 'woofc_suggested_products_before_limit', array_diff( $suggested_products, $cart_products ), $suggested_products, $cart_products );

                            if ( $suggested_limit = (int) self::get_setting( 'suggested_limit', 10 ) ) {
                                $suggested_products = array_slice( $suggested_products, 0, $suggested_limit );
                            }

                            $suggested_products = apply_filters( 'woofc_suggested_products', $suggested_products, $cart_products );

                            if ( ! empty( $suggested_products ) ) {
                                self::get_suggested_products( $suggested_products, $link );
                            }
                        }

                        if ( self::get_setting( 'upsell_funnel', 'yes' ) === 'yes' && class_exists( 'Wpcuf' ) ) {
                            echo '<div class="woofc-upsell-funnel">' . do_shortcode( '[wpcuf_uf]' ) . '</div>';
                        }
                    }

                    if ( self::get_setting( 'continue', 'yes' ) === 'yes' ) {
                        echo '<div class="woofc-continue"><span class="woofc-continue-url" data-url="' . esc_url( self::get_setting( 'continue_url', '' ) ) . '">' . self::localization( 'continue', esc_html__( 'Continue shopping', 'woo-fly-cart' ) ) . '</span></div>';
                    }

                    do_action( 'woofc_below_bottom' );
                    echo apply_filters( 'woofc_below_bottom_content', '' );

                    echo '</div><!-- woofc-area-bot -->';

                    do_action( 'woofc_below_area' );
                    echo apply_filters( 'woofc_below_area_content', '' );

                    echo '</div>';

                    $product = $global_product;

                    return apply_filters( 'woofc_cart_area', ob_get_clean() );
                }

                function get_suggested_products( $suggested_products = [], $link = 'no' ) {
                    do_action( 'woofc_above_suggested', $suggested_products );
                    echo apply_filters( 'woofc_above_suggested_content', '' );
                    echo '<div class="woofc-suggested">';
                    echo '<div class="woofc-suggested-heading"><span>' . self::localization( 'suggested', esc_html__( 'You may be interested in&hellip;', 'woo-fly-cart' ) ) . '</span></div>';
                    echo '<div class="woofc-suggested-products ' . ( ( count( $suggested_products ) > 1 ) && ( apply_filters( 'woofc_slick', self::get_setting( 'suggested_carousel', 'yes' ), 'suggested' ) === 'yes' ) ? 'woofc-suggested-products-slick' : '' ) . '">';

                    foreach ( $suggested_products as $suggested_product_id ) {
                        $suggested_product = wc_get_product( $suggested_product_id );

                        if ( $suggested_product ) {
                            $suggested_product_link = $suggested_product->is_visible() ? $suggested_product->get_permalink() : '';

                            echo '<div class="woofc-suggested-product">';
                            echo '<div class="woofc-suggested-product-image">';

                            if ( ( $link !== 'no' ) && ! empty( $suggested_product_link ) ) {
                                echo sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-link" data-id="' . esc_attr( $suggested_product_id ) . '" data-context="woofc"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', esc_url( $suggested_product_link ), $suggested_product->get_image() );
                            } else {
                                echo $suggested_product->get_image();
                            }

                            echo '</div>';
                            echo '<div class="woofc-suggested-product-info">';
                            echo '<div class="woofc-suggested-product-name">';

                            if ( ( $link !== 'no' ) && ! empty( $suggested_product_link ) ) {
                                echo sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-link" data-id="' . esc_attr( $suggested_product_id ) . '" data-context="woofc"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', esc_url( $suggested_product_link ), $suggested_product->get_name() );
                            } else {
                                echo $suggested_product->get_name();
                            }

                            echo '</div>';
                            echo '<div class="woofc-suggested-product-price">' . $suggested_product->get_price_html() . '</div>';
                            echo '<div class="woofc-suggested-product-atc">' . do_shortcode( '[add_to_cart style="" show_price="false" id="' . esc_attr( $suggested_product->get_id() ) . '"]' ) . '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }

                    echo '</div></div>';
                    echo apply_filters( 'woofc_below_suggested_content', '' );
                    do_action( 'woofc_below_suggested', $suggested_products );
                }

                function get_cart_count() {
                    if ( ! isset( WC()->cart ) ) {
                        return '';
                    }

                    $count       = WC()->cart->get_cart_contents_count();
                    $icon        = self::get_setting( 'count_icon', 'woofc-icon-cart7' );
                    $count_class = 'woofc-count woofc-count-' . $count . ' woofc-count-' . self::get_setting( 'count_position', 'bottom-left' );

                    if ( ( self::get_setting( 'count_hide_empty', 'no' ) === 'yes' ) && ( $count <= 0 ) ) {
                        $count_class .= ' woofc-count-hide-empty';
                    }

                    $cart_count = '<div id="woofc-count" class="' . esc_attr( apply_filters( 'woofc_cart_count_class', $count_class ) ) . '" data-count="' . esc_attr( $count ) . '">';
                    $cart_count .= '<i class="' . esc_attr( $icon ) . '"></i>';
                    $cart_count .= '<span id="woofc-count-number" class="woofc-count-number">' . esc_attr( $count ) . '</span>';
                    $cart_count .= '</div>';

                    return apply_filters( 'woofc_cart_count', $cart_count, $count, $icon );
                }

                function get_cart_menu() {
                    if ( ! isset( WC()->cart ) ) {
                        return '';
                    }

                    $count     = WC()->cart->get_cart_contents_count();
                    $subtotal  = WC()->cart->get_cart_subtotal();
                    $icon      = self::get_setting( 'count_icon', 'woofc-icon-cart7' );
                    $cart_menu = '<li class="' . esc_attr( apply_filters( 'woofc_cart_menu_class', 'menu-item woofc-menu-item menu-item-type-woofc' ) ) . '"><a href="' . esc_url( wc_get_cart_url() ) . '"><span class="woofc-menu-item-inner" data-count="' . esc_attr( $count ) . '"><i class="' . esc_attr( $icon ) . '"></i> <span class="woofc-menu-item-inner-subtotal">' . $subtotal . '</span></span></a></li>';

                    return apply_filters( 'woofc_cart_menu', $cart_menu, $count, $subtotal, $icon );
                }

                function nav_menu_items( $items, $args ) {
                    $selected    = false;
                    $saved_menus = self::get_setting( 'menus', [] );

                    if ( ! is_array( $saved_menus ) || empty( $saved_menus ) || ! property_exists( $args, 'menu' ) ) {
                        return $items;
                    }

                    if ( $args->menu instanceof WP_Term ) {
                        // menu object
                        if ( in_array( $args->menu->term_id, $saved_menus, false ) ) {
                            $selected = true;
                        }
                    } elseif ( is_numeric( $args->menu ) ) {
                        // menu id
                        if ( in_array( $args->menu, $saved_menus, false ) ) {
                            $selected = true;
                        }
                    } elseif ( is_string( $args->menu ) ) {
                        // menu slug or name
                        $menu = get_term_by( 'name', $args->menu, 'nav_menu' );

                        if ( ! $menu ) {
                            $menu = get_term_by( 'slug', $args->menu, 'nav_menu' );
                        }

                        if ( $menu && in_array( $menu->term_id, $saved_menus ) ) {
                            $selected = true;
                        }
                    }

                    if ( $selected ) {
                        $items .= self::get_cart_menu();
                    }

                    return $items;
                }

                function footer() {
                    if ( self::disable() ) {
                        return null;
                    }

                    // use 'woofc-position-' instead of 'woofc-effect-' from 5.3
                    $area_class = apply_filters( 'woofc_area_class', 'woofc-area woofc-position-' . esc_attr( self::get_setting( 'position', '05' ) ) . ' woofc-effect-' . esc_attr( self::get_setting( 'position', '05' ) ) . ' woofc-slide-' . esc_attr( self::get_setting( 'effect', 'yes' ) ) . ' woofc-rounded-' . esc_attr( self::get_setting( 'rounded', 'no' ) ) . ' woofc-style-' . esc_attr( self::get_setting( 'style', '01' ) ) );

                    echo '<div id="woofc-area" class="' . esc_attr( $area_class ) . '">';
                    echo self::get_cart_area();

                    echo '</div>';

                    if ( self::get_setting( 'count', 'yes' ) === 'yes' ) {
                        echo self::get_cart_count();
                    }

                    if ( self::get_setting( 'overlay_layer', 'yes' ) === 'yes' ) {
                        echo '<div class="woofc-overlay"></div>';
                    }
                }

                function disable() {
                    global $wp_query;
                    $disable = false;

                    if ( $current_page = $wp_query->get_queried_object_id() ) {
                        $hide_pages = self::get_setting( 'hide_pages', [] );

                        if ( isset( self::$settings['hide_cart_checkout'] ) && ( self::$settings['hide_cart_checkout'] === 'yes' ) ) {
                            $hide_pages[] = wc_get_page_id( 'cart' );
                            $hide_pages[] = wc_get_page_id( 'checkout' );
                        }

                        if ( ! empty( $hide_pages ) && in_array( $current_page, $hide_pages ) ) {
                            // hide on selected pages
                            $disable = true;
                        }
                    }

                    return apply_filters( 'woofc_disable', $disable );
                }

                function cart_fragment( $fragments ) {
                    $fragments['.woofc-count']     = self::get_cart_count();
                    $fragments['.woofc-menu-item'] = self::get_cart_menu();
                    $fragments['.woofc-cart-link'] = self::get_cart_link();
                    $fragments['.woofc-cart-area'] = self::get_cart_area();

                    return $fragments;
                }

                function wpcsm_locations( $locations ) {
                    $locations['WPC Fly Cart'] = [
                            'woofc_above_area'      => esc_html__( 'Before cart', 'woo-fly-cart' ),
                            'woofc_below_area'      => esc_html__( 'After cart', 'woo-fly-cart' ),
                            'woofc_above_items'     => esc_html__( 'Before cart items', 'woo-fly-cart' ),
                            'woofc_below_items'     => esc_html__( 'After cart items', 'woo-fly-cart' ),
                            'woofc_above_item'      => esc_html__( 'Before cart item', 'woo-fly-cart' ),
                            'woofc_below_item'      => esc_html__( 'After cart item', 'woo-fly-cart' ),
                            'woofc_above_item_info' => esc_html__( 'Before cart item info', 'woo-fly-cart' ),
                            'woofc_below_item_info' => esc_html__( 'After cart item info', 'woo-fly-cart' ),
                            'woofc_above_item_name' => esc_html__( 'Before cart item name', 'woo-fly-cart' ),
                            'woofc_below_item_name' => esc_html__( 'After cart item name', 'woo-fly-cart' ),
                            'woofc_above_suggested' => esc_html__( 'Before suggested products', 'woo-fly-cart' ),
                            'woofc_below_suggested' => esc_html__( 'After suggested products', 'woo-fly-cart' ),
                            'woofc_above_buttons'   => esc_html__( 'Before buttons', 'woo-fly-cart' ),
                            'woofc_below_buttons'   => esc_html__( 'After buttons', 'woo-fly-cart' ),
                    ];

                    return $locations;
                }

                function shortcode_cart_link() {
                    return apply_filters( 'woofc_shortcode_cart_link', self::get_cart_link() );
                }

                public static function get_cart_link( $echo = false ) {
                    if ( ! isset( WC()->cart ) ) {
                        return '';
                    }

                    $count     = WC()->cart->get_cart_contents_count();
                    $subtotal  = WC()->cart->get_cart_subtotal();
                    $icon      = self::get_setting( 'count_icon', 'woofc-icon-cart7' );
                    $cart_link = '<span class="woofc-cart-link"><a href="' . wc_get_cart_url() . '"><span class="woofc-cart-link-inner" data-count="' . esc_attr( $count ) . '"><i class="' . esc_attr( $icon ) . '"></i> <span class="woofc-cart-link-inner-subtotal">' . $subtotal . '</span></span></a></span>';
                    $cart_link = apply_filters( 'woofc_cart_link', $cart_link, $count, $subtotal, $icon );

                    if ( $echo ) {
                        echo $cart_link;
                    } else {
                        return $cart_link;
                    }

                    return null;
                }

                public static function woofc_get_cart_link( $echo = false ) {
                    self::get_cart_link( $echo );
                }

                public static function sanitize_array( $arr ) {
                    foreach ( (array) $arr as $k => $v ) {
                        if ( is_array( $v ) ) {
                            $arr[ $k ] = self::sanitize_array( $v );
                        } else {
                            $arr[ $k ] = sanitize_post_field( 'post_content', $v, 0, 'db' );
                        }
                    }

                    return $arr;
                }
            }

            return WPCleverWoofc::instance();
        }

        return null;
    }
}

if ( ! function_exists( 'woofc_notice_wc' ) ) {
    function woofc_notice_wc() {
        ?>
        <div class="error">
            <p><strong>WPC Fly Cart</strong> requires WooCommerce version 3.0 or greater.</p>
        </div>
        <?php
    }
}
