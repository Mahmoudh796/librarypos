/*
 * Shared interaction helpers for the Ru'ya screens.
 *
 * Financial actions (complete a sale, record a payment, post a voucher) must
 * not run twice because of a double click or an impatient second press. A form
 * marked data-ruya-once submits once and shows its buttons as busy; ruyaOnce()
 * does the same for the "div-as-button" actions of the register.
 */
(function ($) {
    'use strict';

    const markBusy = function (element) {
        $(element).addClass('is-busy').attr('aria-disabled', 'true').prop('disabled', true);
    };

    // Runs action unless this button already did; returns whether it ran.
    window.ruyaOnce = function (button, action) {
        const element = $(button);
        if (element.data('ruyaBusy')) {
            return false;
        }
        element.data('ruyaBusy', true);
        markBusy(element);
        action();

        return true;
    };

    // Re-enables a button after an action that stayed on the page failed.
    window.ruyaRelease = function (button) {
        $(button).data('ruyaBusy', false).removeClass('is-busy').removeAttr('aria-disabled').prop('disabled', false);
    };

    // AJAX form post that cannot be sent twice: the form's submit buttons stay
    // busy until the response; a failure releases them so the user can retry.
    window.ruyaPost = function (form, url, data, done) {
        const element = $(form);
        if (element.data('ruyaBusy')) {
            return;
        }
        const buttons = element.find('[type="submit"]');
        element.data('ruyaBusy', true);
        markBusy(buttons);

        const release = function () {
            element.data('ruyaBusy', false);
            buttons.removeClass('is-busy').removeAttr('aria-disabled').prop('disabled', false);
        };

        $.post(url, data, function (response) {
            if (!response || !response.success) {
                release();
            }
            done(response);
        }, 'json').fail(release);
    };

    // Number check done in the browser, accepting what the server's parse_decimals()
    // accepts (Latin or Arabic-Indic digits, "." or "٫" decimals, grouping marks).
    // It replaces a round trip per field; the server still validates on save.
    window.ruyaParseNumber = function (value) {
        const normalised = String(value)
            .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
            .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
            .replace(/٫/g, '.')
            .replace(/[٬,\s ]/g, '');

        return /^-?\d+(\.\d+)?$/.test(normalised) ? parseFloat(normalised) : NaN;
    };

    if ($.validator) {
        $.validator.addMethod('localeNumber', function (value, element) {
            return this.optional(element) || !isNaN(window.ruyaParseNumber(value));
        });
    }

    $(document).on('submit', 'form[data-ruya-once]', function (event) {
        const form = $(this);
        if (form.data('ruyaSubmitted')) {
            event.preventDefault();
            event.stopImmediatePropagation();

            return false;
        }
        form.data('ruyaSubmitted', true);
        markBusy(form.find('[type="submit"]'));

        return true;
    });
})(jQuery);
