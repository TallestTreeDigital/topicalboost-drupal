(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.ttdTroubleshoot = {
    attach: function (context) {
      $(once('ttd-troubleshoot', '#ttd-troubleshoot-form', context)).each(function () {
        var $form = $(this);

        // Select all checkbox.
        $form.find('#cb-select-all').on('change', function () {
          $form.find('input[name="post_ids[]"]').prop('checked', $(this).prop('checked'));
          updateButtonStates();
        });

        $form.find('input[name="post_ids[]"]').on('change', updateButtonStates);

        function updateButtonStates() {
          var hasSelection = $form.find('input[name="post_ids[]"]:checked').length > 0;
          $form.find('#clear-selected, #reanalyze-selected').prop('disabled', !hasSelection);
        }

        updateButtonStates();

        // Bulk actions.
        $form.find('#clear-selected').on('click', function () {
          handleBulkAction('clear');
        });

        $form.find('#reanalyze-selected').on('click', function () {
          handleBulkAction('reanalyze');
        });

        // Single post actions.
        $form.on('click', '.clear-single', function () {
          handleAction('clear', [$(this).data('post-id')]);
        });

        $form.on('click', '.reanalyze-single', function () {
          handleAction('reanalyze', [$(this).data('post-id')]);
        });

        // Clear rejected topics.
        $form.on('click', '.clear-rejected', function () {
          var postId = $(this).data('post-id');
          var $button = $(this);

          if (!confirm('Are you sure you want to clear all rejected topics for this post?')) {
            return;
          }

          $button.prop('disabled', true);

          $.ajax({
            url: '/api/topicalboost/troubleshoot/clear-rejected-topics',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ post_id: postId }),
            dataType: 'json',
            success: function (response) {
              if (response.success) {
                location.reload();
              } else {
                alert('Error: ' + ((response.data && response.data.message) || 'Unknown error'));
                $button.prop('disabled', false);
              }
            },
            error: function () {
              alert('Network error occurred');
              $button.prop('disabled', false);
            }
          });
        });

        function handleBulkAction(action) {
          var selectedPosts = $form.find('input[name="post_ids[]"]:checked').map(function () {
            return parseInt($(this).val(), 10);
          }).get();

          if (selectedPosts.length === 0) {
            alert('Please select at least one post');
            return;
          }

          handleAction(action, selectedPosts);
        }

        function handleAction(action, postIds) {
          var actionText = action === 'clear' ? 'clear the progress flag from' : 'clear and re-analyze';
          if (!confirm('Are you sure you want to ' + actionText + ' ' + postIds.length + ' post(s)?')) {
            return;
          }

          var $buttons = postIds.length === 1
            ? $form.find('button[data-post-id="' + postIds[0] + '"]')
            : $form.find('#clear-selected, #reanalyze-selected');

          $buttons.prop('disabled', true);

          $.ajax({
            url: '/api/topicalboost/troubleshoot/stuck-posts',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ post_ids: postIds, operation: action }),
            dataType: 'json',
            success: function (response) {
              if (response.success) {
                location.reload();
              } else {
                alert('Error: ' + ((response.data && response.data.message) || 'Unknown error'));
                $buttons.prop('disabled', false);
              }
            },
            error: function () {
              alert('Network error occurred');
              $buttons.prop('disabled', false);
            }
          });
        }
      });
    }
  };

})(jQuery, Drupal, once);
