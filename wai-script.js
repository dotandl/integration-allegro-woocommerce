'use strict';

const bindingElement = '<tr>' +
  '<th><input type="number" class="wai-input wai-input-woocommerce" placeholder="WooCommerce"></th>' +
  '<th><input type="text" class="wai-input wai-input-allegro" placeholder="Allegro"></th>' +
  '</tr>';

jQuery($ => {
  // If hidden bindings field has value parse it and display bindings fields
  if ($("#wai-bindings-json").length) {
    let bindings = JSON.parse($("#wai-bindings-json").val());

    for (let binding of bindings) {
      let element = $.parseHTML(bindingElement);
      $(element).find(".wai-input-woocommerce").val(binding[0]);
      $(element).find(".wai-input-allegro").val(binding[1]);
      $("#wai-bindings > tbody").append(element);
    }
  }

  // If enter key was clicked in one of inputs in settings tab submit the form
  $('.wai-input').live('keydown', e => {
    if (e.keyCode === 13) {
      e.preventDefault();
      $('#wai-submit').click();
    }
  });

  // Toggle Secret's visibility when checkbox is checked
  $('#wai-allegro-secret-toggle-visibility').change(e => {
    $('#wai-allegro-secret').get(0).type = e.target.checked ? 'text' : 'password';
  });

  // Add new binding
  $('#wai-bindings-add').click(e => {
    e.preventDefault();
    $('#wai-bindings > tbody').append(bindingElement);
  });

  // Remove binding
  $('#wai-bindings-remove').click(e => {
    e.preventDefault();
    $('#wai-bindings > tbody').children().last().remove();
  });

  // Save bindings and submit the form
  $("#wai-submit").click(e => {
    e.preventDefault();
    let bindings = [];

    for (let i of $("#wai-bindings > tbody").children()) {
      let binding = [];
      binding.push($(i).find(".wai-input-woocommerce").val());
      binding.push($(i).find(".wai-input-allegro").val());
      bindings.push(binding);
    }

    $("#wai-bindings-json").val(JSON.stringify(bindings));
    $("#wai-form").submit();
  });
});