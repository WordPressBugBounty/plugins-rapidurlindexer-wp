(function($) {
    $(document).ready(function() {
        function renderNotice(type, messages) {
            var notice = $('<div>').addClass('notice notice-' + type);
            var paragraph = $('<p>');

            $.each(messages, function(index, message) {
                if (index > 0) {
                    paragraph.append($('<br>'));
                }
                paragraph.append(document.createTextNode(message));
            });

            notice.append(paragraph);
            $('#rui-bulk-submit-response').empty().append(notice);
        }

        function normalizeErrorMessage(responseData) {
            if (typeof responseData === 'string') {
                return responseData;
            }

            if (responseData && typeof responseData.error === 'string') {
                return responseData.error;
            }

            return rui_ajax.unknown_error;
        }

        function fetchCredits() {
            $.ajax({
                url: rui_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rui_refresh_credits',
                    nonce: rui_ajax.refresh_credits_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.rui-credits-display').text(response.data.credits);
                    } else {
                        $('.rui-credits-display').text(rui_ajax.error_fetching_credits);
                    }
                },
                error: function() {
                    $('.rui-credits-display').text(rui_ajax.error_fetching_credits);
                }
            });
        }

        fetchCredits();
        $('#rui-refresh-credits').on('click', fetchCredits);

        $('#rapidurlindexer-bulk-submit-form').on('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            var submitButton = $('#rapidurlindexer-submit-urls');
            var originalSubmitValue = submitButton.val();
            submitButton.prop('disabled', true).val('Submitting...');
            renderNotice('info', [rui_ajax.submitting_urls]);

            var formData = new FormData(this);
            formData.append('action', 'rapidurlindexer_bulk_submit');
            formData.append('nonce', rui_ajax.nonce);

            $.ajax({
                url: rui_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        renderNotice('success', [
                            response.data.message,
                            rui_ajax.remaining_credits + ' ' + response.data.credits
                        ]);
                        // Clear the form
                        $('#rui-urls').val('');
                        $('#rui-project-name').val('');
                    } else {
                        renderNotice('error', [
                            rui_ajax.error_prefix + ' ' + normalizeErrorMessage(response.data)
                        ]);
                    }
                },
                error: function(xhr, status, error) {
                    renderNotice('error', [rui_ajax.error_prefix + ' ' + error]);
                },
                complete: function() {
                    // Reset button state
                    submitButton.prop('disabled', false).val(originalSubmitValue);
                }
            });
        });

        $('#rui-clear-logs').on('click', function() {
            if (confirm(rui_ajax.confirm_clear_logs)) {
                $.ajax({
                    url: rui_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rui_clear_logs',
                        nonce: rui_ajax.clear_logs_nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(rui_ajax.logs_cleared);
                            location.reload();
                        } else {
                            alert(rui_ajax.error_clearing_logs);
                        }
                    }
                });
            }
        });
    });
})(jQuery);
