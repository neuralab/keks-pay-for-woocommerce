jQuery( function( $ ) {
  'use strict';

  if ( ! kekspayClientScript.order_id ) {
    return;
  }

  var refreshRate = parseInt( kekspayClientScript.ipn_refresh, 10 ) || 5000;
  var startedAt   = Date.now();
  var timer       = null;
  var stopped     = false;
  var inFlight    = false;

  // Check every refresh rate (5s by default), slow down after 5min and stop after 1h to prevent infinite requests.
  function scheduleStatusCheck() {
    var elapsed = Date.now() - startedAt;

    if ( stopped || document.hidden ) {
      return;
    }

    if ( elapsed > 3600000 ) {
      stopped = true;
      return;
    }

    timer = setTimeout( statusCheck, elapsed > 300000 ? refreshRate * 3 : refreshRate );
  }

  // Check kekspay status and redirect to confirmation page when payment completes.
  function statusCheck() {
    timer    = null;
    inFlight = true;

    $.ajax({
      method: 'POST',
      url: kekspayClientScript.ajaxurl,
      dataType: 'json',
      data: {
        'action': 'kekspay_status_check',
        '_ajax_nonce': kekspayClientScript.nonce,
        'order_id': kekspayClientScript.order_id,
        'order_key': kekspayClientScript.order_key
      }
    }).always( function() {
      inFlight = false;
    }).done( function( response ) {
      if ( response && 'pending' !== response.status ) {
        stopped = true;
        if ( response.redirect ) {
          window.location.href = response.redirect;
        }
        return;
      }

      scheduleStatusCheck();
    }).fail( function( response ) {
      console.log( response ); // error logging

      // Invalid order or nonce, retrying won't help.
      if ( response.status >= 400 && response.status < 500 ) {
        stopped = true;
        return;
      }

      scheduleStatusCheck();
    });
  }

  // Pause checking while the tab is hidden and check immediately when it becomes visible again.
  document.addEventListener( 'visibilitychange', function() {
    if ( document.hidden ) {
      clearTimeout( timer );
      timer = null;
    } else if ( ! stopped && ! timer && ! inFlight ) {
      statusCheck();
    }
  } );

  // Check on page load.
  statusCheck();

} );
