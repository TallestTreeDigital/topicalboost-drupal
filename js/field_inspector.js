(function ($, Drupal) {
  'use strict';

  /**
   * Field Inspector functionality for TopicalBoost custom fields.
   */
  Drupal.behaviors.topicalBoostFieldInspector = {
    attach: function (context) {
      const $inspector = $('.field-inspector-container', context);
      if ($inspector.length === 0) {
        return;
      }

      // Initialize field inspector
      this.initFieldInspector($inspector);
    },

    initFieldInspector: function ($container) {
      const self = this;
      
      // Load recent nodes on initialization
      this.loadRecentNodes($container);

      // Handle recent node button clicks
      $container.on('click', '.recent-node-button', function (e) {
        e.preventDefault();
        const nodeId = $(this).data('node-id');
        self.inspectNode(nodeId, $container);
      });

      // Handle manual node ID input
      $container.on('click', '.inspect-node-button', function (e) {
        e.preventDefault();
        const nodeId = $container.find('.node-id-input').val() || $container.find('input[class*="node-id-input"]').val();
        if (nodeId && /^\d+$/.test(nodeId)) {
          self.inspectNode(nodeId, $container);
        } else {
          self.showMessage($container, 'Please enter a valid node ID.', 'error');
        }
      });

      // Handle field checkbox changes
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

    loadRecentNodes: function ($container) {
      const self = this;
      
      this.showLoading($container.find('.recent-nodes-list'), 'Loading recent nodes...');

      $.ajax({
        url: '/admin/topicalboost/field-inspector/recent-nodes',
        method: 'GET',
        dataType: 'json',
        success: function (data) {
          self.renderRecentNodes(data.nodes, $container);
        },
        error: function () {
          self.showMessage($container, 'Error loading recent nodes.', 'error');
        }
      });
    },

    renderRecentNodes: function (nodes, $container) {
      const $nodesList = $container.find('.recent-nodes-list');
      
      if (nodes.length === 0) {
        $nodesList.html('<p class="no-nodes">No recent nodes with custom fields found.</p>');
        return;
      }

      let html = '<div class="recent-nodes-buttons">';
      nodes.forEach(function (node) {
        const date = new Date(node.changed * 1000).toLocaleDateString();
        html += `<button type="button" class="recent-node-button button button--small" data-node-id="${node.nid}">
          <span class="node-title">${Drupal.checkPlain(node.title)}</span>
          <span class="node-meta">${Drupal.checkPlain(node.type)} - ${date}</span>
        </button>`;
      });
      html += '</div>';

      $nodesList.html(html);
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