/**
 * Kargo National Shipping frontend JavaScript
 */
(function($) {
	'use strict';

	// Initialize on document ready
	$(document).ready(function() {
		// Update shipping when country or postcode changes
		$(document.body).on('change', '#shipping_country, #shipping_postcode, #calc_shipping_postcode', function() {
			$(document.body).trigger('update_checkout');
		});

		// Custom form validation for required fields
		if ($('form.checkout').length > 0) {
			$('form.checkout').on('checkout_place_order', function() {
				if ($('#shipping_method input:checked').val().includes('kargo_national_shipping')) {
					// Verify postcode is entered
					if ($('#shipping_postcode').val().trim() === '') {
						alert(kargo_shipping_params.i18n_no_postcode);
						return false;
					}
				}
				return true;
			});
		}
	});

})(jQuery);