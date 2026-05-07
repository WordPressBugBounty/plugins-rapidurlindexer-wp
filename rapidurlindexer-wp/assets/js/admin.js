(function($) {
    $(document).ready(function() {
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
            $('#rui-bulk-submit-response').html('<div class="notice notice-info"><p>Submitting URLs...</p></div>');

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
                        $('#rui-bulk-submit-response').html(
                            '<div class="notice notice-success"><p>' + 
                            response.data.message + 
                            '<br>Remaining credits: ' + response.data.credits + 
                            '</p></div>'
                        );
                        // Clear the form
                        $('#rui-urls').val('');
                        $('#rui-project-name').val('');
                    } else {
                        $('#rui-bulk-submit-response').html(
                            '<div class="notice notice-error"><p>Error: ' + 
                            (response.data || 'Unknown error occurred') + 
                            '</p></div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('#rui-bulk-submit-response').html(
                        '<div class="notice notice-error"><p>Error: ' + 
                        error + 
                        '</p></div>'
                    );
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

    function updateCreditsDisplay(credits) {
        $('.rui-credits-display').text(credits);
    }
})(jQuery);
