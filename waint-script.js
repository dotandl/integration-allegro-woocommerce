'use strict';

/**
 * One binding of bindings table
 */
const bindingElement = '<tr>' +
  '<th><input type="number" class="waint-input waint-input-woocommerce" placeholder="WooCommerce"></th>' +
  '<th><input type="text" class="waint-input waint-input-allegro" placeholder="Allegro"></th>' +
  '</tr>';

/**
 * Function adding a parameter to the current URL
 *
 * This function gets current URL and checks whether it has already got params
 * and adds new param depends on that info. If URL has fragment
 * identifier ("#" and ID of element) it will be removed.
 *
 * @param {string} key Param's key
 * @param {string} value Param's value
 * @returns {string} Final URL
 */
function addParamToUrl(key, value) {
  let url = location.href;
  url = url.split('#')[0];

  if (url.includes('?'))
    url += `&${key}=${value}`;
  else
    url += `?${key}=${value}`;
  return url;
}

jQuery($ => {
  // If hidden bindings field has value parse it and display bindings fields
  if ($('#waint-bindings-json').length) {
    let bindings = JSON.parse($('#waint-bindings-json').val());

    for (let binding of bindings) {
      let element = $.parseHTML(bindingElement);
      $(element).find('.waint-input-woocommerce').val(binding[0]);
      $(element).find('.waint-input-allegro').val(binding[1]);
      $('#waint-bindings > tbody').append(element);
    }
  }

  // If enter key was clicked in one of inputs in settings tab submit the form
  $('.waint-input').live('keydown', e => {
    if (e.keyCode === 13) {
      e.preventDefault();
      $('#waint-submit').click();
    }
  });

  // Toggle Secret's visibility when checkbox is checked
  $('#waint-allegro-secret-toggle-visibility').change(e => {
    $('#waint-allegro-secret').get(0).type = e.target.checked ? 'text' : 'password';
  });

  // Add new binding
  $('#waint-bindings-add').click(e => {
    e.preventDefault();
    $('#waint-bindings > tbody').append(bindingElement);
  });

  // Remove binding
  $('#waint-bindings-remove').click(e => {
    e.preventDefault();
    $('#waint-bindings > tbody').children().last().remove();
  });

  // Sync WooCommerce -> Allegro
  $('#waint-sync-woocommerce-allegro').click(e => {
    e.preventDefault();
    location.href = addParamToUrl('action', 'sync-woocommerce-allegro');
  });

  // Sync Allegro -> WooCommerce
  $('#waint-sync-allegro-woocommerce').click(e => {
    e.preventDefault();
    location.href = addParamToUrl('action', 'sync-allegro-woocommerce');
  });

  // Link to Allegro
  $('#waint-link-allegro').click(e => {
    e.preventDefault();
    location.href = addParamToUrl('action', 'link-allegro');
  });

  // Save bindings and submit the form
  $('#waint-submit').click(e => {
    e.preventDefault();
    let bindings = [];

    for (let i of $('#waint-bindings > tbody').children()) {
      let binding = [];
      binding.push(Number($(i).find('.waint-input-woocommerce').val()));
      binding.push($(i).find('.waint-input-allegro').val());
      bindings.push(binding);
    }

    $('#waint-bindings-json').val(JSON.stringify(bindings));
    $('#waint-form').submit();
  });

  $('#waint-clean-log').click(e => {
    e.preventDefault();
    location.href = addParamToUrl('action', 'clean-log-file');
  });
});
