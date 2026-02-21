/**
 * MarkdownPress — Admin JavaScript
 * Handles generate/clear AJAX actions and status polling.
 */
(function ($) {
    'use strict';

    var polling = null;

    // Generate Now button.
    $('#mdp-generate-now').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).text('Starting...');

        $.post(mdpAdmin.ajaxUrl, {
            action: 'mdp_generate_now',
            nonce: mdpAdmin.nonce,
        }, function (response) {
            if (response.success) {
                var data = response.data;
                $btn.text('Generating...');
                showProgress(data.status);

                // Start polling for status updates.
                startPolling();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Generate Now');
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Generate Now');
        });
    });

    // Stop Generation button.
    $('#mdp-stop-generation').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if (!confirm('Stop the current generation? You can start again later.')) return;

        $btn.prop('disabled', true).text('Stopping...');

        $.post(mdpAdmin.ajaxUrl, {
            action: 'mdp_stop_generation',
            nonce: mdpAdmin.nonce,
        }, function (response) {
            if (response.success) {
                stopPolling();
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
                $btn.prop('disabled', false).text('Stop');
            }
        });
    });

    // Clear Cache button.
    $('#mdp-clear-cache').on('click', function (e) {
        e.preventDefault();

        if (!confirm('Delete all cached markdown files? This cannot be undone.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(mdpAdmin.ajaxUrl, {
            action: 'mdp_clear_cache',
            nonce: mdpAdmin.nonce,
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
            }
            $btn.prop('disabled', false);
        });
    });

    // Status polling.
    function startPolling() {
        if (polling) return;

        polling = setInterval(function () {
            $.post(mdpAdmin.ajaxUrl, {
                action: 'mdp_get_status',
                nonce: mdpAdmin.nonce,
            }, function (response) {
                if (!response.success) return;

                var data = response.data;
                var status = data.status;

                showProgress(status);

                // Update card numbers.
                // If queue is empty, generation is done.
                if (data.remaining === 0) {
                    stopPolling();
                    $('#mdp-generate-now')
                        .prop('disabled', false)
                        .html('<span class="dashicons dashicons-controls-play"></span> Generate Now');
                    $('#mdp-status-text').text('Done!');
                    $('#mdp-status-detail').text(status.processed + ' pages processed' + (status.errors > 0 ? ' (' + status.errors + ' errors)' : ''));

                    // Reload after a moment to update all stats.
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                }
            });
        }, 5000);
    }

    function stopPolling() {
        if (polling) {
            clearInterval(polling);
            polling = null;
        }
    }

    function showProgress(status) {
        var $progress = $('#mdp-progress');
        $progress.show();

        var pct = status.total > 0 ? Math.round(status.processed / status.total * 100) : 0;
        $progress.find('.mdp-progress-fill').css('width', pct + '%');
        $('#mdp-progress-text').text(status.processed + ' / ' + status.total + ' pages (' + pct + '%)');
        $('#mdp-status-text').text('Processing...');
        $('#mdp-status-detail').text(status.processed + ' / ' + status.total);
    }

    // If already processing on page load, start polling.
    if ($('#mdp-status-text').text().trim() === 'Processing...') {
        startPolling();
    }

})(jQuery);