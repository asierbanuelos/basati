<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );
         
if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'font-awesome','simple-line-icons','oceanwp-style' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION

// ─── ENVÍO DE PEDIDOS A REVO XEF ─────────────────────────────────────────────
// Se dispara cuando WooCommerce confirma el pago (funciona con Redsys, Stripe, etc.)

function basati_send_order_to_revo( int $order_id ): void {
    // Evitar doble envío con transient de 60 segundos
    $transient_key = 'basati_revo_sent_' . $order_id;
    if ( get_transient( $transient_key ) ) return;
    set_transient( $transient_key, 1, 60 );

    $service_url = get_option( 'basati_service_url', 'https://web-production-b39d5.up.railway.app' );
    $sync_key    = get_option( 'basati_sync_key', 'basati_sync_2026' );

    $response = wp_remote_post(
        trailingslashit( $service_url ) . 'webhooks/woocommerce/order.paid',
        [
            'timeout'     => 10,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-Sync-Key'   => $sync_key,
            ],
            'body'        => wp_json_encode( [ 'id' => $order_id ] ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        error_log( '[Basati] Error enviando pedido ' . $order_id . ' a Revo: ' . $response->get_error_message() );
    } else {
        error_log( '[Basati] Pedido ' . $order_id . ' enviado a Revo. Respuesta: ' . wp_remote_retrieve_body( $response ) );
    }
}

add_action( 'woocommerce_payment_complete',       'basati_send_order_to_revo' );
add_action( 'woocommerce_order_status_processing', 'basati_send_order_to_revo' );

// ─── MODIFICADORES: Gestión + Popup ──────────────────────────────────────────

// Inicializar clave de sync en options si no existe
add_action( 'init', function() {
    if ( ! get_option( 'basati_sync_key' ) ) {
        update_option( 'basati_sync_key', 'basati_sync_2026' );
    }
} );

// ─── PANEL DE CONTROL DE PEDIDOS ─────────────────────────────────────────────

function basati_is_orders_enabled(): bool {
    return get_option( 'basati_pedidos_activos', '1' ) === '1';
}

function basati_is_extras_enabled(): bool {
    return get_option( 'basati_extras_activos', '1' ) === '1';
}

// ─── SISTEMA DE FRANJAS HORARIAS ──────────────────────────────────────────────

function basati_slot_config(): array {
    return [
        'start'        => get_option( 'basati_slot_start', '19:00' ),
        'end'          => get_option( 'basati_slot_end', '23:45' ),
        'max_delivery' => max( 1, (int) get_option( 'basati_max_slot_delivery', 14 ) ),
        'max_pickup'   => max( 1, (int) get_option( 'basati_max_slot_pickup', 14 ) ),
    ];
}

function basati_generate_slots(): array {
    $cfg   = basati_slot_config();
    $start = strtotime( 'today ' . $cfg['start'] );
    $end   = strtotime( 'today ' . $cfg['end'] );
    $slots = [];
    for ( $t = $start; $t <= $end; $t += 15 * 60 ) {
        $slots[] = date( 'H:i', $t );
    }
    return $slots;
}

function basati_online_slot_counts( string $date, string $type ): array {
    return (array) get_option( "basati_online_{$type}_{$date}", [] );
}

// Por franja, devuelve un array de $max booleanos (un hueco = una casilla
// marcable), normalizando formatos antiguos (entero) a array de booleanos.
function basati_manual_slot_checks_raw( string $date, string $type, int $max ): array {
    $raw    = (array) get_option( "basati_manual_{$type}_{$date}", [] );
    $result = [];
    foreach ( basati_generate_slots() as $slot ) {
        $checks = $raw[ $slot ] ?? [];
        if ( ! is_array( $checks ) ) { $checks = array_fill( 0, (int) $checks, true ); }
        $result[ $slot ] = array_values( array_pad( array_slice( $checks, 0, $max ), $max, false ) );
    }
    return $result;
}

// Por franja: 'auto_count' = casillas ocupadas automáticamente por pedidos web
// (bloqueadas), 'manual_checks' = casillas restantes, marcables a mano (teléfono).
function basati_day_slots( string $date, string $type ): array {
    $cfg = basati_slot_config();
    $max = $type === 'delivery' ? $cfg['max_delivery'] : $cfg['max_pickup'];
    $on  = basati_online_slot_counts( $date, $type );
    $raw = basati_manual_slot_checks_raw( $date, $type, $max );

    $result = [];
    foreach ( basati_generate_slots() as $slot ) {
        $o      = min( $max, (int) ( $on[ $slot ] ?? 0 ) );
        $checks = $raw[ $slot ];
        $m      = count( array_filter( $checks ) );
        $total  = $o + $m;
        $manual_avail = max( 0, $max - $o );
        $result[ $slot ] = [
            'online'        => $o,
            'manual'        => $m,
            'total'         => $total,
            'max'           => $max,
            'available'     => max( 0, $max - $total ),
            'auto_count'    => $o,
            'manual_checks' => array_slice( $checks, 0, $manual_avail ),
        ];
    }
    return $result;
}

function basati_slot_can_accept( string $date, string $type, string $slot, int $qty ): bool {
    $cfg   = basati_slot_config();
    $max   = $type === 'delivery' ? $cfg['max_delivery'] : $cfg['max_pickup'];
    $slots = basati_day_slots( $date, $type );
    $total = $slots[ $slot ]['total'] ?? 0;
    return ( $total + $qty ) <= $max;
}

// Guardar conteo online cuando se crea un pedido
add_action( 'woocommerce_checkout_order_created', function( $order ) {
    $type = $order->get_meta( '_basati_order_type' );
    $slot = $order->get_meta( '_basati_slot' );
    if ( ! $type || ! $slot ) return;
    $date = $order->get_date_created()->date( 'Y-m-d' );
    $key  = "basati_online_{$type}_{$date}";
    $data = (array) get_option( $key, [] );
    $qty  = 0;
    foreach ( $order->get_items() as $item ) { $qty += (int) $item->get_quantity(); }
    $data[ $slot ] = (int) ( $data[ $slot ] ?? 0 ) + $qty;
    update_option( $key, $data, false );
} );

// Restar cuando se cancela/reembolsa
function basati_remove_slot_items( int $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $type = $order->get_meta( '_basati_order_type' );
    $slot = $order->get_meta( '_basati_slot' );
    if ( ! $type || ! $slot ) return;
    $date = $order->get_date_created()->date( 'Y-m-d' );
    $key  = "basati_online_{$type}_{$date}";
    $data = (array) get_option( $key, [] );
    $qty  = 0;
    foreach ( $order->get_items() as $item ) { $qty += (int) $item->get_quantity(); }
    $data[ $slot ] = max( 0, (int) ( $data[ $slot ] ?? 0 ) - $qty );
    update_option( $key, $data, false );
}
add_action( 'woocommerce_order_status_cancelled', 'basati_remove_slot_items' );
add_action( 'woocommerce_order_status_refunded',  'basati_remove_slot_items' );

// Guardar tipo y franja en el pedido al confirmar
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    if ( WC()->session ) {
        $order->update_meta_data( '_basati_order_type', WC()->session->get( 'basati_delivery_type', 'delivery' ) );
        $order->update_meta_data( '_basati_slot',       WC()->session->get( 'basati_slot', '' ) );
    }
}, 10, 2 );

function basati_check_category_limit( int $product_id ): ?string {
    if ( ! WC()->cart ) return null;

    $restricted = [ 'pizza-erdiak' ];
    $terms      = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
    $matched    = array_intersect( $restricted, is_array( $terms ) ? $terms : [] );

    if ( empty( $matched ) ) return null;

    foreach ( WC()->cart->get_cart() as $item ) {
        $item_terms = wp_get_post_terms( $item['product_id'], 'product_cat', [ 'fields' => 'slugs' ] );
        if ( ! empty( array_intersect( $matched, is_array( $item_terms ) ? $item_terms : [] ) ) ) {
            return 'Solo puedes añadir 1 Pizza Erdia al carrito.';
        }
    }
    return null;
}

add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity ) {
    if ( ! $passed ) return false;
    if ( ! basati_is_orders_enabled() ) {
        wc_add_notice( 'Los pedidos están deshabilitados temporalmente.', 'error' );
        return false;
    }
    $type = WC()->session ? (string) WC()->session->get( 'basati_delivery_type', '' ) : '';
    $slot = WC()->session ? (string) WC()->session->get( 'basati_slot', '' ) : '';
    if ( $type && $slot ) {
        $in_cart = WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
        $date    = current_time( 'Y-m-d' );
        if ( ! basati_slot_can_accept( $date, $type, $slot, $in_cart + $quantity ) ) {
            $slots = basati_day_slots( $date, $type );
            $free  = max( 0, ( $slots[ $slot ]['available'] ?? 0 ) - $in_cart );
            wc_add_notice(
                $free > 0
                    ? "Solo quedan {$free} artículo(s) libres en la franja {$slot}."
                    : "La franja {$slot} está llena. Elige otra franja horaria.",
                'error'
            );
            return false;
        }
    }
    $error = basati_check_category_limit( (int) $product_id );
    if ( $error ) { wc_add_notice( $error, 'error' ); return false; }
    return $passed;
}, 10, 3 );

add_action( 'woocommerce_checkout_process', function() {
    if ( ! basati_is_orders_enabled() ) {
        wc_add_notice( 'Los pedidos están deshabilitados temporalmente.', 'error' );
        return;
    }
    $type = WC()->session ? (string) WC()->session->get( 'basati_delivery_type', '' ) : '';
    $slot = WC()->session ? (string) WC()->session->get( 'basati_slot', '' ) : '';
    if ( ! $type ) { wc_add_notice( 'Por favor elige si tu pedido es a domicilio o para recoger.', 'error' ); return; }
    if ( ! $slot ) { wc_add_notice( 'Por favor elige una franja horaria para tu pedido.', 'error' ); return; }
    $cart_qty = WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
    $date     = current_time( 'Y-m-d' );
    if ( ! basati_slot_can_accept( $date, $type, $slot, $cart_qty ) ) {
        $slots = basati_day_slots( $date, $type );
        $avail = $slots[ $slot ]['available'] ?? 0;
        wc_add_notice(
            $avail > 0
                ? "La franja {$slot} ya no tiene espacio suficiente. Solo quedan {$avail} artículo(s). Vuelve al menú y elige otra franja."
                : "La franja {$slot} se ha llenado. Vuelve al menú y elige otra franja horaria.",
            'error'
        );
    }
} );

add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    $type = WC()->session ? (string) WC()->session->get( 'basati_delivery_type', 'delivery' ) : 'delivery';
    if ( $type !== 'delivery' ) return;
    $fee = (float) get_option( 'basati_gastos_envio', 1.50 );
    if ( $fee > 0 ) {
        $cart->add_fee( 'Gastos de envío', $fee );
    }
} );

// AJAX: disponibilidad de franjas (frontend)
add_action( 'wp_ajax_basati_slots',        'basati_ajax_slots' );
add_action( 'wp_ajax_nopriv_basati_slots', 'basati_ajax_slots' );
function basati_ajax_slots(): void {
    check_ajax_referer( 'basati_modifiers', 'nonce' );
    $type = sanitize_text_field( $_POST['type'] ?? 'delivery' );
    if ( ! in_array( $type, [ 'delivery', 'pickup' ] ) ) $type = 'delivery';
    $date    = current_time( 'Y-m-d' );
    $slots   = basati_day_slots( $date, $type );
    $cart_qty = WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
    wp_send_json_success( [ 'slots' => $slots, 'cart_qty' => $cart_qty ] );
}

// AJAX: guardar tipo+franja en sesión WC (frontend)
add_action( 'wp_ajax_basati_set_slot',        'basati_ajax_set_slot' );
add_action( 'wp_ajax_nopriv_basati_set_slot', 'basati_ajax_set_slot' );
function basati_ajax_set_slot(): void {
    check_ajax_referer( 'basati_modifiers', 'nonce' );
    $type = sanitize_text_field( $_POST['type'] ?? 'delivery' );
    $slot = sanitize_text_field( $_POST['slot'] ?? '' );
    if ( WC()->session ) {
        WC()->session->set( 'basati_delivery_type', $type );
        WC()->session->set( 'basati_slot', $slot );
    }
    wp_send_json_success( [ 'type' => $type, 'slot' => $slot ] );
}

// AJAX: entrada manual en franja (admin/tablet) — marca/desmarca casillas
add_action( 'wp_ajax_basati_manual_slot', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden' ); return; }
    check_ajax_referer( 'basati_manual_slot_nonce', 'nonce' );
    $type = sanitize_text_field( $_POST['type'] ?? 'delivery' );
    $slot = sanitize_text_field( $_POST['slot'] ?? '' );
    $date = current_time( 'Y-m-d' );
    if ( ! $slot || ! in_array( $type, [ 'delivery', 'pickup' ], true ) ) { wp_send_json_error( 'Invalid' ); return; }

    $cfg  = basati_slot_config();
    $max  = $type === 'delivery' ? $cfg['max_delivery'] : $cfg['max_pickup'];
    $key  = "basati_manual_{$type}_{$date}";
    $data = (array) get_option( $key, [] );
    $checks = $data[ $slot ] ?? [];
    if ( ! is_array( $checks ) ) { $checks = array_fill( 0, (int) $checks, true ); }
    $checks = array_values( array_pad( array_slice( $checks, 0, $max ), $max, false ) );

    if ( isset( $_POST['index'] ) ) {
        $index = intval( $_POST['index'] );
        if ( $index < 0 || $index >= $max ) { wp_send_json_error( 'Invalid index' ); return; }
        $checks[ $index ] = ! empty( $_POST['checked'] );
    } else {
        $value  = max( 0, intval( $_POST['value'] ?? 0 ) );
        $checks = array_pad( array_fill( 0, min( $value, $max ), true ), $max, false );
    }

    $data[ $slot ] = $checks;
    update_option( $key, $data, false );
    wp_send_json_success( [ 'slots' => basati_day_slots( $date, $type ), 'checks' => $checks ] );
} );

// AJAX: refresco periódico de las tablas de franjas (rellena casillas web automáticas)
add_action( 'wp_ajax_basati_slot_refresh', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden' ); return; }
    check_ajax_referer( 'basati_manual_slot_nonce', 'nonce' );
    $today = current_time( 'Y-m-d' );
    wp_send_json_success( [
        'delivery' => basati_day_slots( $today, 'delivery' ),
        'pickup'   => basati_day_slots( $today, 'pickup' ),
    ] );
} );

add_action( 'wp_ajax_basati_hourly_stats', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden' ); return; }

    $days  = min( 30, max( 1, intval( $_POST['days'] ?? 7 ) ) );
    $start = strtotime( "-{$days} days midnight" );

    $orders = wc_get_orders( [
        'status'       => [ 'pending', 'processing', 'on-hold', 'completed', 'wc-completed' ],
        'limit'        => -1,
        'date_created' => '>' . $start,
    ] );

    $slots = [];
    foreach ( $orders as $order ) {
        $date = $order->get_date_created();
        if ( ! $date ) continue;
        $day  = $date->date( 'Y-m-d' );
        $hour = $date->date( 'H' );
        if ( ! isset( $slots[ $day ][ $hour ] ) ) {
            $slots[ $day ][ $hour ] = [ 'orders' => 0, 'items' => 0 ];
        }
        $slots[ $day ][ $hour ]['orders']++;
        foreach ( $order->get_items() as $item ) {
            $slots[ $day ][ $hour ]['items'] += (int) $item->get_quantity();
        }
    }

    wp_send_json_success( [
        'slots'      => $slots,
        'days'       => $days,
        'max_orders' => basati_slot_config()['max_delivery'],
    ] );
} );

add_action( 'admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Panel de Pedidos',
        '🛵 Panel de Pedidos',
        'manage_woocommerce',
        'basati-control',
        'basati_render_control_page'
    );
} );

// ─── WIDGET DE ESCRITORIO ─────────────────────────────────────────────────────

add_action( 'wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'basati_dashboard_widget',
        '🛵 Basati — Control de Pedidos',
        'basati_dashboard_widget_render'
    );
} );

function basati_dashboard_widget_render(): void {
    if ( isset( $_POST['basati_widget_nonce'] ) && wp_verify_nonce( $_POST['basati_widget_nonce'], 'basati_widget_save' ) ) {
        update_option( 'basati_pedidos_activos', isset( $_POST['pedidos_activos_w'] ) ? '1' : '0' );
        echo '<div style="background:#edfaed;border-left:3px solid #27ae60;padding:.5rem .8rem;margin-bottom:.8rem;border-radius:3px;font-size:.85rem">✅ Guardado</div>';
    }

    $on         = basati_is_orders_enabled();
    $today      = current_time( 'Y-m-d' );
    $now_hour   = (int) current_time( 'H' );
    $now_min    = (int) current_time( 'i' );
    $del_slots  = basati_day_slots( $today, 'delivery' );
    $pck_slots  = basati_day_slots( $today, 'pickup' );
    $nonce_man  = wp_create_nonce( 'basati_manual_slot_nonce' );

    // Mostrar solo las próximas 4 franjas desde ahora
    $all_slot_keys = array_keys( $del_slots );
    $current_idx   = 0;
    foreach ( $all_slot_keys as $i => $s ) {
        [ $h, $m ] = explode( ':', $s );
        if ( (int)$h > $now_hour || ( (int)$h === $now_hour && (int)$m >= $now_min ) ) {
            $current_idx = $i;
            break;
        }
    }
    $visible_keys = array_slice( $all_slot_keys, $current_idx, 5 );
    ?>
    <style>
        #basati_dashboard_widget .bc-w-toggle{display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;padding-bottom:.8rem;border-bottom:1px solid #eee}
        #basati_dashboard_widget .bc-w-badge{font-size:.8rem;font-weight:700;padding:2px 8px;border-radius:3px;color:#fff;background:<?php echo $on ? '#27ae60' : '#e74c3c'; ?>}
        #basati_dashboard_widget .bcw-slot-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:.25rem;margin:.5rem 0}
        #basati_dashboard_widget .bcw-slot-cell{border:1.5px solid #eee;border-radius:4px;padding:.25rem .15rem;text-align:center;font-size:.72rem}
        #basati_dashboard_widget .bcw-slot-time{font-weight:700;display:block;font-size:.75rem}
        #basati_dashboard_widget .bcw-slot-avail{display:block;font-size:.65rem;color:#888}
        #basati_dashboard_widget .bcw-slot-input{width:100%;border:1px solid #ddd;border-radius:3px;text-align:center;font-size:.8rem;padding:.15rem;margin-top:.2rem}
        #basati_dashboard_widget .bcw-label{font-size:.75rem;font-weight:700;color:#555;margin:.6rem 0 .2rem;display:block}
    </style>

    <form method="post">
        <?php wp_nonce_field( 'basati_widget_save', 'basati_widget_nonce' ); ?>
        <div class="bc-w-toggle">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem">
                <input type="checkbox" name="pedidos_activos_w" <?php checked( $on ); ?> style="width:16px;height:16px">
                Pedidos activos
            </label>
            <span class="bc-w-badge"><?php echo $on ? '✅ ON' : '🚫 OFF'; ?></span>
        </div>
        <button type="submit" class="button" style="width:100%;font-size:.82rem;margin-bottom:.8rem">💾 Guardar</button>
    </form>

    <span class="bcw-label">🛵 Próximas franjas — Domicilio</span>
    <div class="bcw-slot-grid">
    <?php foreach ( $visible_keys as $slot ) :
        $d   = $del_slots[ $slot ];
        $pct = $d['max'] > 0 ? min(100, round($d['total']/$d['max']*100)) : 0;
        $bc  = $pct >= 100 ? '#fde8e8;border-color:#e74c3c' : ($pct >= 70 ? '#fff8e1;border-color:#f39c12' : '#f0faf0;border-color:#27ae60');
    ?>
        <div class="bcw-slot-cell" style="background:<?php echo $bc; ?>">
            <span class="bcw-slot-time"><?php echo esc_html($slot); ?></span>
            <span class="bcw-slot-avail"><?php echo $d['available']; ?> lib.</span>
            <input type="number" class="bcw-slot-input bc-slot-manual-input"
                data-type="delivery" data-slot="<?php echo esc_attr($slot); ?>"
                data-nonce="<?php echo esc_attr($nonce_man); ?>"
                value="<?php echo esc_attr($d['manual']); ?>" min="0" placeholder="man">
        </div>
    <?php endforeach; ?>
    </div>

    <span class="bcw-label">🏪 Próximas franjas — Recoger</span>
    <div class="bcw-slot-grid">
    <?php foreach ( $visible_keys as $slot ) :
        $d   = $pck_slots[ $slot ];
        $pct = $d['max'] > 0 ? min(100, round($d['total']/$d['max']*100)) : 0;
        $bc  = $pct >= 100 ? '#fde8e8;border-color:#e74c3c' : ($pct >= 70 ? '#fff8e1;border-color:#f39c12' : '#f0f7ff;border-color:#3498db');
    ?>
        <div class="bcw-slot-cell" style="background:<?php echo $bc; ?>">
            <span class="bcw-slot-time"><?php echo esc_html($slot); ?></span>
            <span class="bcw-slot-avail"><?php echo $d['available']; ?> lib.</span>
            <input type="number" class="bcw-slot-input bc-slot-manual-input"
                data-type="pickup" data-slot="<?php echo esc_attr($slot); ?>"
                data-nonce="<?php echo esc_attr($nonce_man); ?>"
                value="<?php echo esc_attr($d['manual']); ?>" min="0" placeholder="man">
        </div>
    <?php endforeach; ?>
    </div>

    <script>
    (function($){
        var saveTimer = {};
        $(document).on('change input', '.bcw-slot-input', function() {
            var $inp = $(this), key = $inp.data('type') + '_' + $inp.data('slot');
            clearTimeout(saveTimer[key]);
            saveTimer[key] = setTimeout(function() {
                $.post(ajaxurl, { action:'basati_manual_slot', nonce:$inp.data('nonce'),
                    type:$inp.data('type'), slot:$inp.data('slot'), value:parseInt($inp.val())||0 });
            }, 800);
        });
    })(jQuery);
    </script>

    <p style="margin:.7rem 0 0;font-size:.75rem;color:#aaa;text-align:right">
        <a href="<?php echo admin_url('admin.php?page=basati-control'); ?>">Ver todas las franjas →</a>
    </p>

    <?php
}

function basati_render_control_page(): void {
    if ( isset( $_POST['basati_control_nonce'] ) && wp_verify_nonce( $_POST['basati_control_nonce'], 'basati_save_control' ) ) {
        update_option( 'basati_pedidos_activos',   isset( $_POST['pedidos_activos'] ) ? '1' : '0' );
        update_option( 'basati_extras_activos',    isset( $_POST['extras_activos'] )  ? '1' : '0' );
        update_option( 'basati_max_slot_delivery', max( 1, intval( $_POST['max_slot_delivery'] ?? 14 ) ) );
        update_option( 'basati_max_slot_pickup',   max( 1, intval( $_POST['max_slot_pickup']   ?? 14 ) ) );
        update_option( 'basati_slot_start',        sanitize_text_field( $_POST['slot_start'] ?? '19:00' ) );
        update_option( 'basati_slot_end',          sanitize_text_field( $_POST['slot_end']   ?? '23:45' ) );
        update_option( 'basati_gastos_envio', max( 0.0, (float) str_replace( ',', '.', sanitize_text_field( $_POST['gastos_envio'] ?? '1.50' ) ) ) );

        $all_ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
        $visible = array_map( 'intval', (array) ( $_POST['visible_products'] ?? [] ) );
        update_option( 'basati_hidden_products', array_values( array_diff( $all_ids, $visible ) ) );

        $mod_config = get_option( 'revo_modifiers_config', [] );
        if ( ! empty( $mod_config['categories'] ) ) {
            $en_cats = (array) ( $_POST['mod_cats'] ?? [] );
            $en_mods = (array) ( $_POST['mod_mods'] ?? [] );
            foreach ( $mod_config['categories'] as &$cat ) {
                $cat['enabled'] = in_array( (string) $cat['id'], $en_cats );
                foreach ( $cat['modifiers'] as &$mod ) {
                    $mod['enabled'] = in_array( (string) $mod['id'], $en_mods );
                }
            }
            unset( $cat, $mod );
            update_option( 'revo_modifiers_config', $mod_config );
        }

        echo '<div class="notice notice-success is-dismissible"><p>✅ Configuración guardada.</p></div>';
    }

    $pedidosOn      = basati_is_orders_enabled();
    $extrasOn       = basati_is_extras_enabled();
    $slot_cfg       = basati_slot_config();
    $today          = current_time( 'Y-m-d' );
    $delivery_slots = basati_day_slots( $today, 'delivery' );
    $pickup_slots   = basati_day_slots( $today, 'pickup' );
    $nonce_manual   = wp_create_nonce( 'basati_manual_slot_nonce' );
    $fee            = (float) get_option( 'basati_gastos_envio', 1.50 );
    $hidden         = array_map( 'intval', (array) get_option( 'basati_hidden_products', [] ) );

    $mod_config   = get_option( 'revo_modifiers_config', [] );

    $all_products = [];
    $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name' ] );
    foreach ( ( is_array( $cats ) ? $cats : [] ) as $cat ) {
        $posts = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'id', 'terms' => $cat->term_id ] ],
        ] );
        if ( ! empty( $posts ) ) {
            $all_products[ $cat->name ] = $posts;
        }
    }
    ?>
    <div class="wrap">
    <h1>🛵 Panel de Pedidos — Basati</h1>
    <style>
        .bc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;margin:1.5rem 0}
        .bc-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:1.5rem}
        .bc-card h2{margin:0 0 1.2rem;font-size:1.05rem;display:flex;align-items:center;gap:.4rem}
        .bc-toggle{position:relative;display:inline-block;width:62px;height:32px;flex-shrink:0}
        .bc-toggle input{opacity:0;width:0;height:0}
        .bc-slider{position:absolute;cursor:pointer;inset:0;background:#ccc;border-radius:32px;transition:.25s}
        .bc-slider:before{content:'';position:absolute;height:24px;width:24px;left:4px;bottom:4px;background:#fff;border-radius:50%;transition:.25s}
        .bc-toggle input:checked+.bc-slider{background:#27ae60}
        .bc-toggle input:checked+.bc-slider:before{transform:translateX(30px)}
        .bc-toggle-row{display:flex;align-items:center;gap:1rem;margin-bottom:.8rem}
        .bc-status{font-size:1rem;font-weight:600}
        .bc-on{color:#27ae60}.bc-off{color:#e74c3c}
        .bc-hint{color:#888;font-size:.82rem;margin:0;line-height:1.4}
        .bc-bar-wrap{background:#f0f0f0;border-radius:4px;height:18px;overflow:hidden;margin:.35rem 0 .15rem}
        .bc-bar{height:100%;border-radius:4px;transition:width .3s,background .3s}
        .bc-stat-row{display:flex;justify-content:space-between;align-items:baseline;font-size:.9rem}
        .bc-stat-num{font-size:1.15rem;font-weight:700}
        .bc-refresh{background:none;border:1px solid #ddd;border-radius:4px;padding:.25rem .7rem;cursor:pointer;font-size:.8rem;color:#555;margin-left:.5rem}
        .bc-refresh:hover{background:#f5f5f5}
        .bc-fields{display:grid;grid-template-columns:1fr 1fr;gap:.8rem 1rem}
        .bc-field label{display:block;font-size:.82rem;color:#555;margin-bottom:.3rem;font-weight:600}
        .bc-field input[type=number]{width:100%;padding:.45rem .6rem;border:1px solid #ddd;border-radius:4px;font-size:1rem}
        .bc-cap-summary{font-size:.82rem;color:#888;margin-top:.8rem}
        .bc-motos{text-align:center;font-size:1.8rem;letter-spacing:.25rem;margin-bottom:.8rem}
        .bc-alert{background:#fff3cd;border-left:4px solid #f39c12;padding:.8rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:.9rem}
        .bc-alert-red{background:#fde8e8;border-color:#e74c3c}
        /* slot tables */
        .bc-slot-wrap{display:grid;grid-template-columns:1fr;gap:1.5rem;margin-top:1.5rem}
        .bc-slot-table{background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden}
        .bc-slot-table-header{padding:.8rem 1.2rem;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between}
        .bc-slot-table-header h3{margin:0;font-size:.95rem}
        .bc-slot-tbl{width:100%;border-collapse:collapse;font-size:.85rem}
        .bc-slot-tbl th{background:#f8f8f8;padding:.45rem .7rem;text-align:center;font-size:.75rem;color:#666;border-bottom:1px solid #eee}
        .bc-slot-tbl th:first-child{text-align:left}
        .bc-slot-tbl td{padding:.4rem .7rem;border-bottom:1px solid #f5f5f5;text-align:center;vertical-align:middle}
        .bc-slot-tbl td:first-child{font-weight:700;color:#444;text-align:left}
        .bc-slot-bar{background:#f0f0f0;border-radius:3px;height:8px;overflow:hidden;width:60px;display:inline-block}
        .bc-slot-bar-fill{height:100%;border-radius:3px;transition:width .3s}
        .bc-slot-checks{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:3px}
        .bc-slot-check{width:17px;height:17px;cursor:pointer;accent-color:#27ae60;margin:0}
        .bc-slot-check-auto{accent-color:#2980b9;cursor:not-allowed}
        .bc-check-sep{width:1px;height:16px;background:#ddd;margin:0 3px}
        .bc-slot-full td:first-child{color:#e74c3c}
    </style>

    <form method="post" id="bc-form">
    <?php wp_nonce_field( 'basati_save_control', 'basati_control_nonce' ); ?>
    <div class="bc-grid">

        <div class="bc-card">
            <h2>🛒 Pedidos en el frontend</h2>
            <div class="bc-toggle-row">
                <label class="bc-toggle">
                    <input type="checkbox" name="pedidos_activos" id="bc-pedidos" <?php checked( $pedidosOn ); ?>>
                    <span class="bc-slider"></span>
                </label>
                <span class="bc-status <?php echo $pedidosOn ? 'bc-on' : 'bc-off'; ?>">
                    <?php echo $pedidosOn ? '✅ Habilitados' : '🚫 Deshabilitados'; ?>
                </span>
            </div>
            <p class="bc-hint"><?php echo $pedidosOn
                ? 'Los clientes pueden hacer pedidos normalmente.'
                : 'El botón de carrito está desactivado para los clientes.'; ?></p>
        </div>

        <div class="bc-card">
            <h2>➕ Extras / Modificadores</h2>
            <div class="bc-toggle-row">
                <label class="bc-toggle">
                    <input type="checkbox" name="extras_activos" id="bc-extras" <?php checked( $extrasOn ); ?>>
                    <span class="bc-slider"></span>
                </label>
                <span class="bc-status <?php echo $extrasOn ? 'bc-on' : 'bc-off'; ?>">
                    <?php echo $extrasOn ? '✅ Habilitados' : '🚫 Deshabilitados'; ?>
                </span>
            </div>
            <p class="bc-hint"><?php echo $extrasOn
                ? 'Se muestra el selector de extras al añadir productos.'
                : 'Se añade al carrito directamente, sin mostrar extras.'; ?></p>
        </div>

        <div class="bc-card">
            <h2>⚙️ Configuración de franjas</h2>
            <div class="bc-fields">
                <div class="bc-field">
                    <label>Hora inicio</label>
                    <input type="time" name="slot_start" value="<?php echo esc_attr( $slot_cfg['start'] ); ?>" style="width:100%;padding:.45rem .6rem;border:1px solid #ddd;border-radius:4px">
                </div>
                <div class="bc-field">
                    <label>Hora fin</label>
                    <input type="time" name="slot_end" value="<?php echo esc_attr( $slot_cfg['end'] ); ?>" style="width:100%;padding:.45rem .6rem;border:1px solid #ddd;border-radius:4px">
                </div>
                <div class="bc-field">
                    <label>Máx. artículos/franja 🛵 Domicilio</label>
                    <input type="number" name="max_slot_delivery" value="<?php echo esc_attr( $slot_cfg['max_delivery'] ); ?>" min="1" max="100">
                </div>
                <div class="bc-field">
                    <label>Máx. artículos/franja 🏪 Recoger</label>
                    <input type="number" name="max_slot_pickup" value="<?php echo esc_attr( $slot_cfg['max_pickup'] ); ?>" min="1" max="100">
                </div>
                <div class="bc-field">
                    <label>Gastos de envío domicilio (€)</label>
                    <input type="number" name="gastos_envio" value="<?php echo esc_attr( number_format( $fee, 2, '.', '' ) ); ?>" min="0" step="0.01">
                </div>
                <div class="bc-field" style="grid-column:1/-1">
                    <button type="submit" class="button button-primary" style="width:100%">💾 Guardar configuración</button>
                </div>
            </div>
        </div>

    </div>

    <?php
    // ── Tablas de franjas ────────────────────────────────────────────────────
    function basati_render_slot_table( array $slots, string $type, string $label, string $nonce_manual ): void {
        $type_color = $type === 'delivery' ? '#c0392b' : '#2980b9';
        ?>
        <div class="bc-slot-table">
            <div class="bc-slot-table-header">
                <h3 style="color:<?php echo $type_color; ?>"><?php echo esc_html( $label ); ?> — Hoy (<?php echo current_time( 'd/m' ); ?>)</h3>
                <span style="font-size:.78rem;color:#aaa">Franjas de 15 min · máx <?php echo $slots ? array_values($slots)[0]['max'] : '—'; ?> artículos</span>
            </div>
            <table class="bc-slot-tbl">
                <thead><tr>
                    <th>Franja</th><th>Web</th><th>Manual</th><th>Total</th><th>Disponible</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $slots as $slot => $d ) :
                    $pct = $d['max'] > 0 ? min( 100, round( $d['total'] / $d['max'] * 100 ) ) : 0;
                    $bar_color = $pct >= 100 ? '#e74c3c' : ( $pct >= 70 ? '#f39c12' : '#27ae60' );
                    $row_class = $pct >= 100 ? 'bc-slot-full' : '';
                ?>
                <tr class="<?php echo $row_class; ?>">
                    <td><?php echo esc_html( $slot ); ?></td>
                    <td><?php echo $d['online']; ?></td>
                    <td>
                        <div class="bc-slot-checks"
                            data-type="<?php echo esc_attr( $type ); ?>"
                            data-slot="<?php echo esc_attr( $slot ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce_manual ); ?>"
                            data-auto="<?php echo (int) $d['auto_count']; ?>">
                            <?php
                            $pos = 0;
                            for ( $a = 0; $a < $d['auto_count']; $a++ ) :
                                if ( $pos > 0 && $pos % 5 === 0 ) : ?><span class="bc-check-sep"></span><?php endif;
                                $pos++;
                            ?>
                                <input type="checkbox" class="bc-slot-check bc-slot-check-auto" checked disabled title="Pedido web (automático)">
                            <?php endfor;
                            foreach ( $d['manual_checks'] as $i => $on ) :
                                if ( $pos > 0 && $pos % 5 === 0 ) : ?><span class="bc-check-sep"></span><?php endif;
                                $pos++;
                            ?>
                                <input type="checkbox" class="bc-slot-check" data-index="<?php echo (int) $i; ?>" <?php checked( $on ); ?> title="Manual (teléfono)">
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td><strong><?php echo $d['total']; ?>/<?php echo $d['max']; ?></strong></td>
                    <td style="color:<?php echo $bar_color; ?>;font-weight:700">
                        <?php echo $d['available']; ?>
                        <span class="bc-slot-bar"><span class="bc-slot-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>"></span></span>
                    </td>
                    <td style="color:#aaa;font-size:.75rem"><?php echo $pct >= 100 ? 'LLENO' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    ?>
    <p style="font-size:.78rem;color:#888;margin:.8rem 0 0;display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap">
        <span style="display:flex;align-items:center;gap:.3rem"><input type="checkbox" checked disabled style="width:14px;height:14px;accent-color:#2980b9;margin:0"> Pedido web (se marca solo)</span>
        <span style="display:flex;align-items:center;gap:.3rem"><input type="checkbox" checked disabled style="width:14px;height:14px;accent-color:#27ae60;margin:0"> Manual (teléfono)</span>
    </p>
    <div class="bc-slot-wrap">
        <?php basati_render_slot_table( $delivery_slots, 'delivery', '🛵 Domicilio', $nonce_manual ); ?>
        <?php basati_render_slot_table( $pickup_slots,   'pickup',   '🏪 Recoger',   $nonce_manual ); ?>
    </div>

    <script>
    (function($){
        // Marcar/desmarcar casillas de pedidos manuales por franja
        $(document).on('change', '.bc-slot-check:not(.bc-slot-check-auto)', function() {
            var $chk  = $(this);
            var $wrap = $chk.closest('.bc-slot-checks');
            $.post(ajaxurl, {
                action:  'basati_manual_slot',
                nonce:   $wrap.data('nonce'),
                type:    $wrap.data('type'),
                slot:    $wrap.data('slot'),
                index:   $chk.data('index'),
                checked: $chk.is(':checked') ? 1 : 0
            }, function(r) {
                if (!r.success) return;
                var d = r.data.slots[$wrap.data('slot')];
                updateSlotRow($wrap, d);
            });
        });

        function checksHtml(d) {
            var html = '', pos = 0;
            for (var a = 0; a < d.auto_count; a++) {
                if (pos > 0 && pos % 5 === 0) html += '<span class="bc-check-sep"></span>';
                html += '<input type="checkbox" class="bc-slot-check bc-slot-check-auto" checked disabled title="Pedido web (automático)">';
                pos++;
            }
            d.manual_checks.forEach(function(on, i) {
                if (pos > 0 && pos % 5 === 0) html += '<span class="bc-check-sep"></span>';
                html += '<input type="checkbox" class="bc-slot-check" data-index="' + i + '"' + (on ? ' checked' : '') + ' title="Manual (teléfono)">';
                pos++;
            });
            return html;
        }

        function updateSlotRow($wrap, d) {
            var $tr  = $wrap.closest('tr');
            var pct  = d.max > 0 ? Math.min(100, Math.round(d.total / d.max * 100)) : 0;
            var bar  = pct >= 100 ? '#e74c3c' : (pct >= 70 ? '#f39c12' : '#27ae60');
            $tr.find('td:nth-child(2)').text(d.online);
            $tr.find('td:nth-child(4) strong').text(d.total + '/' + d.max);
            $tr.find('td:nth-child(5)').first().css({color: bar, 'font-weight': 700}).html(
                d.available + ' <span class="bc-slot-bar"><span class="bc-slot-bar-fill" style="width:' + pct + '%;background:' + bar + '"></span></span>'
            );
            $tr.toggleClass('bc-slot-full', pct >= 100);
            $tr.find('td:last-child').text(pct >= 100 ? 'LLENO' : '');
            if (parseInt($wrap.data('auto'), 10) !== d.auto_count) {
                $wrap.data('auto', d.auto_count).html(checksHtml(d));
            }
        }

        // Refresca periódicamente para que las casillas web se marquen solas
        function refreshSlots() {
            $.post(ajaxurl, { action: 'basati_slot_refresh', nonce: '<?php echo esc_js( $nonce_manual ); ?>' }, function(r) {
                if (!r.success) return;
                ['delivery', 'pickup'].forEach(function(type) {
                    var slots = r.data[type] || {};
                    $('.bc-slot-checks[data-type="' + type + '"]').each(function() {
                        var $wrap = $(this);
                        var d = slots[$wrap.data('slot')];
                        if (d) updateSlotRow($wrap, d);
                    });
                });
            });
        }
        setInterval(refreshSlots, 20000);
    })(jQuery);
    </script>

    <div style="height:1.5rem"></div>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-top:1.5rem;overflow:hidden">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem">
            <h2 style="margin:0;font-size:1.05rem">🛍️ Visibilidad de Productos</h2>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                <input type="text" id="bc-prod-search" placeholder="Buscar producto..." style="padding:.35rem .7rem;border:1px solid #ddd;border-radius:4px;font-size:.85rem;width:180px">
                <button type="button" id="bc-show-all" class="button" style="font-size:.82rem">✅ Mostrar todos</button>
                <button type="button" id="bc-hide-all" class="button" style="font-size:.82rem">🚫 Ocultar todos</button>
                <button type="submit" class="button button-primary" style="font-size:.82rem">💾 Guardar visibilidad</button>
            </div>
        </div>
        <?php if ( empty( $all_products ) ) : ?>
            <p style="padding:1.5rem;color:#888;margin:0">No hay productos publicados.</p>
        <?php else : ?>
        <table class="wp-list-table widefat" style="border:0;border-radius:0">
            <thead>
                <tr style="background:#f8f8f8">
                    <th style="width:160px;padding:.6rem 1rem">Categoría</th>
                    <th style="padding:.6rem 1rem">Producto</th>
                    <th style="width:90px;text-align:right;padding:.6rem 1rem">Precio</th>
                    <th style="width:90px;text-align:center;padding:.6rem 1rem">Visible</th>
                </tr>
            </thead>
            <tbody id="bc-prod-tbody">
            <?php foreach ( $all_products as $cat_name => $prods ) :
                $cat_ids        = wp_list_pluck( $prods, 'ID' );
                $hidden_in_cat  = count( array_intersect( $cat_ids, $hidden ) );
                $all_hidden     = $hidden_in_cat === count( $cat_ids );
            ?>
                <tr class="bc-cat-row" data-cat="<?php echo esc_attr( $cat_name ); ?>" style="background:#f0f4f8">
                    <td colspan="3" style="padding:.5rem 1rem;font-weight:700;font-size:.85rem;color:#444">
                        <?php echo esc_html( $cat_name ); ?>
                        <span style="font-weight:400;color:#aaa;font-size:.78rem">&nbsp;<?php echo count( $prods ); ?> productos
                        <?php if ( $hidden_in_cat > 0 ) echo '· <span style="color:#e74c3c">' . $hidden_in_cat . ' ocultos</span>'; ?></span>
                    </td>
                    <td style="text-align:center;padding:.5rem">
                        <button type="button" class="button bc-cat-btn" style="font-size:.75rem;padding:2px 8px"
                                data-cat="<?php echo esc_attr( $cat_name ); ?>"
                                data-state="<?php echo $all_hidden ? 'hidden' : 'visible'; ?>">
                            <?php echo $all_hidden ? 'Mostrar cat.' : 'Ocultar cat.'; ?>
                        </button>
                    </td>
                </tr>
                <?php foreach ( $prods as $p ) :
                    $product = wc_get_product( $p->ID );
                    if ( ! $product ) continue;
                    $is_vis = ! in_array( $p->ID, $hidden, true );
                ?>
                <tr class="bc-prod-row" data-cat="<?php echo esc_attr( $cat_name ); ?>" data-name="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>"
                    style="<?php echo $is_vis ? '' : 'opacity:.5;background:#fafafa'; ?>">
                    <td style="color:#999;font-size:.82rem;padding:.5rem 1rem"><?php echo esc_html( $cat_name ); ?></td>
                    <td style="padding:.5rem 1rem">
                        <span style="font-weight:500"><?php echo esc_html( $p->post_title ); ?></span>
                        <?php if ( ! $product->is_in_stock() ) : ?>
                            <span style="background:#e74c3c;color:#fff;border-radius:3px;padding:1px 6px;font-size:.7rem;margin-left:.4rem">Sin stock</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;color:#c0392b;padding:.5rem 1rem">
                        <?php echo $product->get_price() ? wc_price( $product->get_price() ) : '—'; ?>
                    </td>
                    <td style="text-align:center;padding:.5rem 1rem">
                        <label class="bc-toggle">
                            <input type="checkbox" name="visible_products[]" value="<?php echo esc_attr( $p->ID ); ?>"
                                   class="bc-vis-check" data-cat="<?php echo esc_attr( $cat_name ); ?>"
                                   <?php checked( $is_vis ); ?>>
                            <span class="bc-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php
    $mod_cats_list = $mod_config['categories'] ?? [];
    if ( ! empty( $mod_cats_list ) ) :
        $last_sync = $mod_config['last_sync'] ?? 'Nunca';
    ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-top:1.5rem;overflow:hidden">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem">
            <div>
                <h2 style="margin:0 0 .2rem;font-size:1.05rem">➕ Visibilidad de Extras / Modificadores</h2>
                <span style="font-size:.78rem;color:#aaa">Última sync: <?php echo esc_html( $last_sync ); ?></span>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                <button type="button" id="bc-mods-show-all" class="button" style="font-size:.82rem">✅ Activar todos</button>
                <button type="button" id="bc-mods-hide-all" class="button" style="font-size:.82rem">🚫 Desactivar todos</button>
                <button type="submit" class="button button-primary" style="font-size:.82rem">💾 Guardar extras</button>
            </div>
        </div>
        <table class="wp-list-table widefat" style="border:0;border-radius:0">
            <thead>
                <tr style="background:#f8f8f8">
                    <th style="width:160px;padding:.6rem 1rem">Categoría</th>
                    <th style="padding:.6rem 1rem">Extra / Modificador</th>
                    <th style="width:90px;text-align:right;padding:.6rem 1rem">Precio</th>
                    <th style="width:90px;text-align:center;padding:.6rem 1rem">Activo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $mod_cats_list as $mcat ) :
                $mcat_on     = ! empty( $mcat['enabled'] );
                $type_label  = $mcat['type'] === 'add' ? '➕ Extras' : '➖ Quitar';
                $type_color  = $mcat['type'] === 'add' ? '#27ae60' : '#e74c3c';
                $applies     = implode( ', ', $mcat['applies_to'] ?? [] );
                $hidden_mods = count( array_filter( $mcat['modifiers'] ?? [], fn($m) => empty( $m['enabled'] ) ) );
            ?>
                <tr class="bc-mcat-row" data-mcat="<?php echo esc_attr( $mcat['id'] ); ?>" style="background:#f0f4f8">
                    <td colspan="3" style="padding:.5rem 1rem;font-weight:700;font-size:.85rem;color:#444">
                        <?php echo esc_html( $mcat['name'] ); ?>
                        <span style="background:<?php echo $type_color; ?>;color:#fff;border-radius:3px;padding:1px 7px;font-size:.72rem;margin-left:.4rem"><?php echo $type_label; ?></span>
                        <span style="font-weight:400;color:#aaa;font-size:.78rem;margin-left:.5rem">Aplica a: <?php echo esc_html( $applies ); ?></span>
                        <?php if ( $hidden_mods > 0 ) : ?>
                            <span style="color:#e74c3c;font-size:.78rem;margin-left:.4rem">&nbsp;· <?php echo $hidden_mods; ?> desactivados</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;padding:.5rem">
                        <button type="button" class="button bc-mcat-btn" style="font-size:.75rem;padding:2px 8px"
                                data-mcat="<?php echo esc_attr( $mcat['id'] ); ?>"
                                data-state="<?php echo $mcat_on ? 'on' : 'off'; ?>">
                            <?php echo $mcat_on ? 'Desactivar cat.' : 'Activar cat.'; ?>
                        </button>
                    </td>
                </tr>
                <?php foreach ( $mcat['modifiers'] as $mmod ) :
                    $mmod_on = ! empty( $mmod['enabled'] );
                ?>
                <tr class="bc-mmod-row" data-mcat="<?php echo esc_attr( $mcat['id'] ); ?>"
                    style="<?php echo $mmod_on ? '' : 'opacity:.5;background:#fafafa'; ?>">
                    <td style="color:#999;font-size:.82rem;padding:.5rem 1rem"><?php echo esc_html( $mcat['name'] ); ?></td>
                    <td style="padding:.5rem 1rem;font-weight:500"><?php echo esc_html( $mmod['name'] ); ?></td>
                    <td style="text-align:right;font-weight:600;color:#c0392b;padding:.5rem 1rem">
                        <?php echo $mmod['price'] > 0 ? '+' . number_format( $mmod['price'], 2 ) . '€' : '—'; ?>
                    </td>
                    <td style="text-align:center;padding:.5rem 1rem">
                        <label class="bc-toggle">
                            <input type="checkbox" name="mod_mods[]" value="<?php echo esc_attr( $mmod['id'] ); ?>"
                                   class="bc-mmod-check" data-mcat="<?php echo esc_attr( $mcat['id'] ); ?>"
                                   <?php checked( $mmod_on ); ?>>
                            <span class="bc-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3" style="padding:.3rem 1rem;border-top:1px solid #f0f0f0">
                        <label class="bc-toggle" style="transform:scale(.85);transform-origin:left">
                            <input type="checkbox" name="mod_cats[]" value="<?php echo esc_attr( $mcat['id'] ); ?>"
                                   class="bc-mcat-check" data-mcat="<?php echo esc_attr( $mcat['id'] ); ?>"
                                   <?php checked( $mcat_on ); ?>>
                            <span class="bc-slider"></span>
                        </label>
                        <span style="font-size:.8rem;color:#888;margin-left:.4rem">Categoría completa activa</span>
                    </td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    </form>

    <script>
    (function($){
        $('#bc-pedidos, #bc-extras').on('change', function(){ $('#bc-form').submit(); });

        // Recargar página para actualizar tablas de franjas (más simple que AJAX complejo)
        $('#bc-refresh').on('click', function(){ location.reload(); });

        // ── Tabla de productos ────────────────────────────────────────────────
        $('#bc-prod-search').on('input', function(){
            var q = $(this).val().toLowerCase().trim();
            $('.bc-prod-row').each(function(){
                $(this).toggle(!q || $(this).data('name').indexOf(q) !== -1);
            });
            $('.bc-cat-row').each(function(){
                var cat = $(this).data('cat');
                $(this).toggle($('.bc-prod-row[data-cat="' + cat + '"]:visible').length > 0);
            });
        });

        $('#bc-show-all').on('click', function(){
            $('.bc-vis-check').prop('checked', true);
            $('.bc-prod-row').css({opacity:'1', background:''});
        });

        $('#bc-hide-all').on('click', function(){
            $('.bc-vis-check').prop('checked', false);
            $('.bc-prod-row').css({opacity:'.5', background:'#fafafa'});
        });

        $(document).on('click', '.bc-cat-btn', function(){
            var $btn     = $(this);
            var cat      = $btn.data('cat');
            var state    = $btn.data('state');
            var show     = state === 'hidden';
            $('.bc-vis-check[data-cat="' + cat + '"]').prop('checked', show);
            $('.bc-prod-row[data-cat="' + cat + '"]').css({opacity: show ? '1' : '.5', background: show ? '' : '#fafafa'});
            $btn.data('state', show ? 'visible' : 'hidden').text(show ? 'Ocultar cat.' : 'Mostrar cat.');
        });

        $(document).on('change', '.bc-vis-check', function(){
            $(this).closest('.bc-prod-row').css({opacity: $(this).is(':checked') ? '1' : '.5', background: $(this).is(':checked') ? '' : '#fafafa'});
        });

        // ── Tabla de extras ───────────────────────────────────────────────────
        $('#bc-mods-show-all').on('click', function(){
            $('.bc-mmod-check, .bc-mcat-check').prop('checked', true);
            $('.bc-mmod-row').css({opacity:'1', background:''});
        });

        $('#bc-mods-hide-all').on('click', function(){
            $('.bc-mmod-check, .bc-mcat-check').prop('checked', false);
            $('.bc-mmod-row').css({opacity:'.5', background:'#fafafa'});
        });

        $(document).on('click', '.bc-mcat-btn', function(){
            var $btn  = $(this);
            var mcat  = $btn.data('mcat');
            var state = $btn.data('state');
            var on    = state === 'off';
            $('.bc-mmod-check[data-mcat="' + mcat + '"]').prop('checked', on);
            $('.bc-mcat-check[data-mcat="' + mcat + '"]').prop('checked', on);
            $('.bc-mmod-row[data-mcat="' + mcat + '"]').css({opacity: on ? '1' : '.5', background: on ? '' : '#fafafa'});
            $btn.data('state', on ? 'on' : 'off').text(on ? 'Desactivar cat.' : 'Activar cat.');
        });

        $(document).on('change', '.bc-mmod-check', function(){
            $(this).closest('.bc-mmod-row').css({opacity: $(this).is(':checked') ? '1' : '.5', background: $(this).is(':checked') ? '' : '#fafafa'});
        });

        $(document).on('change', '.bc-mcat-check', function(){
            var mcat = $(this).data('mcat');
            var on   = $(this).is(':checked');
            $('.bc-mmod-check[data-mcat="' + mcat + '"]').prop('checked', on);
            $('.bc-mmod-row[data-mcat="' + mcat + '"]').css({opacity: on ? '1' : '.5', background: on ? '' : '#fafafa'});
        });

        // ── Registro por hora ──────────────────────────────────────────────────
        var $hmWrap = $('#bc-heatmap-wrap');

        function renderHeatmap(slots, maxOrders) {
            var days = Object.keys(slots).sort();
            if (days.length === 0) return '<p style="color:#888;padding:1rem">No hay pedidos en este período.</p>';

            var activeHours = [];
            for (var h = 0; h < 24; h++) {
                var hh = String(h).padStart(2, '0');
                if (days.some(function(d){ return slots[d] && slots[d][hh] && slots[d][hh].orders > 0; })) {
                    activeHours.push(hh);
                }
            }
            if (activeHours.length === 0) return '<p style="color:#888;padding:1rem">No hay datos en este período.</p>';

            var dayNames = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
            var html = '<div style="overflow-x:auto"><table class="bc-hm-table">';

            // Cabecera con días
            html += '<thead><tr><th class="bc-hm-th-hour">Hora</th>';
            days.forEach(function(d) {
                var dt   = new Date(d + 'T12:00:00');
                var name = dayNames[dt.getDay()] + '<br><span style="font-weight:400;font-size:.75rem">' + dt.getDate() + '/' + (dt.getMonth()+1) + '</span>';
                html += '<th class="bc-hm-th-day">' + name + '</th>';
            });
            html += '<th class="bc-hm-th-day" style="background:#f0f0f0">Total</th></tr></thead>';

            // Filas por hora
            html += '<tbody>';
            activeHours.forEach(function(hh) {
                var rowOrders = 0, rowItems = 0;
                html += '<tr><td class="bc-hm-hour">' + hh + ':00</td>';
                days.forEach(function(d) {
                    var cell = slots[d] && slots[d][hh];
                    var o = cell ? cell.orders : 0;
                    var i = cell ? cell.items  : 0;
                    rowOrders += o; rowItems += i;
                    var pct = maxOrders > 0 ? o / maxOrders : 0;
                    var bg  = o === 0 ? '' : pct >= 1 ? '#e74c3c' : pct >= 0.7 ? '#f39c12' : pct >= 0.35 ? '#f1c40f' : '#2ecc71';
                    var fg  = o === 0 ? '#ccc' : (pct >= 0.35 ? '#fff' : '#333');
                    var title = o > 0 ? o + ' pedidos · ' + i + ' artículos' : '';
                    html += '<td class="bc-hm-cell" style="' + (bg ? 'background:' + bg + ';' : '') + 'color:' + fg + '" title="' + title + '">' + (o || '·') + '</td>';
                });
                html += '<td class="bc-hm-cell bc-hm-total" title="' + rowItems + ' artículos">' + rowOrders + '</td>';
                html += '</tr>';
            });

            // Fila totales
            html += '<tr class="bc-hm-totals"><td class="bc-hm-hour">Total</td>';
            var grandTotal = 0;
            days.forEach(function(d) {
                var dayTotal = 0;
                Object.values(slots[d] || {}).forEach(function(c){ dayTotal += c.orders; });
                grandTotal += dayTotal;
                html += '<td class="bc-hm-cell bc-hm-total">' + dayTotal + '</td>';
            });
            html += '<td class="bc-hm-cell bc-hm-grand">' + grandTotal + '</td>';
            html += '</tr></tbody></table></div>';

            // Leyenda
            html += '<div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:.7rem;font-size:.77rem;color:#666">';
            html += '<span>Leyenda:</span>';
            [['#2ecc71','Bajo'],['#f1c40f','Medio'],['#f39c12','Alto'],['#e74c3c','Lleno']].forEach(function(l){
                html += '<span style="display:flex;align-items:center;gap:.3rem"><span style="width:12px;height:12px;background:' + l[0] + ';border-radius:2px;display:inline-block"></span>' + l[1] + '</span>';
            });
            html += '</div>';
            return html;
        }

        $('#bc-load-heatmap').on('click', function() {
            var days = $('#bc-hm-days').val();
            var $btn = $(this).prop('disabled', true).text('Cargando...');
            $hmWrap.html('<p style="color:#888;padding:1rem">Consultando pedidos...</p>');
            $.post(ajaxurl, { action: 'basati_hourly_stats', days: days }, function(res) {
                $btn.prop('disabled', false).text('Cargar');
                if (!res.success) { $hmWrap.html('<p style="color:#e74c3c">Error al cargar datos.</p>'); return; }
                $hmWrap.html(renderHeatmap(res.data.slots, res.data.max_orders));
            }).fail(function(){
                $btn.prop('disabled', false).text('Cargar');
                $hmWrap.html('<p style="color:#e74c3c">Error de conexión.</p>');
            });
        });

    })(jQuery);
    </script>

    <style>
        .bc-hm-table{border-collapse:collapse;min-width:100%;font-size:.85rem}
        .bc-hm-table th,.bc-hm-table td{border:1px solid #e0e0e0;text-align:center;white-space:nowrap}
        .bc-hm-th-hour{padding:.5rem .8rem;background:#f8f8f8;font-size:.78rem;color:#666;text-align:left;min-width:55px}
        .bc-hm-th-day{padding:.4rem .6rem;background:#f8f8f8;font-size:.8rem;min-width:52px;line-height:1.3}
        .bc-hm-hour{padding:.4rem .8rem;background:#f8f8f8;font-size:.8rem;font-weight:600;color:#555;text-align:left}
        .bc-hm-cell{padding:.35rem .5rem;font-weight:600;font-size:.85rem;cursor:default}
        .bc-hm-total{background:#f5f5f5;color:#333}
        .bc-hm-grand{background:#e8e8e8;font-weight:700}
        .bc-hm-totals td{border-top:2px solid #ccc;font-weight:700;background:#f0f0f0}
    </style>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-top:1.5rem;overflow:hidden">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem">
            <h2 style="margin:0;font-size:1.05rem">📅 Registro de pedidos por hora</h2>
            <div style="display:flex;gap:.5rem;align-items:center">
                <label style="font-size:.85rem;color:#555">Últimos</label>
                <select id="bc-hm-days" style="padding:.3rem .5rem;border:1px solid #ddd;border-radius:4px;font-size:.85rem">
                    <option value="7">7 días</option>
                    <option value="14">14 días</option>
                    <option value="30">30 días</option>
                </select>
                <button type="button" id="bc-load-heatmap" class="button">Cargar</button>
            </div>
        </div>
        <div id="bc-heatmap-wrap" style="padding:1rem 1.5rem;color:#aaa;font-size:.88rem">
            Selecciona el período y pulsa <strong>Cargar</strong>.
        </div>
    </div>

    </div>
    <?php
}

// REST endpoint: recibe config de modificadores desde Node.js
add_action( 'rest_api_init', function() {
    register_rest_route( 'basati/v1', '/sync-modifiers', [
        'methods'             => 'POST',
        'callback'            => 'basati_receive_modifier_sync',
        'permission_callback' => 'basati_check_sync_key',
    ] );
} );

function basati_check_sync_key( WP_REST_Request $request ): bool {
    return $request->get_header( 'X-Sync-Key' ) === get_option( 'basati_sync_key', '' );
}

function basati_receive_modifier_sync( WP_REST_Request $request ): WP_REST_Response {
    $body = $request->get_json_params();
    if ( empty( $body['categories'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing categories' ], 400 );
    }

    $existing = get_option( 'revo_modifiers_config', [] );
    // Indexar existentes por ID para preservar toggles manuales
    $existingCats = [];
    foreach ( ( $existing['categories'] ?? [] ) as $cat ) {
        $existingCats[ $cat['id'] ] = $cat;
    }
    $existingMods = [];
    foreach ( $existingCats as $cat ) {
        foreach ( ( $cat['modifiers'] ?? [] ) as $mod ) {
            $existingMods[ $mod['id'] ] = $mod;
        }
    }

    $merged = [];
    foreach ( $body['categories'] as $cat ) {
        $catEnabled = $existingCats[ $cat['id'] ]['enabled'] ?? true;
        $mergedMods = [];
        foreach ( $cat['modifiers'] as $mod ) {
            $mergedMods[] = [
                'id'      => $mod['id'],
                'name'    => $mod['name'],
                'price'   => $mod['price'],
                'enabled' => $existingMods[ $mod['id'] ]['enabled'] ?? true,
            ];
        }
        $merged[] = [
            'id'          => $cat['id'],
            'name'        => $cat['name'],
            'type'        => $cat['type'],
            'enabled'     => $catEnabled,
            'applies_to'  => $cat['applies_to'],
            'modifiers'   => $mergedMods,
        ];
    }

    update_option( 'revo_modifiers_config', [
        'last_sync'  => current_time( 'Y-m-d H:i:s' ),
        'categories' => $merged,
    ] );

    return new WP_REST_Response( [ 'ok' => true, 'categories' => count( $merged ) ] );
}

// Página de administración
add_action( 'admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Modificadores Revo',
        'Modificadores Revo',
        'manage_woocommerce',
        'revo-modifiers',
        'basati_render_modifiers_page'
    );
} );

function basati_render_modifiers_page(): void {
    // Guardar cambios
    if ( isset( $_POST['basati_modifiers_nonce'] ) && wp_verify_nonce( $_POST['basati_modifiers_nonce'], 'basati_save_modifiers' ) ) {
        $config = get_option( 'revo_modifiers_config', [] );
        $enabled_cats = $_POST['enabled_cats'] ?? [];
        $enabled_mods = $_POST['enabled_mods'] ?? [];

        foreach ( $config['categories'] as &$cat ) {
            $cat['enabled'] = in_array( (string) $cat['id'], $enabled_cats );
            foreach ( $cat['modifiers'] as &$mod ) {
                $mod['enabled'] = in_array( (string) $mod['id'], $enabled_mods );
            }
        }
        update_option( 'revo_modifiers_config', $config );
        echo '<div class="notice notice-success"><p>✅ Cambios guardados.</p></div>';
    }

    $config = get_option( 'revo_modifiers_config', [] );
    $categories = $config['categories'] ?? [];
    $lastSync   = $config['last_sync'] ?? 'Nunca';
    ?>
    <div class="wrap">
        <h1>🔧 Modificadores Revo XEF</h1>
        <p style="color:#666">Última sincronización: <strong><?php echo esc_html( $lastSync ); ?></strong>
        — Para actualizar ejecuta <code>npm run sync:direct</code></p>

        <?php if ( empty( $categories ) ) : ?>
            <div class="notice notice-warning"><p>Sin datos. Ejecuta <code>npm run sync:direct</code> primero.</p></div>
        <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'basati_save_modifiers', 'basati_modifiers_nonce' ); ?>
            <style>
                .revo-cat { background:#fff; border:1px solid #ddd; border-radius:6px; margin-bottom:1.5rem; overflow:hidden; }
                .revo-cat-header { display:flex; align-items:center; gap:1rem; padding:.8rem 1.2rem; background:#f8f8f8; border-bottom:1px solid #eee; }
                .revo-cat-header h3 { margin:0; font-size:1rem; flex:1; }
                .revo-badge-add { background:#27ae60; color:#fff; border-radius:4px; padding:2px 8px; font-size:.75rem; }
                .revo-badge-remove { background:#e74c3c; color:#fff; border-radius:4px; padding:2px 8px; font-size:.75rem; }
                .revo-applies { font-size:.8rem; color:#888; }
                .revo-mods-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.5rem; padding:1rem 1.2rem; }
                .revo-mod-row { display:flex; align-items:center; gap:.5rem; font-size:.9rem; }
                .revo-price { color:#c0392b; font-weight:600; font-size:.8rem; margin-left:auto; }
            </style>

            <?php foreach ( $categories as $cat ) :
                $catEnabled = ! empty( $cat['enabled'] );
                $type = $cat['type'] === 'add' ? 'add' : 'remove';
            ?>
            <div class="revo-cat">
                <div class="revo-cat-header">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="enabled_cats[]" value="<?php echo esc_attr( $cat['id'] ); ?>"
                            <?php checked( $catEnabled ); ?> style="width:18px;height:18px;accent-color:#c0392b">
                        <h3><?php echo esc_html( $cat['name'] ); ?></h3>
                    </label>
                    <span class="revo-badge-<?php echo $type; ?>"><?php echo $type === 'add' ? '➕ Extras' : '➖ Quitar'; ?></span>
                    <span class="revo-applies">Aplica a: <?php echo esc_html( implode( ', ', $cat['applies_to'] ?? [] ) ); ?></span>
                </div>
                <div class="revo-mods-grid">
                    <?php foreach ( $cat['modifiers'] as $mod ) :
                        $modEnabled = ! empty( $mod['enabled'] );
                    ?>
                    <label class="revo-mod-row" style="<?php echo $modEnabled ? '' : 'opacity:.45'; ?>">
                        <input type="checkbox" name="enabled_mods[]" value="<?php echo esc_attr( $mod['id'] ); ?>"
                            <?php checked( $modEnabled ); ?> style="accent-color:#c0392b">
                        <?php echo esc_html( $mod['name'] ); ?>
                        <?php if ( $mod['price'] > 0 ) : ?>
                            <span class="revo-price">+<?php echo number_format( $mod['price'], 2 ); ?>€</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <p><button type="submit" class="button button-primary button-large">💾 Guardar cambios</button></p>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

// AJAX: devuelve los modificadores activos de un producto según su categoría
function basati_get_modifiers(): void {
    check_ajax_referer( 'basati_modifiers', 'nonce' );

    if ( ! basati_is_extras_enabled() ) { wp_send_json_success( [] ); return; }

    $product_id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) { wp_send_json_error( 'Missing product_id' ); return; }

    $config = get_option( 'revo_modifiers_config', [] );
    if ( empty( $config['categories'] ) ) { wp_send_json_success( [] ); return; }

    // Obtener categorías WooCommerce del producto
    $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
    $productCatNames = array_map( 'strtolower', is_array( $terms ) ? $terms : [] );

    // Filtrar categorías de modificadores que aplican a este producto
    $result = [];
    foreach ( $config['categories'] as $cat ) {
        if ( empty( $cat['enabled'] ) ) continue;

        // Comprobar si alguna de las categorías del producto está en applies_to
        $appliesTo = array_map( 'strtolower', $cat['applies_to'] ?? [] );
        $matches   = array_intersect( $productCatNames, $appliesTo );
        if ( empty( $matches ) ) continue;

        $enabledMods = array_filter( $cat['modifiers'], fn($m) => ! empty( $m['enabled'] ) );
        if ( empty( $enabledMods ) ) continue;

        $result[] = [
            'id'        => $cat['id'],
            'name'      => $cat['name'],
            'type'      => $cat['type'],
            'modifiers' => array_values( $enabledMods ),
        ];
    }

    wp_send_json_success( $result );
}
add_action( 'wp_ajax_basati_get_modifiers',        'basati_get_modifiers' );
add_action( 'wp_ajax_nopriv_basati_get_modifiers', 'basati_get_modifiers' );

// Inyectar modificadores via sesión WC (más fiable que leer $_POST en el filtro)
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item_data, $product_id ) {
    $modifiers = WC()->session->get( 'basati_next_modifiers', '' );
    if ( empty( $modifiers ) ) return $cart_item_data;

    $product = wc_get_product( $product_id );
    $cart_item_data['revo_modifiers']      = $modifiers;
    $cart_item_data['basati_base_price']   = $product ? (float) $product->get_price( 'edit' ) : 0;
    $cart_item_data['basati_modifier_key'] = md5( $modifiers );

    WC()->session->set( 'basati_next_modifiers', '' );
    return $cart_item_data;
}, 10, 2 );

// AJAX: añadir al carrito con modificadores
function basati_ajax_add_to_cart(): void {
    check_ajax_referer( 'basati_modifiers', 'nonce' );

    if ( ! basati_is_orders_enabled() ) {
        wp_send_json_error( [ 'code' => 'disabled', 'message' => 'Pedidos deshabilitados temporalmente.' ] );
        return;
    }

    $type    = WC()->session ? (string) WC()->session->get( 'basati_delivery_type', '' ) : '';
    $slot    = WC()->session ? (string) WC()->session->get( 'basati_slot', '' ) : '';
    $qty     = max( 1, intval( $_POST['quantity'] ?? 1 ) );
    $in_cart = WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;

    if ( $type && $slot ) {
        $date = current_time( 'Y-m-d' );
        if ( ! basati_slot_can_accept( $date, $type, $slot, $in_cart + $qty ) ) {
            $slots = basati_day_slots( $date, $type );
            $free  = max( 0, ( $slots[ $slot ]['available'] ?? 0 ) - $in_cart );
            $msg   = $free > 0
                ? "Solo quedan {$free} artículo(s) disponibles en la franja {$slot}."
                : "La franja {$slot} está llena. Por favor elige otra franja.";
            wp_send_json_error( [ 'code' => 'slot_full', 'message' => $msg ] );
            return;
        }
    }

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $quantity   = $qty;
    $modifiers  = isset( $_POST['revo_modifiers'] ) ? wp_unslash( $_POST['revo_modifiers'] ) : '';

    if ( ! $product_id ) { wp_send_json_error( 'Missing product_id' ); return; }

    $limit_error = basati_check_category_limit( $product_id );
    if ( $limit_error ) {
        wp_send_json_error( [ 'code' => 'category_limit', 'message' => $limit_error ] );
        return;
    }

    // Guardar en sesión ANTES de add_to_cart para que el filtro lo recoja
    if ( ! empty( $modifiers ) ) {
        WC()->session->set( 'basati_next_modifiers', $modifiers );
    }

    $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );

    if ( ! $cart_item_key ) {
        WC()->session->set( 'basati_next_modifiers', '' );
        wp_send_json_error( 'Could not add to cart' );
        return;
    }

    WC()->cart->calculate_totals();

    wp_send_json_success( [
        'cart_hash'     => WC()->cart->get_cart_hash(),
        'cart_quantity' => WC()->cart->get_cart_contents_count(),
        'fragments'     => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
    ] );
}
add_action( 'wp_ajax_basati_add_to_cart',        'basati_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_basati_add_to_cart', 'basati_ajax_add_to_cart' );

// Sumar precio de modificadores — siempre desde el precio base para evitar acumulación
add_action( 'woocommerce_before_calculate_totals', function( $cart ): void {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['revo_modifiers'] ) ) continue;

        $mods = json_decode( $cart_item['revo_modifiers'], true );
        if ( ! is_array( $mods ) ) continue;

        $extra = (float) array_sum( array_column( $mods, 'price' ) );

        // Usar siempre el precio base guardado para evitar acumulación en recálculos
        $base = isset( $cart_item['basati_base_price'] )
            ? (float) $cart_item['basati_base_price']
            : (float) $cart_item['data']->get_price( 'edit' );

        $cart_item['data']->set_price( $base + $extra );
    }
}, 30 );

// Mostrar modificadores — añadidos directamente al nombre del producto (funciona en todos los temas)
add_filter( 'woocommerce_cart_item_name', function( $product_name, $cart_item, $cart_item_key ) {
    if ( empty( $cart_item['revo_modifiers'] ) ) return $product_name;

    $mods = json_decode( $cart_item['revo_modifiers'], true );
    if ( ! is_array( $mods ) || empty( $mods ) ) return $product_name;

    $adds    = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'add' );
    $removes = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'remove' );

    $html = '<div class="basati-cart-mods" style="font-size:.82rem;margin-top:.3rem;line-height:1.5;">';

    if ( $adds ) {
        $labels = array_map( function( $m ) {
            $price = (float) $m['price'];
            $extra = $price > 0 ? ' <span style="color:#c0392b;font-weight:600;">(+' . number_format( $price, 2 ) . '€)</span>' : '';
            return esc_html( $m['name'] ) . $extra;
        }, $adds );
        $html .= '<span style="color:#27ae60;font-weight:600;">➕</span> ' . implode( ', ', $labels ) . '<br>';
    }

    if ( $removes ) {
        $labels = array_map( fn($m) => esc_html( $m['name'] ), $removes );
        $html .= '<span style="color:#e74c3c;font-weight:600;">➖ Sin:</span> ' . implode( ', ', $labels );
    }

    $html .= '</div>';

    return $product_name . $html;
}, 10, 3 );

// También mantener woocommerce_get_item_data para checkout
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
    if ( empty( $cart_item['revo_modifiers'] ) ) return $item_data;

    $mods = json_decode( $cart_item['revo_modifiers'], true );
    if ( ! is_array( $mods ) || empty( $mods ) ) return $item_data;

    $adds    = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'add' );
    $removes = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'remove' );

    if ( $adds ) {
        $labels = array_map( fn($m) => $m['name'] . ( $m['price'] > 0 ? ' (+' . number_format( $m['price'], 2 ) . '€)' : '' ), $adds );
        $item_data[] = [ 'key' => '➕ Añadido', 'value' => implode( ', ', $labels ) ];
    }
    if ( $removes ) {
        $item_data[] = [ 'key' => '➖ Sin', 'value' => implode( ', ', array_column( $removes, 'name' ) ) ];
    }

    return $item_data;
}, 10, 2 );

// Persistir modificadores al restaurar carrito de sesión
add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
    if ( ! empty( $values['revo_modifiers'] ) ) {
        $cart_item['revo_modifiers'] = $values['revo_modifiers'];
    }
    if ( isset( $values['basati_base_price'] ) ) {
        $cart_item['basati_base_price'] = $values['basati_base_price'];
    }
    return $cart_item;
}, 10, 2 );

// Guardar modificadores en el meta del pedido (línea de producto)
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( empty( $values['revo_modifiers'] ) ) return;
    $item->add_meta_data( 'revo_modifiers', $values['revo_modifiers'], true );
    $item->add_meta_data( 'basati_base_price', $values['basati_base_price'] ?? '', true );

    // Mostrar en el admin del pedido
    $mods = json_decode( $values['revo_modifiers'], true );
    if ( ! is_array( $mods ) ) return;

    $adds    = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'add' );
    $removes = array_filter( $mods, fn($m) => ( $m['type'] ?? '' ) === 'remove' );

    if ( $adds ) {
        $labels = array_map( fn($m) => $m['name'] . ( $m['price'] > 0 ? ' (+' . number_format( $m['price'], 2 ) . '€)' : '' ), $adds );
        $item->add_meta_data( '➕ Añadido', implode( ', ', $labels ), true );
    }
    if ( $removes ) {
        $item->add_meta_data( '➖ Sin', implode( ', ', array_column( $removes, 'name' ) ), true );
    }
}, 10, 4 );

// Incluir JS del popup
add_action( 'wp_footer', function() {
    if ( ! function_exists( 'WC' ) ) return;
    $nonce = wp_create_nonce( 'basati_modifiers' );
    ?>
<?php if ( ! basati_is_orders_enabled() ) : ?>
<div id="basati-closed-banner" style="background:#c0392b;color:#fff;text-align:center;padding:12px 20px;font-size:.95rem;font-weight:600;position:sticky;top:0;z-index:9998;letter-spacing:.02em;">
    ⚠️ Los pedidos están temporalmente deshabilitados
</div>
<?php endif; ?>
<style>
.basati-mods-toggle{background:none;border:none;padding:0;cursor:pointer;color:#c0392b;font-size:.88rem;font-weight:600;display:flex;align-items:center;gap:.35rem;margin:.4rem 0 0;text-decoration:none}
.basati-mods-toggle:hover{text-decoration:underline}
.basati-mods-toggle .bmt-arrow{font-size:.7rem;transition:transform .2s}
.basati-mods-toggle.open .bmt-arrow{transform:rotate(180deg)}
.basati-mods-content{margin-top:.7rem}
/* ── Selector de tipo y franja ── */
.basati-slot-section{margin-bottom:.8rem}
.basati-type-row{display:flex;gap:.5rem;margin-bottom:.75rem}
.basati-type-btn{flex:1;padding:.55rem .4rem;border:2px solid #ddd;border-radius:6px;background:#f8f8f8;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .15s;color:#555}
.basati-type-btn.active{border-color:#c0392b;background:#c0392b;color:#fff}
.basati-slot-label{font-size:.78rem;font-weight:700;color:#666;margin-bottom:.4rem}
.basati-slot-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.3rem;max-height:160px;overflow-y:auto}
.basati-slot-btn{padding:.38rem .2rem;border:1.5px solid #ddd;border-radius:5px;background:#f9f9f9;font-size:.78rem;cursor:pointer;text-align:center;transition:all .15s;line-height:1.2}
.basati-slot-btn .bsb-time{display:block;font-weight:700;font-size:.82rem}
.basati-slot-btn .bsb-avail{display:block;font-size:.68rem;color:#888}
.basati-slot-btn.slot-free{border-color:#27ae60;background:#f0faf0}.basati-slot-btn.slot-free .bsb-avail{color:#27ae60}
.basati-slot-btn.slot-mid{border-color:#f39c12;background:#fffbf0}.basati-slot-btn.slot-mid .bsb-avail{color:#f39c12}
.basati-slot-btn.slot-low{border-color:#e67e22;background:#fff8f0}.basati-slot-btn.slot-low .bsb-avail{color:#e67e22}
.basati-slot-btn.slot-full{border-color:#ddd;background:#f5f5f5;opacity:.5;cursor:not-allowed}
.basati-slot-btn.slot-chosen{border-color:#c0392b!important;background:#c0392b!important;color:#fff!important}
.basati-slot-btn.slot-chosen .bsb-avail{color:rgba(255,255,255,.8)!important}
.basati-slot-divider{border:none;border-top:1px solid #eee;margin:.6rem 0}
.basati-slot-hint{font-size:.75rem;color:#aaa;margin:.3rem 0 0;text-align:center}
</style>
<div id="basati-modifier-modal" style="display:none">
  <div class="basati-modal-overlay"></div>
  <div class="basati-modal-box">
    <button class="basati-modal-close">&times;</button>
    <h3 class="basati-modal-title"></h3>
    <div class="basati-modal-body">
      <div class="basati-slot-section">
        <div class="basati-type-row">
          <button type="button" class="basati-type-btn" data-type="delivery">🛵 Domicilio</button>
          <button type="button" class="basati-type-btn" data-type="pickup">🏪 Recoger</button>
        </div>
        <div class="basati-slot-label" id="basati-slot-label" style="display:none">Elige tu franja horaria:</div>
        <div id="basati-slot-grid" class="basati-slot-grid" style="display:none"></div>
        <p class="basati-slot-hint" id="basati-slot-hint">Selecciona cómo quieres el pedido</p>
      </div>
      <hr class="basati-slot-divider">
      <button type="button" class="basati-mods-toggle" id="basati-mods-toggle" style="display:none">
        Modificar <span class="bmt-arrow">▼</span>
      </button>
      <div class="basati-mods-content" id="basati-mods-content" style="display:none"></div>
    </div>
    <div class="basati-modal-footer">
      <span class="basati-modal-total"></span>
      <button class="basati-modal-confirm button alt" disabled><?php esc_html_e( 'Añadir al carrito', 'basati' ); ?></button>
    </div>
  </div>
</div>
<script>
(function($){
    var ajaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
    var nonce   = '<?php echo esc_js( $nonce ); ?>';
    var pending = null;
    var chosenType = '<?php echo esc_js( WC()->session ? (string)WC()->session->get('basati_delivery_type','') : '' ); ?>';
    var chosenSlot = '<?php echo esc_js( WC()->session ? (string)WC()->session->get('basati_slot','') : '' ); ?>';
    var basatiConfig = {
        pedidosActivos: <?php echo basati_is_orders_enabled() ? 'true' : 'false'; ?>,
        extrasActivos:  <?php echo basati_is_extras_enabled()  ? 'true' : 'false'; ?>,
        msgs: { disabled: 'Pedidos deshabilitados temporalmente. Vuelve a intentarlo más tarde.' }
    };

    // ── Franjas horarias ─────────────────────────────────────────────────────

    function renderSlotGrid(slots, qty) {
        var html = '';
        Object.keys(slots).forEach(function(slot) {
            var d    = slots[slot];
            var avail = d.available;
            var canFit = avail >= qty && d.total < d.max;
            var pct  = d.max > 0 ? d.total / d.max : 0;
            var cls  = d.total >= d.max ? 'slot-full' : (pct >= 0.85 ? 'slot-low' : (pct >= 0.5 ? 'slot-mid' : 'slot-free'));
            if (slot === chosenSlot) cls += ' slot-chosen';
            var availLabel = d.total >= d.max ? 'Lleno' : (avail === 1 ? '1 libre' : avail + ' libres');
            var dis = canFit ? '' : ' disabled';
            html += '<button type="button" class="basati-slot-btn ' + cls + '" data-slot="' + slot + '"' + dis + '>'
                  + '<span class="bsb-time">' + slot + '</span>'
                  + '<span class="bsb-avail">' + availLabel + '</span>'
                  + '</button>';
        });
        return html || '<p style="color:#aaa;font-size:.82rem;text-align:center;padding:.5rem">Sin franjas disponibles</p>';
    }

    function loadSlots(basePrice) {
        if (!chosenType) return;
        var qty = pending ? pending.quantity : 1;
        $('#basati-slot-grid').html('<p style="color:#aaa;font-size:.82rem;text-align:center;padding:.5rem">Cargando...</p>').show();
        $('#basati-slot-label').show();
        $('#basati-slot-hint').hide();
        $.post(ajaxUrl, { action: 'basati_slots', nonce: nonce, type: chosenType }, function(res) {
            if (!res.success) return;
            $('#basati-slot-grid').html(renderSlotGrid(res.data.slots, qty));
            // Verificar si el slot elegido sigue disponible
            if (chosenSlot && res.data.slots[chosenSlot]) {
                var d = res.data.slots[chosenSlot];
                if (d.total >= d.max || d.available < qty) { chosenSlot = ''; }
            }
            updateConfirmBtn(basePrice);
        });
    }

    function updateConfirmBtn(basePrice) {
        var ok = !!(chosenType && chosenSlot);
        $('.basati-modal-confirm').prop('disabled', !ok);
        if (ok && basePrice !== undefined) updateModalTotal(basePrice);
    }

    function updateTypeButtons() {
        $('.basati-type-btn').removeClass('active');
        if (chosenType) $('.basati-type-btn[data-type="' + chosenType + '"]').addClass('active');
    }

    // Click en tipo de pedido
    $(document).on('click', '.basati-type-btn', function() {
        var newType = $(this).data('type');
        chosenType  = newType;
        chosenSlot  = '';
        updateTypeButtons();
        updateConfirmBtn();
        $.post(ajaxUrl, { action: 'basati_set_slot', nonce: nonce, type: chosenType, slot: '' });
        loadSlots(pending ? pending.basePrice : 0);
    });

    // Click en franja
    $(document).on('click', '.basati-slot-btn:not([disabled])', function() {
        chosenSlot = $(this).data('slot');
        $('.basati-slot-btn').removeClass('slot-chosen');
        $(this).addClass('slot-chosen');
        $.post(ajaxUrl, { action: 'basati_set_slot', nonce: nonce, type: chosenType, slot: chosenSlot });
        updateConfirmBtn(pending ? pending.basePrice : 0);
    });

    function showBasatiNotice(msg, type) {
        var color = type === 'error' ? '#c0392b' : '#f39c12';
        var $n = $('<div style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:' + color + ';color:#fff;padding:14px 28px;border-radius:8px;font-size:1rem;font-weight:600;z-index:999999;box-shadow:0 4px 20px rgba(0,0,0,.3);max-width:90vw;text-align:center;pointer-events:none;">' + $('<span>').text(msg).html() + '</div>');
        $('body').append($n);
        setTimeout(function(){ $n.fadeOut(400, function(){ $(this).remove(); }); }, 4500);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function modifierPrice(mod) {
        return parseFloat(String(mod.price || 0).replace(',', '.')) || 0;
    }

    function buildModifiersHtml(categories) {
        var html = '';
        categories.forEach(function(cat) {
            var isAdd = cat.type === 'add';
            var groupPrefix = isAdd ? '+ ' : '- ';
            html += '<div class="basati-mod-group">';
            html += '<p class="basati-mod-group-title">' + groupPrefix + escapeHtml(cat.name) + '</p>';
            html += '<div class="basati-mod-items">';
            cat.modifiers.forEach(function(mod) {
                var price = modifierPrice(mod);
                var priceLabel = price > 0 ? ' <span class="basati-mod-price">+' + price.toFixed(2) + '€</span>' : '';
                html += '<label class="basati-mod-item">'
                     + '<input type="checkbox"'
                     + ' data-id="'    + escapeHtml(mod.id) + '"'
                     + ' data-name="'  + escapeHtml(mod.name) + '"'
                     + ' data-price="' + price + '"'
                     + ' data-type="'  + escapeHtml(cat.type) + '">'
                     + ' <span class="basati-mod-name">' + escapeHtml(mod.name) + '</span>' + priceLabel
                     + '</label>';
            });
            html += '</div></div>';
        });
        return html;
    }

    function collectSelected($scope) {
        var selected = [];
        $scope.find('input[type=checkbox]:checked').each(function() {
            selected.push({
                id:    $(this).data('id'),
                name:  $(this).data('name'),
                price: parseFloat($(this).data('price')) || 0,
                type:  $(this).data('type')
            });
        });
        return selected;
    }

    function getProductId($context, $btn) {
        var productId = 0;
        if ($btn && $btn.length) {
            productId = $btn.data('product_id') || $btn.val();
        }

        productId = productId
                 || $context.find('.single_add_to_cart_button').data('product_id')
                 || $context.find('.add_to_cart_button').data('product_id')
                 || $context.find('input[name=add-to-cart]').val()
                 || $context.find('button[name=add-to-cart]').val()
                 || $context.closest('form.cart').find('button[name=add-to-cart]').val();

        if (!productId) {
            var className = ($context.closest('.product').attr('class') || '') + ' ' + ($('body').attr('class') || '');
            var match = className.match(/(?:post-|postid-)(\d+)/);
            if (match) productId = match[1];
        }

        return parseInt(productId, 10) || 0;
    }

    function hydrateLiteralShortcodes($root) {
        var shortcodeHtml = '<div class="basati-mod-shortcode" data-nonce="' + nonce + '"><div class="basati-mod-sc-content"></div><div class="basati-mod-sc-total" aria-live="polite"></div></div>';
        $root.find('.elementor-widget-container, .elementor-heading-title, p, span, div, h1, h2, h3, h4').addBack().filter(function() {
            var $el = $(this);
            return $el.children().length === 0 && $.trim($el.text()) === '[revo_modificadores]';
        }).replaceWith(shortcodeHtml);
    }

    function updateShortcodeExtra($sc) {
        var extra = 0;
        $sc.find('input[type=checkbox]:checked').each(function() {
            extra += parseFloat($(this).data('price')) || 0;
        });

        $sc.find('.basati-mod-sc-total').text(extra > 0 ? 'Extras: +' + extra.toFixed(2) + '€' : '');
    }

    function doAddToCart(productId, quantity, selected, $btn) {
        $.post(ajaxUrl, {
            action:         'basati_add_to_cart',
            nonce:          nonce,
            product_id:     productId,
            quantity:       quantity,
            revo_modifiers: selected.length > 0 ? JSON.stringify(selected) : ''
        }, function(res) {
            if (res.success) {
                $(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, $btn]);
            } else if (res.data && res.data.message) {
                showBasatiNotice(res.data.message, 'error');
            }
        });
    }

    // ── Modo 1: Shortcode [revo_modificadores] dentro del popup de Elementor ─

    function loadModifiersIntoShortcode($sc) {
        var $form = $sc.closest('.elementor-popup-modal, .elementor-location-popup, form.cart, .product');
        var productId = getProductId($form.length ? $form : $sc, $());

        if (!productId) {
            $sc.find('.basati-mod-sc-content, .basati-mod-sc-total').empty();
            return;
        }

        $sc.data('product-id', productId);
        $sc.find('.basati-mod-sc-content').html('<p style="color:#999;font-size:.85rem">Cargando opciones...</p>');

        $.post(ajaxUrl, {
            action:     'basati_get_modifiers',
            nonce:      $sc.data('nonce'),
            product_id: productId
        }, function(res) {
            if (res.success && res.data && res.data.length > 0) {
                $sc.find('.basati-mod-sc-content').html(buildModifiersHtml(res.data));
                updateShortcodeExtra($sc);
            } else {
                $sc.find('.basati-mod-sc-content, .basati-mod-sc-total').empty();
            }
        });
    }

    // Inicializar shortcodes visibles al cargar
    hydrateLiteralShortcodes($(document.body));
    $('.basati-mod-shortcode:visible').each(function() { loadModifiersIntoShortcode($(this)); });

    // Inicializar cuando Elementor abre un popup
    $(document).on('elementor/popup/show', function() {
        hydrateLiteralShortcodes($(document.body));
        $('.basati-mod-shortcode:visible').each(function() { loadModifiersIntoShortcode($(this)); });
    });

    $(document).on('change', '.basati-mod-shortcode input[type=checkbox]', function() {
        updateShortcodeExtra($(this).closest('.basati-mod-shortcode'));
    });

    // Interceptar "AÑADIR AL CARRITO" dentro del popup con shortcode
    $(document).on('click', '.single_add_to_cart_button', function(e) {
        var $btn = $(this);
        var $sc  = $('.basati-mod-shortcode:visible').first();
        if ($sc.length === 0) return; // Sin shortcode visible → comportamiento normal

        e.preventDefault();
        e.stopPropagation();

        if (!basatiConfig.pedidosActivos) { showBasatiNotice(basatiConfig.msgs.disabled, 'error'); return; }

        var productId = $sc.data('product-id') || getProductId($btn.closest('.elementor-popup-modal, .elementor-location-popup, form.cart, .product'), $btn);
        var quantity  = parseInt($btn.closest('form').find('.qty').val()) || 1;
        var selected  = collectSelected($sc);

        if (!productId) return;

        doAddToCart(productId, quantity, selected, $btn);

        // Actualizar precio visible en el popup
        updatePopupPrice($sc, $btn);
    });

    function updatePopupPrice($sc, $btn) {
        var extra = 0;
        $sc.find('input[type=checkbox]:checked').each(function() {
            extra += parseFloat($(this).data('price')) || 0;
        });
        if (extra > 0) {
            var $price = $btn.closest('.elementor-popup-modal, .product').find('.woocommerce-Price-amount').first();
            $price.closest('.price').find('.basati-extra-price').remove();
            $price.closest('.price').append('<span class="basati-extra-price" style="color:#c0392b;font-weight:700;margin-left:.5rem">+' + extra.toFixed(2) + '€ extras</span>');
        }
    }

    // ── Selector de tamaño Entera/Media en el menú (pizzas fusionadas) ───────

    $(document).on('click', '.basati-size-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn  = $(this);
        var $wrap = $btn.closest('.basati-size-wrap');

        $wrap.find('.basati-size-btn').removeClass('active');
        $btn.addClass('active');
        $wrap.find('.basati-cart-btn').data({
            'product-id': $btn.data('product-id'),
            'price':      $btn.data('price'),
            'name':       $btn.data('name')
        });
    });

    // ── Modo 2: Botón [basati_carrito] ───────────────────────────────────────

    $(document).on('click', '.basati-cart-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!basatiConfig.pedidosActivos) { showBasatiNotice(basatiConfig.msgs.disabled, 'error'); return; }

        var $btn      = $(this);
        var productId = $btn.data('product-id');
        var basePrice = parseFloat($btn.data('price')) || 0;
        var name      = $btn.data('name') || '';

        if (!productId) return;

        pending = { $btn: $btn, productId: productId, basePrice: basePrice, quantity: 1 };

        if (!basatiConfig.extrasActivos) {
            openModal([], basePrice, name);
            return;
        }
        $btn.addClass('loading');
        $.post(ajaxUrl, { action: 'basati_get_product_popup', nonce: nonce, product_id: productId }, function(res) {
            $btn.removeClass('loading');
            openModal(res.success ? (res.data.modifiers || []) : [], res.success ? res.data.price : basePrice, res.success ? res.data.name : name);
        }).fail(function() { $btn.removeClass('loading'); openModal([], basePrice, name); });
    });

    // ── Modo 3: Botones WooCommerce nativos del menú ──────────────────────────

    $(document).on('click', '.add_to_cart_button', function(e) {
        var $btn      = $(this);
        var productId = $btn.data('product_id');
        var basePrice = parseFloat($btn.closest('.basati-menu-item').find('.basati-menu-item-precio .woocommerce-Price-amount').first().text().replace(/[^0-9.,]/g,'').replace(',','.')) || 0;
        var name      = $btn.closest('.basati-menu-item').find('.basati-menu-item-nombre').text() || '';

        if (!productId) return;

        e.preventDefault();
        e.stopPropagation();

        if (!basatiConfig.pedidosActivos) { showBasatiNotice(basatiConfig.msgs.disabled, 'error'); return; }

        pending = { $btn: $btn, productId: productId, basePrice: basePrice, quantity: 1 };

        if (!basatiConfig.extrasActivos) {
            openModal([], basePrice, name);
            return;
        }
        $.post(ajaxUrl, { action: 'basati_get_product_popup', nonce: nonce, product_id: productId }, function(res) {
            openModal(res.success ? (res.data.modifiers || []) : [], res.success ? res.data.price : basePrice, res.success ? res.data.name : name);
        }).fail(function() { openModal([], basePrice, name); });
    });

    function openModal(categories, basePrice, productName) {
        var $modal   = $('#basati-modifier-modal');
        var $toggle  = $('#basati-mods-toggle');
        var $content = $('#basati-mods-content');

        $modal.find('.basati-modal-title').text(productName);

        // Modifiers section
        if (categories && categories.length > 0) {
            $content.html(buildModifiersHtml(categories)).hide();
            $toggle.removeClass('open').show();
        } else {
            $content.empty().hide();
            $toggle.hide();
        }

        // Slot section: restore session state or start fresh
        updateTypeButtons();
        if (chosenType) {
            loadSlots(basePrice);
        } else {
            $('#basati-slot-label').hide();
            $('#basati-slot-grid').hide().empty();
            $('#basati-slot-hint').show().text('Selecciona cómo quieres el pedido');
        }

        updateModalTotal(basePrice);
        updateConfirmBtn(basePrice);
        $modal.show();
        $modal.off('change').on('change', 'input[type=checkbox]', function() { updateModalTotal(basePrice); });
    }

    $(document).on('click', '#basati-mods-toggle', function() {
        var $toggle  = $(this);
        var $content = $('#basati-mods-content');
        if ($content.is(':visible')) {
            $content.slideUp(200);
            $toggle.removeClass('open');
        } else {
            $content.slideDown(200);
            $toggle.addClass('open');
        }
    });

    function updateModalTotal(basePrice) {
        var extra = 0;
        $('#basati-modifier-modal input[type=checkbox]:checked').each(function() {
            extra += parseFloat($(this).data('price')) || 0;
        });
        var total = (basePrice + extra) * (pending ? pending.quantity : 1);
        $('#basati-modifier-modal .basati-modal-total').text('Total: ' + total.toFixed(2) + '€');
    }

    function closeModal() {
        $('#basati-modifier-modal').hide().off('change');
        pending = null;
    }

    function confirmAddToCart(selected) {
        if (!pending) return;
        var p = pending;
        closeModal();
        doAddToCart(p.productId, p.quantity, selected, p.$btn);
    }

    $(document).on('click', '.basati-modal-confirm', function() {
        confirmAddToCart(collectSelected($('#basati-modifier-modal')));
    });
    $(document).on('click', '.basati-modal-close, .basati-modal-overlay', closeModal);

})(jQuery);
</script>
    <?php
} );

// ─── SHORTCODE: [basati_carrito] — botón + que abre el popup ─────────────────
// Uso: [basati_carrito] dentro del loop de productos
// Uso: [basati_carrito product_id="270"] con ID explícito

function basati_carrito_shortcode( $atts ): string {
    if ( ! function_exists( 'WC' ) ) return '';

    $atts       = shortcode_atts( [ 'product_id' => 0 ], $atts );
    $product_id = intval( $atts['product_id'] ) ?: get_the_ID();
    if ( ! $product_id ) return '';

    $product = wc_get_product( $product_id );
    if ( ! $product ) return '';

    $boton = sprintf(
        '<button class="basati-cart-btn" data-product-id="%d" data-price="%s" data-name="%s" aria-label="Añadir al carrito">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
        </button>',
        $product_id,
        esc_attr( $product->get_price() ),
        esc_attr( $product->get_name() )
    );

    // Si esta pizza tiene una versión "media" homónima en Pizza Erdiak,
    // ofrecemos un selector Entera/Media justo antes de añadir al carrito.
    if ( ! has_term( 'pizza-erdiak', 'product_cat', $product_id ) ) {
        $erdi_id = basati_erdi_products_by_title()[ mb_strtolower( trim( $product->get_name() ) ) ] ?? null;
        $erdi    = $erdi_id ? wc_get_product( $erdi_id ) : null;

        if ( $erdi ) {
            $nombre = $product->get_name();
            return sprintf(
                '<div class="basati-size-wrap">
                    <div class="basati-size-toggle">
                        <button type="button" class="basati-size-btn active" data-size="entera" data-product-id="%1$d" data-price="%2$s" data-name="%3$s">Entera <span class="basati-size-btn-price">%4$s</span></button>
                        <button type="button" class="basati-size-btn" data-size="media" data-product-id="%5$d" data-price="%6$s" data-name="%7$s">Media <span class="basati-size-btn-price">%8$s</span></button>
                    </div>
                    %9$s
                </div>',
                $product_id,
                esc_attr( $product->get_price() ),
                esc_attr( $nombre . ' (Entera)' ),
                wp_kses_post( $product->get_price_html() ),
                $erdi_id,
                esc_attr( $erdi->get_price() ),
                esc_attr( $nombre . ' (Media)' ),
                wp_kses_post( $erdi->get_price_html() ),
                $boton
            );
        }
    }

    return $boton;
}
add_shortcode( 'basati_carrito', 'basati_carrito_shortcode' );

// AJAX: devuelve nombre, precio y modificadores del producto en una sola llamada
function basati_get_product_popup(): void {
    check_ajax_referer( 'basati_modifiers', 'nonce' );
    $product_id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) { wp_send_json_error( 'Missing product_id' ); return; }

    $product = wc_get_product( $product_id );
    if ( ! $product ) { wp_send_json_error( 'Product not found' ); return; }

    if ( ! basati_is_extras_enabled() ) {
        wp_send_json_success( [ 'id' => $product_id, 'name' => $product->get_name(), 'price' => (float) $product->get_price(), 'modifiers' => [] ] );
        return;
    }

    // Obtener modificadores
    $config = get_option( 'revo_modifiers_config', [] );
    $terms  = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
    $catNames = array_map( 'strtolower', is_array( $terms ) ? $terms : [] );
    $modifiers = [];

    foreach ( $config['categories'] ?? [] as $cat ) {
        if ( empty( $cat['enabled'] ) ) continue;
        $appliesTo = array_map( 'strtolower', $cat['applies_to'] ?? [] );
        if ( empty( array_intersect( $catNames, $appliesTo ) ) ) continue;
        $enabledMods = array_values( array_filter( $cat['modifiers'], fn($m) => ! empty( $m['enabled'] ) ) );
        if ( empty( $enabledMods ) ) continue;
        $modifiers[] = [ 'id' => $cat['id'], 'name' => $cat['name'], 'type' => $cat['type'], 'modifiers' => $enabledMods ];
    }

    wp_send_json_success( [
        'id'        => $product_id,
        'name'      => $product->get_name(),
        'price'     => (float) $product->get_price(),
        'modifiers' => $modifiers,
    ] );
}
add_action( 'wp_ajax_basati_get_product_popup',        'basati_get_product_popup' );
add_action( 'wp_ajax_nopriv_basati_get_product_popup', 'basati_get_product_popup' );

// ─── SHORTCODE: [revo_modificadores] para popup de Elementor ─────────────────
// Colócalo en el popup de Elementor donde quieres que aparezcan los checkboxes

function basati_revo_modificadores(): string {
    if ( ! function_exists( 'WC' ) ) return '';
    $nonce = wp_create_nonce( 'basati_modifiers' );
    return '<div class="basati-mod-shortcode" data-nonce="' . esc_attr( $nonce ) . '">
        <div class="basati-mod-sc-content"></div>
        <div class="basati-mod-sc-total" aria-live="polite"></div>
    </div>';
}
add_shortcode( 'revo_modificadores', 'basati_revo_modificadores' );

// Algunos widgets de Elementor imprimen el texto literal si no son de tipo "Shortcode".
add_filter( 'widget_text', 'do_shortcode' );
add_filter( 'elementor/widget/render_content', function( $content ) {
    return is_string( $content ) && false !== strpos( $content, '[revo_modificadores]' )
        ? do_shortcode( $content )
        : $content;
}, 10 );

// ─── Mapa de pizzas medias (categoría "pizza-erdiak") por nombre ─────────────
// Permite fusionar cada pizza entera con su versión "media" del mismo nombre.

function basati_erdi_products_by_title(): array {
    static $map = null;
    if ( $map !== null ) return $map;

    $map  = [];
    $term = get_term_by( 'slug', 'pizza-erdiak', 'product_cat' );
    if ( $term ) {
        $query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => array_map( 'intval', (array) get_option( 'basati_hidden_products', [] ) ),
            'tax_query'      => [ [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => 'pizza-erdiak',
            ] ],
        ] );
        foreach ( $query->posts as $post ) {
            $map[ mb_strtolower( trim( $post->post_title ) ) ] = $post->ID;
        }
        wp_reset_postdata();
    }
    return $map;
}

// ─── SHORTCODE: Menú por categoría ───────────────────────────────────────────
// Uso: [menu_categoria categoria="pizzas" emoji="🍕"]

function basati_menu_categoria( $atts ) {
    $atts = shortcode_atts( [
        'categoria' => '',
        'emoji'     => '',
        'titulo'    => '',
    ], $atts );

    if ( empty( $atts['categoria'] ) ) return '';

    // Obtener la categoría WooCommerce
    $term = get_term_by( 'slug', sanitize_title( $atts['categoria'] ), 'product_cat' );
    if ( ! $term ) return '<p>Categoría no encontrada: ' . esc_html( $atts['categoria'] ) . '</p>';

    $titulo = ! empty( $atts['titulo'] ) ? $atts['titulo'] : strtoupper( $term->name );
    $emoji  = ! empty( $atts['emoji'] ) ? ' ' . $atts['emoji'] : '';

    // Consulta de productos
    $query = new WP_Query( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'post__not_in'   => array_map( 'intval', (array) get_option( 'basati_hidden_products', [] ) ),
        'tax_query'      => [ [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $atts['categoria'],
        ] ],
    ] );

    if ( ! $query->have_posts() ) return '';

    ob_start(); ?>

    <div class="basati-menu-seccion">
        <h2 class="basati-menu-titulo"><?php echo esc_html( $titulo . $emoji ); ?></h2>
        <div class="basati-menu-grid">
        <?php while ( $query->have_posts() ) : $query->the_post();
            global $product;
            $product    = wc_get_product( get_the_ID() );
            $nombre     = get_the_title();
            $precio     = $product->get_price_html();
            $descripcion = get_the_excerpt();
            $enlace     = get_permalink();
        ?>
            <div class="basati-menu-item">
                <div class="basati-menu-item-header">
                    <span class="basati-menu-item-nombre"><?php echo esc_html( $nombre ); ?></span>
                    <span class="basati-menu-item-puntos"></span>
                    <span class="basati-menu-item-precio"><?php echo wp_kses_post( $precio ); ?></span>
                </div>
                <?php if ( $descripcion ) : ?>
                    <p class="basati-menu-item-desc"><?php echo esc_html( $descripcion ); ?></p>
                <?php endif; ?>
                <div class="basati-menu-item-actions">
                    <?php woocommerce_template_loop_add_to_cart(); ?>
                </div>
            </div>
        <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>

    <?php return ob_get_clean();
}
add_shortcode( 'menu_categoria', 'basati_menu_categoria' );

// ─── DESCRIPCIÓN CORTA DEL PRODUCTO EN EL LISTADO ────────────────────────────
add_action( 'ocean_after_archive_product_title', function() {
    global $product;
    if ( ! $product ) return;
    $desc = $product->get_short_description();
    if ( $desc ) {
        echo '<li class="basati-loop-desc">' . wp_kses_post( $desc ) . '</li>';
    }
} );
