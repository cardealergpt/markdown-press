jQuery(document).ready(function($) {
    // Handle export from post list page
    $(document).on('click', '.markdown-press-export', function(e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        handleExport({
            export_type: 'selected',
            post_ids: [postId]
        });
    });

    // Handle export from admin page
    $('#markdown-press-export-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var data = {
            export_type: $('#export-type').val()
        };

        // Add post IDs if selected type
        if (data.export_type === 'selected') {
            data.post_ids = $('#post-select').val();
            if (!data.post_ids || !data.post_ids.length) {
                showError(markdownPressAdmin.i18n.selectContent);
                return;
            }
        }

        // Add taxonomy data if taxonomy type
        if (data.export_type === 'taxonomy') {
            data.taxonomy = $('#taxonomy-select').val();
            var $selectedTermSelect = $('.term-select[data-taxonomy="' + data.taxonomy + '"]');
            var selectedTermId = $selectedTermSelect.val();
            
            if (!data.taxonomy || !selectedTermId) {
                showError(markdownPressAdmin.i18n.selectTaxonomy);
                return;
            }
            
            data.term_id = selectedTermId;
        }

        handleExport(data);
    });

    function handleExport(data) {
        // Remove any existing notices first
        $('.markdown-press-notice').remove();
        
        // Create result container if it doesn't exist
        var $result = $('<div id="markdown-press-export-result" class="markdown-press-notice notice is-dismissible"></div>');
        $('.wrap h1').after($result);
        
        // Show loading message
        $result.addClass('notice-info').html('<p>' + markdownPressAdmin.i18n.exporting + '</p>').show();

        // Add nonce to data
        data.action = 'markdown_press_export';
        data.nonce = markdownPressAdmin.nonce;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    if (response.data.download_url) {
                        // For single post export, redirect directly
                        if (data.export_type === 'selected' && data.post_ids && data.post_ids.length === 1) {
                            window.location.href = response.data.download_url;
                            showSuccess(response.data.message);
                        } else {
                            // For bulk export, show message with download link
                            var message = response.data.message + ' <a href="' + response.data.download_url + 
                                        '" class="button button-primary">' + markdownPressAdmin.i18n.download + '</a>';
                            showSuccess(message);
                        }
                    } else {
                        showSuccess(response.data.message);
                    }
                } else {
                    showError(response.data.message || markdownPressAdmin.i18n.exportFailed);
                }
                
                // Initialize WordPress dismissible notices
                $('.notice.is-dismissible').each(function() {
                    var $el = $(this);
                    var $button = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
                    
                    $button.on('click.wp-dismiss-notice', function(event) {
                        event.preventDefault();
                        $el.fadeTo(100, 0, function() {
                            $el.slideUp(100, function() {
                                $el.remove();
                            });
                        });
                    });
                    
                    $el.append($button);
                });
            },
            error: function(xhr, status, error) {
                var message = xhr.responseJSON && xhr.responseJSON.data ? 
                            xhr.responseJSON.data.message : 
                            markdownPressAdmin.i18n.exportFailed + (error ? ': ' + error : '');
                showError(message);
            }
        });
    }

    function showSuccess(message) {
        var $result = $('#markdown-press-export-result');
        $result.removeClass('notice-info notice-error')
               .addClass('notice-success')
               .html('<p>' + message + '</p>')
               .show();
    }

    function showError(message) {
        var $result = $('#markdown-press-export-result');
        $result.removeClass('notice-info notice-success')
               .addClass('notice-error')
               .html('<p>' + message + '</p>')
               .show();
    }
});
