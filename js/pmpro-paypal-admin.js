( function ( $ ) {
	'use strict';

	$( document ).ready( function () {
		$( document ).on( 'click', '.pmpro-paypal-edit-webhook-id', function () {
			var $button = $( this );
			var targetId = $button.data( 'target' );
			var $input = $( '#' + targetId );

			var confirmed = window.confirm(
				'Warning: Changing the Webhook ID will affect how PayPal payment events are verified.\n\n' +
				'Only change this value if you have manually created a new webhook in your PayPal dashboard ' +
				'and need to enter the new Webhook ID.\n\n' +
				'Are you sure you want to edit this value?'
			);

			if ( confirmed ) {
				$input.prop( 'readonly', false ).focus();
				$button.hide();
			}
		} );
	} );
} )( jQuery );
