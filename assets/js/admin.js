/**
 * WooCommerce Commission & Pricing Engine - Admin Scripts
 *
 * @package WCPE
 */

/* global jQuery, wcpeAdmin */

(function($) {
	'use strict';

	/**
	 * WCPE Admin Module
	 */
	var WCPEAdmin = {

		/**
		 * Initialize the admin scripts.
		 */
		init: function() {
			this.bindEvents();
			this.initCopyButtons();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Delete confirmation.
			$(document).on('click', '.wcpe-action--delete', this.confirmDelete);

			// Payout confirmation.
			$(document).on('click', '.wcpe-action--payout, .wcpe-confirm-payout', this.confirmPayout);

			// Modal close.
			$(document).on('click', '.wcpe-modal-close, .wcpe-modal-backdrop', this.closeModal);

			// Rule type change.
			$(document).on('change', '#wcpe_rule_type', this.handleRuleTypeChange);

			// Bulk action confirmation.
			$(document).on('click', '#doaction, #doaction2', this.confirmBulkAction);
		},

		/**
		 * Initialize copy to clipboard buttons.
		 */
		initCopyButtons: function() {
			$('.wcpe-copy-webhook-url').on('click', function(e) {
				e.preventDefault();

				var $code = $(this).siblings('code');
				var text = $code.text();

				if (navigator.clipboard) {
					navigator.clipboard.writeText(text).then(function() {
						WCPEAdmin.showNotice(wcpeAdmin.strings.success, 'success');
					});
				} else {
					// Fallback for older browsers.
					var $temp = $('<input>');
					$('body').append($temp);
					$temp.val(text).select();
					document.execCommand('copy');
					$temp.remove();
					WCPEAdmin.showNotice(wcpeAdmin.strings.success, 'success');
				}
			});
		},

		/**
		 * Confirm delete action.
		 *
		 * @param {Event} e Click event.
		 * @return {boolean}
		 */
		confirmDelete: function(e) {
			if (!confirm(wcpeAdmin.strings.confirmDelete)) {
				e.preventDefault();
				return false;
			}
			return true;
		},

		/**
		 * Confirm payout action.
		 *
		 * @param {Event} e Click event.
		 * @return {boolean}
		 */
		confirmPayout: function(e) {
			if (!confirm(wcpeAdmin.strings.confirmPayout)) {
				e.preventDefault();
				return false;
			}
			return true;
		},

		/**
		 * Confirm bulk action.
		 *
		 * @param {Event} e Click event.
		 * @return {boolean}
		 */
		confirmBulkAction: function(e) {
			var $select = $(this).siblings('select');
			var action = $select.val();

			if (action === 'delete') {
				if (!confirm(wcpeAdmin.strings.confirmDelete)) {
					e.preventDefault();
					return false;
				}
			}

			return true;
		},

		/**
		 * Handle rule type change to show/hide target selector.
		 */
		handleRuleTypeChange: function() {
			var type = $(this).val();
			var $targetRow = $('#wcpe_target_row');
			var $targetSelect = $('#wcpe_target_id');
			var $targetLabel = $targetRow.find('label');

			switch (type) {
				case 'global':
					$targetRow.hide();
					$targetSelect.prop('required', false);
					break;

				case 'category':
					$targetRow.show();
					$targetLabel.text(wcpeAdmin.strings.selectCategory || 'Category');
					$targetSelect.prop('required', true);
					WCPEAdmin.loadCategories();
					break;

				case 'vendor':
					$targetRow.show();
					$targetLabel.text(wcpeAdmin.strings.selectVendor || 'Vendor');
					$targetSelect.prop('required', true);
					WCPEAdmin.loadVendors();
					break;

				case 'product':
					$targetRow.show();
					$targetLabel.text(wcpeAdmin.strings.selectProduct || 'Product');
					$targetSelect.prop('required', true);
					WCPEAdmin.loadProducts();
					break;
			}
		},

		/**
		 * Load categories via AJAX.
		 */
		loadCategories: function() {
			var $select = $('#wcpe_target_id');
			$select.html('<option value="">' + (wcpeAdmin.strings.loading || 'Loading...') + '</option>');

			$.ajax({
				url: wcpeAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wcpe_get_categories',
					nonce: wcpeAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						var options = '<option value="">' + (wcpeAdmin.strings.selectOption || 'Select...') + '</option>';
						$.each(response.data, function(id, name) {
							options += '<option value="' + id + '">' + name + '</option>';
						});
						$select.html(options);
					}
				}
			});
		},

		/**
		 * Load vendors via AJAX.
		 */
		loadVendors: function() {
			var $select = $('#wcpe_target_id');
			$select.html('<option value="">' + (wcpeAdmin.strings.loading || 'Loading...') + '</option>');

			$.ajax({
				url: wcpeAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wcpe_get_vendors',
					nonce: wcpeAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						var options = '<option value="">' + (wcpeAdmin.strings.selectOption || 'Select...') + '</option>';
						$.each(response.data, function(id, name) {
							options += '<option value="' + id + '">' + name + '</option>';
						});
						$select.html(options);
					}
				}
			});
		},

		/**
		 * Load products via AJAX.
		 */
		loadProducts: function() {
			var $select = $('#wcpe_target_id');
			$select.html('<option value="">' + (wcpeAdmin.strings.loading || 'Loading...') + '</option>');

			$.ajax({
				url: wcpeAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wcpe_get_products',
					nonce: wcpeAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						var options = '<option value="">' + (wcpeAdmin.strings.selectOption || 'Select...') + '</option>';
						$.each(response.data, function(id, name) {
							options += '<option value="' + id + '">' + name + '</option>';
						});
						$select.html(options);
					}
				}
			});
		},

		/**
		 * Open modal.
		 *
		 * @param {string} modalId Modal ID.
		 */
		openModal: function(modalId) {
			$('.wcpe-modal-backdrop').show();
			$('#' + modalId).show();
		},

		/**
		 * Close modal.
		 */
		closeModal: function() {
			$('.wcpe-modal-backdrop').hide();
			$('.wcpe-modal').hide();
		},

		/**
		 * Show admin notice.
		 *
		 * @param {string} message Notice message.
		 * @param {string} type    Notice type (success, error, warning).
		 */
		showNotice: function(message, type) {
			type = type || 'success';

			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

			$('.wrap h1').first().after($notice);

			// Auto-dismiss after 3 seconds.
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 3000);
		},

		/**
		 * Process AJAX form submission.
		 *
		 * @param {jQuery}   $form    Form element.
		 * @param {Function} callback Success callback.
		 */
		submitForm: function($form, callback) {
			var $button = $form.find('button[type="submit"]');
			var originalText = $button.text();

			$button.prop('disabled', true).text(wcpeAdmin.strings.processing);

			$.ajax({
				url: wcpeAdmin.ajaxUrl,
				type: 'POST',
				data: $form.serialize(),
				success: function(response) {
					$button.prop('disabled', false).text(originalText);

					if (response.success) {
						WCPEAdmin.showNotice(wcpeAdmin.strings.success, 'success');
						if (typeof callback === 'function') {
							callback(response);
						}
					} else {
						WCPEAdmin.showNotice(response.data || wcpeAdmin.strings.error, 'error');
					}
				},
				error: function() {
					$button.prop('disabled', false).text(originalText);
					WCPEAdmin.showNotice(wcpeAdmin.strings.error, 'error');
				}
			});
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		WCPEAdmin.init();
	});

})(jQuery);
