(function ($) {
  'use strict';

  if (sellwin_ajax.has_mobile === '1' || sellwin_ajax.has_mobile === true) {
    return;
  }

  var modalHtml =
    '<div class="sellwin-modal-overlay" id="sellwin-capture-modal">' +
      '<div class="sellwin-modal">' +
        '<h2>Welcome to Sellwin</h2>' +
        '<p>Please share your details to continue shopping</p>' +
        '<label for="sellwin-name">Your Name</label>' +
        '<input type="text" id="sellwin-name" placeholder="Enter your name" autocomplete="name">' +
        '<label for="sellwin-mobile">Mobile Number</label>' +
        '<input type="tel" id="sellwin-mobile" placeholder="Enter 10-digit mobile number" autocomplete="tel" maxlength="10">' +
        '<div class="sellwin-error" id="sellwin-error">Please enter a valid 10-digit mobile number</div>' +
        '<button type="button" id="sellwin-submit">Continue Shopping</button>' +
        '<div class="sellwin-footer">Your details are safe with us</div>' +
      '</div>' +
    '</div>';

  function blockAddToCart() {
    $('.single_add_to_cart_button, .add_to_cart_button, .ajax_add_to_cart').each(function () {
      var $btn = $(this);
      if (!$btn.data('sellwin-blocked')) {
        $btn.data('sellwin-blocked', true);
        $btn.data('sellwin-original-click', $btn.attr('onclick'));
        $btn.attr('onclick', 'return false;');
        $btn.on('click.sellwin', function (e) {
          e.preventDefault();
          e.stopImmediatePropagation();
          showModal();
          return false;
        });
      }
    });
  }

  function showModal() {
    if ($('#sellwin-capture-modal').length) return;
    $('body').append(modalHtml);
    $('#sellwin-mobile').on('input', function () {
      $(this).val($(this).val().replace(/[^0-9]/g, ''));
    });
    $('#sellwin-submit').on('click', submitMobile);
    $('#sellwin-mobile, #sellwin-name').on('keypress', function (e) {
      if (e.which === 13) submitMobile();
    });
  }

  function submitMobile() {
    var name = $('#sellwin-name').val().trim();
    var mobile = $('#sellwin-mobile').val().trim();
    var $error = $('#sellwin-error');
    var $btn = $('#sellwin-submit');

    if (!/^[0-9]{10}$/.test(mobile)) {
      $error.show();
      return;
    }

    $error.hide();
    $btn.prop('disabled', true).text('Please wait...');

    $.ajax({
      url: sellwin_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'sellwin_save_mobile',
        nonce: sellwin_ajax.nonce,
        mobile: mobile,
        name: name
      },
      success: function (response) {
        if (response.success) {
          $('#sellwin-capture-modal').fadeOut(300, function () {
            $(this).remove();
          });
          unblockAddToCart();
        } else {
          $error.text(response.data.message || 'Something went wrong').show();
          $btn.prop('disabled', false).text('Continue Shopping');
        }
      },
      error: function () {
        $error.text('Network error. Please try again.').show();
        $btn.prod('disabled', false).text('Continue Shopping');
      }
    });
  }

  function unblockAddToCart() {
    $('.single_add_to_cart_button, .add_to_cart_button, .ajax_add_to_cart').each(function () {
      var $btn = $(this);
      $btn.data('sellwin-blocked', false);
      $btn.off('click.sellwin');
      if ($btn.data('sellwin-original-click')) {
        $btn.attr('onclick', $btn.data('sellwin-original-click'));
      }
    });
  }

  $(document).ready(function () {
    blockAddToCart();
    $(document).ajaxComplete(function () {
      blockAddToCart();
    });
  });

})(jQuery);
