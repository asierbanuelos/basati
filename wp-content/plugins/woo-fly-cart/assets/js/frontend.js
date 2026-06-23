'use strict';

(function ($) {
    var woofc_timeout = null;

    $(function () {
        // Reload the cart
        if (woofc_vars.reload === 'yes') {
            woofc_cart_reload();
        }

        // Auto show
        if ((woofc_vars.auto_show_normal === 'yes') && (woofc_vars.show_cart === 'yes')) {
            setTimeout(function () {
                if (woofc_vars.instant_checkout === 'yes' && woofc_vars.instant_checkout_open === 'yes') {
                    woofc_show_cart('checkout');
                } else {
                    woofc_show_cart();
                }
            }, woofc_vars.delay);
        }

        if ((woofc_vars.auto_show_normal === 'yes') && (woofc_vars.show_cart !== 'yes') && (woofc_vars.show_checkout === 'yes') && (woofc_vars.instant_checkout === 'yes')) {
            setTimeout(function () {
                woofc_show_cart('checkout');
            }, woofc_vars.delay);
        }
    });

    // Quick view
    $(document).on('click touch', '#woofc-area .woosq-link, #woofc-area .woosq-btn', function (e) {
        woofc_hide_cart();
        e.preventDefault();
    });

    // Auto show
    $(document.body).on('added_to_cart', function (e, fragments, cart_hash, $button) {
        if (woofc_vars.auto_show === 'yes') {
            setTimeout(function () {
                woofc_show_cart();
            }, woofc_vars.delay);
        }

        if ($button && $button.closest('.woofc-save-for-later').length) {
            // refresh again
            $(document.body).trigger('wc_fragment_refresh');
        }
    });

    $(document.body).on('wc_fragments_loaded', function () {
        woofc_cart_loaded();
    });

    $(document.body).on('wc_fragments_refreshed', function () {
        woofc_cart_loaded();
    });

    // Reload cart/checkout
    $(document.body).on('woofc_cart_reload', function () {
        if (woofc_vars.is_cart && $('form.woocommerce-cart-form').length) {
            $(document.body).trigger('wc_update_cart');
        }

        if (woofc_vars.is_checkout && $('form.woocommerce-checkout').length) {
            $(document.body).trigger('update_checkout', {update_shipping_method: false});
        }
    });

    // Manual show
    if (woofc_vars.manual_show !== '') {
        $(document).on('click touch', woofc_vars.manual_show, function (e) {
            woofc_toggle_cart();
            e.preventDefault();
        });
    }

    // Qty minus & plus
    $(document).on('click touch', '.woofc-item-qty-plus, .woofc-item-qty-minus', function () {
        // get values
        var $qty = $(this).closest('.woofc-item-qty').find('.qty'), val = parseFloat($qty.val()),
            max = parseFloat($qty.attr('max')), min = parseFloat($qty.attr('min')), step = $qty.attr('step');

        // format values
        if (!val || val === '' || val === 'NaN') {
            val = 0;
        }

        if (max === '' || max === 'NaN') {
            max = '';
        }

        if (min === '' || min === 'NaN') {
            min = 0;
        }

        if (step === 'any' || step === '' || step === undefined || parseFloat(step) === 'NaN') {
            step = 1;
        } else {
            step = parseFloat(step);
        }

        // change the value
        if ($(this).is('.woofc-item-qty-plus')) {
            if (max && (max === val || val > max)) {
                $qty.val(max);
            } else {
                $qty.val((val + step).toFixed(woofc_decimal_places(step)));
            }
        } else {
            if (val - step <= 0) {
                // remove item
                if ((woofc_vars.confirm_remove === 'yes')) {
                    if (confirm(woofc_vars.confirm_remove_text)) {
                        woofc_remove_item($qty.closest('.woofc-item'));
                    }
                } else {
                    woofc_remove_item($qty.closest('.woofc-item'));
                }

                return false;
            }

            if (min && (min === val || val < min)) {
                $qty.val(min);
            } else if (val > 0) {
                $qty.val((val - step).toFixed(woofc_decimal_places(step)));
            }
        }

        // trigger change event
        $qty.trigger('change');
    });

    // Qty on change
    $(document).on('change', '.woofc-area .qty', function () {
        var item_key = $(this).closest('.woofc-item').attr('data-key');
        var item_qty = $(this).val();

        woofc_update_qty(item_key, item_qty);
    });

    // Qty validate
    $(document).on('keyup', '.woofc-area .qty', function () {
        var $this = $(this);

        if ($this.closest('.woopq-quantity-input').length) {
            // checked in WPC Product Quantity
            return;
        }

        if (woofc_timeout != null) clearTimeout(woofc_timeout);
        woofc_timeout = setTimeout(woofc_check_qty, 1000, $this);
    });

    // Remove item
    $(document).on('click touch', '.woofc-area .woofc-item-remove', function () {
        if (woofc_vars.confirm_remove === 'yes') {
            if (confirm(woofc_vars.confirm_remove_text)) {
                woofc_remove_item($(this).closest('.woofc-item'));
            }
        } else {
            woofc_remove_item($(this).closest('.woofc-item'));
        }
    });

    $(document).on('click touch', '.woofc-overlay', function () {
        woofc_hide_cart();
    });

    $(document).on('click touch', '.woofc-close', function () {
        woofc_hide_cart();
    });

    $(document).on('click touch', '.woofc-continue-url', function () {
        var url = $(this).attr('data-url');

        woofc_hide_cart();

        if (url !== '') {
            window.location.href = url;
        }
    });

    $(document).on('click touch', '.woofc-empty-cart', function () {
        var data = {
            action: 'woofc_empty_cart', nonce: woofc_nonce(),
        };

        if (woofc_vars.confirm_empty === 'yes') {
            if (confirm(woofc_vars.confirm_empty_text)) {
                woofc_cart_loading();

                $.post(woofc_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woofc_empty_cart'), data, function (response) {
                    woofc_cart_reload();
                    $(document.body).trigger('woofc_cart_emptied');
                });
            }
        } else {
            woofc_cart_loading();

            $.post(woofc_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woofc_empty_cart'), data, function (response) {
                woofc_cart_reload();
                $(document.body).trigger('woofc_cart_emptied');
            });
        }
    });

    // Count button
    $(document).on('click touch', '.woofc-count', function (e) {
        woofc_toggle_cart();

        e.preventDefault();
    });

    // Menu item
    $(document).on('click touch', '.woofc-menu-item a', function (e) {
        if (woofc_vars.cart_url != '') {
            window.location.href = woofc_vars.cart_url;
        } else {
            woofc_toggle_cart();
        }

        e.preventDefault();
    });

    // Cart
    $(document).on('click touch', '.woofc-cart, .woofc-btn', function (e) {
        woofc_toggle_cart();

        e.preventDefault();
    });

    // Cart link
    $(document).on('click touch', '.woofc-cart-link a', function (e) {
        if (woofc_vars.cart_url != '') {
            window.location.href = woofc_vars.cart_url;
        } else {
            woofc_toggle_cart();
        }

        e.preventDefault();
    });

    $(document).on('updated_checkout', function (e) {
        woofc_slick();
        woofc_perfect_scrollbar();
    });

    $(document).on('click touch', '.woofc-undo a', function (e) {
        e.preventDefault();
        woofc_cart_loading();

        var undo_key = $('body').attr('woofc-undo-key');
        var data = {
            action: 'woofc_undo_remove', item_key: undo_key, nonce: woofc_nonce(),
        };

        $.post(woofc_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woofc_undo_remove'), data, function (response) {
            woofc_cart_reload();
        });

        $('body').attr('woofc-undo-key', '');
        $('body').attr('woofc-undo-name', '');
    });
})(jQuery);

function woofc_decimal_places(num) {
    var match = ('' + num).match(/(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/);

    if (!match) {
        return 0;
    }

    return Math.max(0, // Number of digits right of decimal point.
        (match[1] ? match[1].length : 0)
        // Adjust for scientific notation.
        - (match[2] ? +match[2] : 0));
}

function woofc_update_qty(cart_item_key, cart_item_qty) {
    woofc_cart_loading();

    var data = {
        action: 'woofc_update_qty', cart_item_key: cart_item_key, cart_item_qty: cart_item_qty, nonce: woofc_nonce(),
    };

    jQuery.post(woofc_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woofc_update_qty'), data, function (response) {
        woofc_cart_reload();

        jQuery(document.body).trigger('woofc_update_qty', [cart_item_key, cart_item_qty]);
    });
}

function woofc_remove_item($item) {
    var cart_item_key = $item.attr('data-key');
    var cart_item_name = $item.attr('data-name');
    var $btn = $item.find('.woofc-item-remove');

    woofc_cart_loading();

    var data = {
        action: 'woofc_remove_item', cart_item_key: cart_item_key, nonce: woofc_nonce(),
    };

    jQuery.post(woofc_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woofc_remove_item'), data, function (response) {
        if (!response || !response.fragments) {
            return;
        }

        jQuery(document.body).trigger('removed_from_cart', [response.fragments, response.cart_hash, $btn]);

        jQuery('body').attr('woofc-undo-key', cart_item_key);
        jQuery('body').attr('woofc-undo-name', cart_item_name);

        woofc_cart_reload();

        jQuery(document.body).trigger('woofc_remove_item', [cart_item_key, cart_item_name, response]);
    });
}

function woofc_cart_loading() {
    jQuery('.woofc-inner').addClass('woofc-inner-loading');
    jQuery('.woofc-count').addClass('woofc-count-loading').removeClass('woofc-count-shake');

    jQuery(document.body).trigger('woofc_cart_loading');
}

function woofc_cart_reload() {
    jQuery(document.body).trigger('wc_fragment_refresh');
    jQuery(document.body).trigger('woofc_cart_reload');
}

function woofc_cart_loaded() {
    jQuery('.woofc-inner').removeClass('woofc-inner-loading');
    jQuery('.woofc-count').removeClass('woofc-count-loading').addClass('woofc-count-shake');

    if ((woofc_vars.undo_remove === 'yes') && (jQuery('body').attr('woofc-undo-key') !== undefined) && (jQuery('body').attr('woofc-undo-key') !== '')) {
        var undo_name = 'Item';

        if ((jQuery('body').attr('woofc-undo-name') !== undefined) && (jQuery('body').attr('woofc-undo-name') !== '')) {
            undo_name = '"' + jQuery('body').attr('woofc-undo-name') + '"';
        }

        jQuery('.woofc-cart-area .woofc-area-mid').find('.woofc-undo').remove();
        jQuery('.woofc-cart-area .woofc-area-mid').prepend('<div class="woofc-undo"><div class="woofc-undo-inner">' + woofc_vars.removed_text.replace('%s', undo_name) + ' <a href="#">' + woofc_vars.undo_remove_text + '</a></div></div>');
    }

    woofc_slick();
    woofc_perfect_scrollbar();
    jQuery(document.body).trigger('woofc_cart_loaded');
}

function woofc_perfect_scrollbar() {
    if (woofc_vars.scrollbar === 'yes') {
        jQuery('.woofc-area .woofc-area-mid').perfectScrollbar({suppressScrollX: true, theme: 'wpc'});
    }
}

function woofc_slick() {
    if (woofc_vars.suggested_carousel) {
        // suggested
        if (jQuery('.woofc-suggested-product').length > 1) {
            if (jQuery('.woofc-suggested-products').hasClass('slick-initialized')) {
                // unslick first
                jQuery('.woofc-suggested-products').slick('unslick');
            }

            // init slick
            jQuery('.woofc-suggested-products').slick(JSON.parse(woofc_vars.slick_params));
        }
    }

    if (woofc_vars.save_for_later_carousel) {
        // save for later
        if (jQuery('.woofc-save-for-later .woosl-product').length > 1) {
            if (jQuery('.woofc-save-for-later .woosl-products').hasClass('slick-initialized')) {
                // unslick first
                jQuery('.woofc-save-for-later .woosl-products').slick('unslick');
            }

            // init slick
            jQuery('.woofc-save-for-later .woosl-products').slick(JSON.parse(woofc_vars.slick_params));
        }
    }

    if (woofc_vars.upsell_funnel_carousel) {
        // upsell funnel
        if (jQuery('.woofc-upsell-funnel .wpcuf-uf-product').length > 1) {
            if (jQuery('.woofc-upsell-funnel .wpcuf-uf-products').hasClass('slick-initialized')) {
                // unslick first
                jQuery('.woofc-upsell-funnel .wpcuf-uf-products').slick('unslick');
            }

            // init slick
            jQuery('.woofc-upsell-funnel .wpcuf-uf-products').slick(JSON.parse(woofc_vars.slick_params));
        }
    }

    if (jQuery('.woofc-upsell-funnel .wpcuf_variations_form').length) {
        jQuery('.woofc-upsell-funnel .wpcuf_variations_form').each(function () {
            jQuery(this).wc_variation_form();
        });
    }
}

function woofc_show_cart(view = 'cart') {
    jQuery('body').addClass('woofc-show');

    jQuery(document.body).trigger('woofc_show_cart');
}

function woofc_hide_cart() {
    jQuery('body').removeClass('woofc-show woofc-show-checkout');

    jQuery(document.body).trigger('woofc_hide_cart');
}

function woofc_toggle_cart() {
    if (jQuery('body').hasClass('woofc-show')) {
        woofc_hide_cart();
    } else {
        woofc_show_cart();
    }

    jQuery(document.body).trigger('woofc_toggle_cart');
}

function woofc_check_qty($qty) {
    var is_remove = false;
    var val = parseFloat($qty.val());
    var min = parseFloat($qty.attr('min'));
    var max = parseFloat($qty.attr('max'));
    var step = parseFloat($qty.attr('step'));
    var fix = Math.pow(10, Number(woofc_decimal_places(step)) + 1);

    if ((val === '') || isNaN(val)) {
        val = 0;
    }

    if ((min === '') || isNaN(min)) {
        min = 0;
    }

    if ((step === '') || isNaN(step)) {
        step = 1;
    }

    var remainder = woofc_float_remainder(val, step);

    if (remainder >= 0) {
        val = Math.round((val - remainder) * fix) / fix;
    }

    if (val < min || val <= 0) {
        is_remove = true;
        val = min;
    }

    if (!isNaN(max) && (val > max)) {
        val = max;
    }

    $qty.val(val);

    if (is_remove) {
        if ((woofc_vars.confirm_remove === 'yes')) {
            if (confirm(woofc_vars.confirm_remove_text)) {
                woofc_remove_item($qty.closest('.woofc-item'));
            }
        } else {
            woofc_remove_item($qty.closest('.woofc-item'));
        }
    }
}

function woofc_decimal_places(num) {
    var match = ('' + num).match(/(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/);

    if (!match) {
        return 0;
    }

    return Math.max(0, // Number of digits right of decimal point.
        (match[1] ? match[1].length : 0)
        // Adjust for scientific notation.
        - (match[2] ? +match[2] : 0));
}

function woofc_nonce() {
    let nonce = jQuery('.woofc-cart-area').attr('data-nonce');

    if (typeof nonce === 'undefined' || nonce === false || nonce === '') {
        nonce = woofc_vars.nonce;
    }

    return nonce;
}

function woofc_float_remainder(val, step) {
    var valDecCount = (val.toString().split('.')[1] || '').length;
    var stepDecCount = (step.toString().split('.')[1] || '').length;
    var decCount = valDecCount > stepDecCount ? valDecCount : stepDecCount;
    var valInt = parseInt(val.toFixed(decCount).replace('.', ''));
    var stepInt = parseInt(step.toFixed(decCount).replace('.', ''));
    return (valInt % stepInt) / Math.pow(10, decCount);
}