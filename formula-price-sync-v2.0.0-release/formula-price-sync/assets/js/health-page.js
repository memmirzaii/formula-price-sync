/**
 * Formula Price Sync – Admin Health Page JS
 * Handles the force refresh button for the System Health dashboard.
 */
(function ($) {
	'use strict';

	$(function () {
		$('#fps-health-refresh').on('click', function (e) {
			e.preventDefault();

			var $btn = $(this);
			var $cards = $('#fps-health-cards');
			var $updated = $('#fps-health-last-updated');

			$btn.prop('disabled', true).text(FPSHealth.i18n.refreshing);

			$.post(FPSHealth.ajaxUrl, {
				action: 'fps_health_refresh',
				nonce: FPSHealth.nonce
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.html) {
						$cards.html(res.data.html);
						$updated.text(FPSHealth.i18n.refreshed);
					}
				})
				.fail(function () {
					$updated.text(FPSHealth.i18n.error);
				})
				.always(function () {
					$btn.prop('disabled', false).text(FPSHealth.i18n.refreshBtn);
				});
		});
	});
})(jQuery);