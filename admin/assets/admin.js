/**
 * WP Markdown Cache — Admin JavaScript
 * Handles generate/clear AJAX actions and status polling.
 */
(function ($) {
    'use strict';

    var polling = null;

    // Generate Now button.
    $('#wpmc-generate-now').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).text('Starting...');

        $.post(wpmcAdmin.ajaxUrl, {
            action: 'wpmc_generate_now',
            nonce: wpmcAdmin.nonce,
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

    // Clear Cache button.
    $('#wpmc-clear-cache').on('click', function (e) {
        e.preventDefault();

        if (!confirm('Delete all cached markdown files? This cannot be undone.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(wpmcAdmin.ajaxUrl, {
            action: 'wpmc_clear_cache',
            nonce: wpmcAdmin.nonce,
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
            $.post(wpmcAdmin.ajaxUrl, {
                action: 'wpmc_get_status',
                nonce: wpmcAdmin.nonce,
            }, function (response) {
                if (!response.success) return;

                var data = response.data;
                var status = data.status;

                showProgress(status);

                // Update card numbers.
                // If queue is empty, generation is done.
                if (data.remaining === 0) {
                    stopPolling();
                    $('#wpmc-generate-now')
                        .prop('disabled', false)
                        .html('<span class="dashicons dashicons-controls-play"></span> Generate Now');
                    $('#wpmc-status-text').text('Done!');
                    $('#wpmc-status-detail').text(status.processed + ' pages processed' + (status.errors > 0 ? ' (' + status.errors + ' errors)' : ''));

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
        var $progress = $('#wpmc-progress');
        $progress.show();

        var pct = status.total > 0 ? Math.round(status.processed / status.total * 100) : 0;
        $progress.find('.wpmc-progress-fill').css('width', pct + '%');
        $('#wpmc-progress-text').text(status.processed + ' / ' + status.total + ' pages (' + pct + '%)');
        $('#wpmc-status-text').text('Processing...');
        $('#wpmc-status-detail').text(status.processed + ' / ' + status.total);
    }

    // If already processing on page load, start polling.
    if ($('#wpmc-status-text').text().trim() === 'Processing...') {
        startPolling();
    }

})(jQuery);
