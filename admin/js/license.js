/* WA Atlas CRM – License Page JS
   Standalone: only used on the license activation page.
   Does NOT depend on waCRM.nonce or quota data.
   ================================================== */

(function ($) {
    'use strict';

    $(document).ready(function () {

        // ── Activate license ──────────────────────────────────────────────────
        $('#license-form').on('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn   = $(this).find('[type="submit"]');
            var $msg   = $('#lic-message');
            var key    = $('#lic-key').val().trim();
            var email  = $('#lic-email').val().trim();

            if (!key) {
                showMsg('Please enter your license key.', 'error');
                return;
            }

            $btn.prop('disabled', true).text('Activating…');
            $msg.hide();

            $.ajax({
                url:    waCRM.ajax_url,
                method: 'POST',
                data: {
                    action:        'wacrm_save_license',
                    _nonce:        waCRM.license_nonce,
                    license_key:   key,
                    license_email: email,
                },
                success: function (r) {
                    if (r.success) {
                        showMsg('✓ ' + r.data.message, 'success');
                        setTimeout(function () {
                            window.location.href = r.data.redirect || (window.location.href.split('?')[0] + '?page=wacrm-dashboard');
                        }, 1200);
                    } else {
                        showMsg('✕ ' + (r.data && r.data.message ? r.data.message : 'Activation failed. Please check your key and email.'), 'error');
                        $btn.prop('disabled', false).text('Activate License');
                    }
                },
                error: function (xhr) {
                    showMsg('✕ Server error (' + xhr.status + '). Please try again.', 'error');
                    $btn.prop('disabled', false).text('Activate License');
                },
            });
        });

        // ── Remove license ────────────────────────────────────────────────────
        $('#btn-remove-license').on('click', function () {
            if (!window.confirm('Remove the current license key?')) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url:    waCRM.ajax_url,
                method: 'POST',
                data: {
                    action: 'wacrm_remove_license',
                    _nonce: waCRM.license_nonce,
                },
                complete: function () {
                    window.location.reload();
                },
            });
        });

        function showMsg(text, type) {
            var $el = $('#lic-message');
            if (!$el.length) {
                $el = $('<div id="lic-message" class="wacrm-alert" style="margin-top:14px"></div>');
                $('#license-form').after($el);
            }
            $el.removeClass('error success warning info')
               .addClass(type)
               .text(text)
               .show();
        }

    });

})(jQuery);
