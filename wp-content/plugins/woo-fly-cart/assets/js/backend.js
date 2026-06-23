'use strict';

(function($) {
  $(function() {
    $('.woofc_color_picker').wpColorPicker();
    $('#woofc_count_icon').fontIconPicker();

    woofc_init_options();
  });

  // choose background image
  var woofc_file_frame;

  $(document).on('click touch', '#woofc_upload_image_button', function(event) {
    event.preventDefault();

    // If the media frame already exists, reopen it.
    if (woofc_file_frame) {
      // Open frame
      woofc_file_frame.open();
      return;
    }

    // Create the media frame.
    woofc_file_frame = wp.media.frames.woofc_file_frame = wp.media({
      title: 'Select a image to upload', button: {
        text: 'Use this image',
      }, multiple: false,	// Set to true to allow multiple files to be selected
    });

    // When an image is selected, run a callback.
    woofc_file_frame.on('select', function() {
      // We set multiple to false so only get one image from the uploader
      var attachment = woofc_file_frame.state().
          get('selection').
          first().
          toJSON();

      // Do something with attachment.id and/or attachment.url here
      if ($('#woofc_image_preview img').length) {
        $('#woofc_image_preview img').attr('src', attachment.url);
      } else {
        $('#woofc_image_preview').
            html('<img src="' + attachment.url + '"/>');
      }
      $('#woofc_image_attachment_url').val(attachment.id);
    });

    // Finally, open the modal
    woofc_file_frame.open();
  });

  $(document).on('change', 'select.woofc_style, select.woofc_instant_checkout',
      function() {
        woofc_init_options();
      });

  function woofc_init_options() {
    var style = $('select.woofc_style').val();
    var instant_checkout = $('select.woofc_instant_checkout').val();

    // style
    $('.woofc_hide_if_style').hide();
    $('.woofc_show_if_style_' + style).show();

    // instant_checkout
    $('.woofc_hide_if_instant_checkout').hide();
    $('.woofc_show_if_instant_checkout_' + instant_checkout).show();
  }
})(jQuery);