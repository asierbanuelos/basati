<?php
// Base button class if style is icon
if ( $ekit_video_popup_button_style === 'icon' ) {
    $this->add_render_attribute( 'button', 'class', 'ekit_icon_button' );
}

// Glow animation handling
if ( $ekit_video_popup_video_glow === 'yes' ) {
    $glow_type = ! empty( $ekit_video_popup_glow_animation_type )
        ? $ekit_video_popup_glow_animation_type
        : $ekit_video_inline_glow_animation_type;

    if ( ! empty( $glow_type ) ) {
        $this->add_render_attribute( 'button', 'class', 'glow-' . esc_attr( $glow_type ) );
    }
}

// Radio wave scaling (popup first, fallback to inline)
$radio_wave_size = $ekit_video_popup_radio_wave_scale['size']
    ?? $ekit_video_inline_radio_wave_scale['size']
    ?? null;

if ( $radio_wave_size ) {
    $this->add_render_attribute(
        'button',
        'glow-radio_wave',
        '--ekit-radio-wave-scale: ' . $radio_wave_size . ';'
    );
}

// Video type + style handling
if ( $ekit_video_style === 'popup' ) {
    $href = ( $ekit_video_popup_video_type === 'self' )
        ? '#' . $generate_id
        : $ekit_video_popup_url;

    $this->add_render_attribute( 'button', [
        'class'      => [ 'ekit-video-popup', 'ekit-video-popup-btn' ],
        'href'       => $href,
        'aria-label' => 'video-popup'
    ] );
}

if ( $ekit_video_style === 'inline' && !empty($ekit_video_inline_overlay_image['url'])) {
    $this->add_render_attribute( 'button', [
        'class'      => [ 'ekit-video-inline-btn' ],
        'href'       => $ekit_video_popup_url,
        'aria-label' => 'video-inline'
    ] );
}
?>

<a <?php $this->print_render_attribute_string( 'button' ); ?>>
    <?php
    $show_text = in_array( $ekit_video_popup_button_style, [ 'text', 'both' ], true );
    $show_icon = in_array( $ekit_video_popup_button_style, [ 'icon', 'both' ], true ) && ! empty( $ekit_video_popup_button_icons );

    // Icon before text
    if ( $show_icon && $ekit_video_popup_button_style === 'both' && $ekit_video_popup_icon_align === 'before' ) {
        $this->video_icon();
    }

    // Text
    if ( $show_text ) {
        echo '<span class="ekit-video-popup-title">' . esc_html( $ekit_video_popup_button_title ) . '</span>';
    }

    // Icon after text or icon-only
    if ( $show_icon && (
        ( $ekit_video_popup_button_style === 'both' && $ekit_video_popup_icon_align === 'after' ) ||
        $ekit_video_popup_button_style === 'icon'
    ) ) {
        $this->video_icon();
    }
    ?>
</a>
