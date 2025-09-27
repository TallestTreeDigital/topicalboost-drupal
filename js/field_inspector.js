(function ($, Drupal) {
  'use strict';

  /**
   * Field Inspector with Autocomplete Search functionality for TopicalBoost.
   */
  Drupal.behaviors.topicalBoostFieldInspector = {
    attach: function (context) {
      const $inspector = $('.field-inspector-container', context);
      if ($inspector.length === 0) {
        return;
      }

      // Initialize field inspector with autocomplete search
      this.initFieldInspector($inspector);
    },

    initFieldInspector: function ($container) {
      const self = this;

      // Initialize autocomplete search
      this.initAutocompleteSearch($container);

      // Handle field checkbox changes (keep existing functionality)
      $container.on('change', '.field-checkbox', function () {
        const fieldName = $(this).val();
        const isChecked = $(this).is(':checked');
        self.updateMainSelect(fieldName, isChecked, $container);
      });

      // Update field count display when main select changes
      $('#edit-analysis-custom-fields').on('change', function () {
        self.updateFieldCount($container);
      });

      // Initial field count update
      this.updateFieldCount($container);
    },

    initAutocompleteSearch: function ($container) {
      const self = this;
      const $searchInput = $container.find('.field-inspector-search');
      const $resultsDropdown = $container.find('.search-results-dropdown');

      if ($searchInput.length === 0) {
        return;
      }

      let searchTimeout;
      let searchCache = {};
      let currentRequest;

      // Handle search input
      $searchInput.on('input', function () {
        const query = $(this).val().trim();

        // Clear previous timeout
        if (searchTimeout) {
          clearTimeout(searchTimeout);
        }

        // Cancel previous request
        if (currentRequest) {
          currentRequest.abort();
        }

        // Hide dropdown if query is too short
        if (query.length < 2) {
          $resultsDropdown.hide().empty();
          return;
        }

        // Check cache first
        if (searchCache[query]) {
          self.displaySearchResults(searchCache[query], $resultsDropdown, $container);
          return;
        }

        // Debounce search
        searchTimeout = setTimeout(function () {
          self.performSearch(query, $searchInput, $resultsDropdown, $container, searchCache);
        }, 300);
      });

      // Handle keyboard navigation
      $searchInput.on('keydown', function (e) {
        const $visibleResults = $resultsDropdown.find('.search-result-item:visible');
        const $active = $visibleResults.filter('.active');

        switch (e.which) {
          case 40: // Down arrow
            e.preventDefault();
            if ($active.length === 0) {
              $visibleResults.first().addClass('active');
            } else {
              $active.removeClass('active').next().addClass('active');
              if ($visibleResults.filter('.active').length === 0) {
                $visibleResults.first().addClass('active');
              }
            }
            break;

          case 38: // Up arrow
            e.preventDefault();
            if ($active.length === 0) {
              $visibleResults.last().addClass('active');
            } else {
              $active.removeClass('active').prev().addClass('active');
              if ($visibleResults.filter('.active').length === 0) {
                $visibleResults.last().addClass('active');
              }
            }
            break;

          case 13: // Enter
            e.preventDefault();
            if ($active.length > 0) {
              $active.click();
            }
            break;

          case 27: // Escape
            e.preventDefault();
            $resultsDropdown.hide();
            $(this).blur();
            break;
        }
      });

      // Handle clicks outside to close dropdown
      $(document).on('click', function (e) {
        if (!$container.find(e.target).length) {
          $resultsDropdown.hide();
        }
      });

      // Handle focus to show cached results
      $searchInput.on('focus', function () {
        const query = $(this).val().trim();
        if (query.length >= 2 && searchCache[query]) {
          self.displaySearchResults(searchCache[query], $resultsDropdown, $container);
        }
      });
    },

    performSearch: function (query, $searchInput, $resultsDropdown, $container, cache) {
      const self = this;
      const searchUrl = $searchInput.data('search-url') || '/api/topicalboost/field-inspector/search';

      // Show loading state
      $resultsDropdown.html('<div class="search-loading"><div class="loading-spinner"></div><span>Searching...</span></div>').show();

      // Perform AJAX search
      const currentRequest = $.ajax({
        url: searchUrl,
        method: 'GET',
        data: { q: query },
        dataType: 'json',
        timeout: 10000,
        success: function (data) {
          // Cache the results
          cache[query] = data;

          // Display results
          self.displaySearchResults(data, $resultsDropdown, $container);
        },
        error: function (xhr, status) {
          if (status !== 'abort') {
            $resultsDropdown.html('<div class="search-error">Error searching posts. Please try again.</div>').show();
            setTimeout(function () {
              $resultsDropdown.hide();
            }, 3000);
          }
        }
      });

      // Store current request reference for potential cancellation
      this.currentRequest = currentRequest;
    },

    displaySearchResults: function (data, $resultsDropdown, $container) {
      const self = this;

      if (!data.nodes || data.nodes.length === 0) {
        $resultsDropdown.html('<div class="search-no-results">No posts found matching your search.</div>').show();
        return;
      }

      let html = '<div class="search-results-list">';

      data.nodes.forEach(function (node) {
        html += `<div class="search-result-item" data-node-id="${node.nid}">
          <div class="result-title">${Drupal.checkPlain(node.title)}</div>
          <div class="result-meta">
            <span class="result-type">${Drupal.checkPlain(node.type_label)}</span>
            <span class="result-date">${node.changed_formatted}</span>
          </div>
        </div>`;
      });

      html += '</div>';

      $resultsDropdown.html(html).show();

      // Handle result selection
      $resultsDropdown.off('click', '.search-result-item').on('click', '.search-result-item', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const nodeId = $(this).data('node-id');
        const nodeTitle = $(this).find('.result-title').text();

        // Update search input with selected title
        $container.find('.field-inspector-search').val(nodeTitle);

        // Hide dropdown
        $resultsDropdown.hide();

        // Inspect the selected node
        self.inspectNode(nodeId, $container);
      });

      // Handle hover states
      $resultsDropdown.off('mouseenter mouseleave', '.search-result-item')
        .on('mouseenter', '.search-result-item', function () {
          $(this).addClass('active').siblings().removeClass('active');
        })
        .on('mouseleave', '.search-result-item', function () {
          $(this).removeClass('active');
        });
    },

    inspectNode: function (nodeId, $container) {
      const self = this;

      this.showLoading($container.find('.field-list'), 'Inspecting node fields...');

      // Disable the main select during inspection
      $('#edit-analysis-custom-fields').prop('disabled', true);

      $.ajax({
        url: `/admin/topicalboost/field-inspector/${nodeId}`,
        method: 'GET',
        dataType: 'json',
        success: function (data) {
          self.renderFieldList(data, $container);
          self.showMessage($container, `Inspected "${data.node_title}" (${data.content_type})`, 'success');
        },
        error: function (xhr) {
          const message = xhr.status === 404 ? 'Node not found.' : 'Error inspecting node.';
          self.showMessage($container, message, 'error');
          $container.find('.field-list').empty();
        },
        complete: function () {
          $('#edit-analysis-custom-fields').prop('disabled', false);
        }
      });
    },

    renderFieldList: function (data, $container) {
      const $fieldList = $container.find('.field-list');
      const selectedFields = this.getSelectedFields();

      if (data.fields.length === 0) {
        $fieldList.html('<p class="no-fields">No text-compatible fields found for this node.</p>');
        return;
      }

      let html = '<div class="field-checkboxes">';
      html += `<h4>Available Fields for "${Drupal.checkPlain(data.node_title)}"</h4>`;

      data.fields.forEach(function (field) {
        const isChecked = selectedFields.includes(field.machine_name) ? 'checked' : '';
        const sampleValue = field.sample_value ? Drupal.checkPlain(field.sample_value) : '<em>Empty</em>';

        html += `<div class="field-item">
          <label class="field-checkbox-label">
            <input type="checkbox" class="field-checkbox" value="${field.machine_name}" ${isChecked}>
            <span class="field-label">${Drupal.checkPlain(field.label)}</span>
            <span class="field-machine-name">(${field.machine_name})</span>
          </label>
          <div class="field-sample">
            <strong>Type:</strong> ${field.type} <br>
            <strong>Sample:</strong> ${sampleValue}
          </div>
        </div>`;
      });
      html += '</div>';

      $fieldList.html(html);
    },

    updateMainSelect: function (fieldName, isChecked, $container) {
      const $mainSelect = $('#edit-analysis-custom-fields');
      const currentValues = $mainSelect.val() || [];

      if (isChecked) {
        if (!currentValues.includes(fieldName)) {
          currentValues.push(fieldName);
        }
      } else {
        const index = currentValues.indexOf(fieldName);
        if (index > -1) {
          currentValues.splice(index, 1);
        }
      }

      $mainSelect.val(currentValues);
      $mainSelect.trigger('change');
      this.updateFieldCount($container);
    },

    getSelectedFields: function () {
      const $mainSelect = $('#edit-analysis-custom-fields');
      return $mainSelect.val() || [];
    },

    updateFieldCount: function ($container) {
      const selectedCount = this.getSelectedFields().length;
      const $counter = $container.find('.field-count-badge');

      if ($counter.length > 0) {
        $counter.text(`${selectedCount} selected`);
        $counter.toggleClass('has-fields', selectedCount > 0);
      }
    },

    showLoading: function ($target, message = 'Loading...') {
      $target.html(`<div class="loading-indicator">
        <div class="loading-spinner"></div>
        <span class="loading-text">${message}</span>
      </div>`);
    },

    showMessage: function ($container, message, type = 'info') {
      const $messages = $container.find('.field-inspector-messages');
      const alertClass = type === 'error' ? 'alert-danger' :
                        type === 'success' ? 'alert-success' : 'alert-info';

      const messageHtml = `<div class="alert ${alertClass}" role="alert">
        ${Drupal.checkPlain(message)}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
      </div>`;

      $messages.html(messageHtml);

      // Auto-hide after 5 seconds
      setTimeout(function () {
        $messages.find('.alert').fadeOut();
      }, 5000);
    }
  };

  // Handle close button clicks for messages
  $(document).on('click', '.field-inspector-messages .close', function () {
    $(this).closest('.alert').fadeOut();
  });

})(jQuery, Drupal);