'use strict';

const binding = '<tr>' +
  '<th><input type="number" class="wai-input wai-input-woocommerce" placeholder="WooCommerce"></th>' +
  '<th><input type="text" class="wai-input wai-input-allegro" placeholder="Allegro"></th>' +
  '</tr>';

jQuery($ => {
  // If enter key was clicked in one of inputs in settings tab submit the form
  $('.wai-input').live('keydown', e => {
    e.preventDefault();

    if (e.keyCode === 13)
      $('#wai-form').submit();
  });

  // Add new binding
  $('#wai-bindings-add').click(e => {
    e.preventDefault();

    $('#wai-bindings > tbody').append(binding);
  });

  // Remove binding
  $('#wai-bindings-remove').click(e => {
    e.preventDefault();

    $('#wai-bindings > tbody').children().last().remove();
  });
});
