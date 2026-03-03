(function ($) {
    // Binds admin UI behavior when settings page DOM is ready.
    $(function () {
        // Primary action button for manual data refresh.
        var $button = $('#national-grid-update-button');
        // Spinner element shown while update request is in progress.
        var $loader = $('#national-grid-update-loader');
        // Notification area for success/error feedback.
        var $message = $('#national-grid-update-message');
        // Replaces the current log section with server-rendered HTML.
        var replaceLogSection = function (html) {
            if (!html) {
                return;
            }

            var $current = $('#national-grid-admin-log-section');
            if ($current.length) {
                $current.replaceWith(html);
            }
        };
        // Requests fresh log section markup from admin AJAX endpoint.
        var refreshLogSection = function () {
            $.post(nationalGridAdmin.ajaxUrl, {
                action: nationalGridAdmin.fetchLogAction,
                nonce: nationalGridAdmin.nonce
            }).done(function (response) {
                if (response && response.success && response.data && response.data.html) {
                    replaceLogSection(response.data.html);
                }
            });
        };

        // Starts manual data update and renders status/log feedback.
        $button.on('click', function () {
            $message.removeClass('notice notice-success notice-error inline').empty();
            $button.prop('disabled', true);
            $loader.addClass('is-active');

            $.post(nationalGridAdmin.ajaxUrl, {
                action: nationalGridAdmin.action,
                nonce: nationalGridAdmin.nonce
            }).done(function (response) {
                var isSuccess = !!(response && response.success);
                var message = (response && response.data && response.data.message) ? response.data.message : nationalGridAdmin.unknownError;
                var logHtml = (response && response.data && response.data.logHtml) ? response.data.logHtml : '';

                $message
                    .addClass('notice ' + (isSuccess ? 'notice-success' : 'notice-error') + ' inline')
                    .html('<p>' + message + '</p>');

                replaceLogSection(logHtml);
            }).fail(function (xhr) {
                var message = nationalGridAdmin.unknownError;
                var logHtml = '';

                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                    logHtml = xhr.responseJSON.data.logHtml || '';
                } else if (xhr && xhr.responseText) {
                    var raw = String(xhr.responseText).trim();
                    if (raw.length > 0) {
                        message = raw.substring(0, 300);
                    }
                }

                $message
                    .addClass('notice notice-error inline')
                    .html('<p>' + message + '</p>');

                replaceLogSection(logHtml);
            }).always(function () {
                $loader.removeClass('is-active');
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
