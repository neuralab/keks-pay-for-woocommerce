jQuery( function( $ ) {
  'use strict';

  // Check kekspay status periodically and redirect to confirmation page when payment completes.
  function statusCheck() {
    if ( ! kekspayClientScript.order_id ) {
      return;
    }

    $.ajax({
      method: 'POST',
      url: kekspayClientScript.ajaxurl,
      dataType: 'json',
      data: {
        'action': 'kekspay_status_check',
        '_ajax_nonce': kekspayClientScript.nonce,
        'order_id': kekspayClientScript.order_id,
        'order_key': kekspayClientScript.order_key
      },
      success: function(response) {
        if ( 'pending' !== response.status ) {
          clearInterval( statusCheckInterval );
          if ( response.redirect !== null ) {
            window.location.href = response.redirect;
          }
        }
      },
      error: function(response) { // error logging
        console.log(response);
      }
    });
  };

  // Check on page load.
  statusCheck();

  // Check every 5s.
  let statusCheckInterval = setInterval(function() {
    statusCheck();
  }, kekspayClientScript.ipn_refresh);

  // Stop checking after 1h to prevent infinite requests.
  setTimeout( function() {
    clearInterval( statusCheckInterval );
  }, 3600000);

} );
