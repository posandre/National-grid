(function ($) {
    $(function () {
        var $button = $('#national-grid-update-button');
        var $loader = $('#national-grid-update-loader');
        var $message = $('#national-grid-update-message');

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

                $message
                    .addClass('notice ' + (isSuccess ? 'notice-success' : 'notice-error') + ' inline')
                    .html('<p>' + message + '</p>');
            }).fail(function (xhr) {
                var message = nationalGridAdmin.unknownError;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                $message
                    .addClass('notice notice-error inline')
                    .html('<p>' + message + '</p>');
            }).always(function () {
                $loader.removeClass('is-active');
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
