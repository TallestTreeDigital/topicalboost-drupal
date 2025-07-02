(function (Drupal, drupalSettings, $) {
  'use strict';

  Drupal.behaviors.ttdTopicsApiValidation = {
    attach: function (context, settings) {
      // Initialize toggle switches
      initializeToggles(context);
      
      const apiKeyField = context.querySelector('#ttd-topics-api-key-field');
      const indicator = context.querySelector('#api-key-validation-indicator');

      if (apiKeyField && indicator) {
        indicator.style.display = 'none';
        indicator.style.opacity = '0';
        indicator.style.visibility = 'hidden';

        let validationTimeout;

        // Add event listeners for real-time validation
        apiKeyField.addEventListener('input', function () {
          Drupal.ttd_topics.debug.log('API key input changed, length:', this.value.trim().length);
          clearTimeout(validationTimeout);
          const apiKey = this.value.trim();

          if (apiKey.length < 10) {
            Drupal.ttd_topics.debug.log('API key too short, resetting validation');
            resetValidation();
            return;
          }

          Drupal.ttd_topics.debug.log('API key long enough, setting validation timeout');
          // Debounce validation - wait 1 second after user stops typing
          validationTimeout = setTimeout(function () {
            Drupal.ttd_topics.debug.log('Validation timeout triggered, validating key');
            validateApiKey(apiKey);
          }, 1000);
        });

        // Validate on page load if API key exists
        const initialApiKey = apiKeyField.value.trim();
        if (initialApiKey.length >= 10) {
          setTimeout(function () {
            validateApiKey(initialApiKey);
          }, 500);
        }
      }

      function validateApiKey(apiKey) {
        Drupal.ttd_topics.debug.log('Starting API key validation for:', apiKey.substring(0, 8) + '...');

        // Reset any existing glow before starting validation
        resetValidation();

        showIndicator('loading', 'Validating...');

        // Use our server-side endpoint instead of calling external API directly
        const endpoint = '/api/topicalboost/validate-api-key';
        Drupal.ttd_topics.debug.log('Validating against server endpoint:', endpoint);

        fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            api_key: apiKey
          })
        })
        .then(function (response) {
          Drupal.ttd_topics.debug.log('Received response status:', response.status);
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
          return response.json();
        })
        .then(function (response) {
          Drupal.ttd_topics.debug.log('Parsed response:', response);
          if (response.valid) {
            const siteName = response.site_name || 'API';
            Drupal.ttd_topics.debug.log('API key validated successfully, applying success glow');
            showIndicator('success', `Valid - ${siteName}`);
            addFieldGlow('success');
          } else {
            const error = response.error || 'Invalid API key';
            Drupal.ttd_topics.debug.log('API key validation failed, applying error glow');
            showIndicator('error', error);
            addFieldGlow('error');
          }
        })
        .catch(function (error) {
          Drupal.ttd_topics.debug.error('API validation error:', error);

          let errorMessage = 'Validation failed';
          if (error.message.includes('404')) {
            errorMessage = 'Endpoint not found';
          } else if (error.message.includes('Failed to fetch')) {
            errorMessage = 'Network error';
          } else if (error.message.includes('CORS')) {
            errorMessage = 'CORS error';
          }

          Drupal.ttd_topics.debug.log('API validation caught error, applying error glow');
          showIndicator('error', errorMessage);
          addFieldGlow('error');
        });
      }

      function showIndicator(type, message) {
        const indicator = context.querySelector('#api-key-validation-indicator');
        const apiKeyField = context.querySelector('#ttd-topics-api-key-field');
        if (!indicator || !apiKeyField) { return;
        }

        let icon = '';
        let className = 'api-key-indicator';

        switch (type) {
          case 'success':
            icon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20,6 9,17 4,12"></polyline></svg>`;
            className += ' api-key-valid';
            break;

          case 'error':
            icon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>`;
            className += ' api-key-invalid';
            break;

          case 'loading':
            icon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 11-6.219-8.56"></path></svg>`;
            className += ' api-key-loading';
            break;
        }

        indicator.className = className;
        indicator.innerHTML = `<span class="api-key-icon">${icon}</span>`;

        // Show the indicator
        indicator.style.display = 'flex';
        indicator.style.opacity = '1';
        indicator.style.visibility = 'visible';
      }

      function addFieldGlow(type) {
        const apiKeyField = context.querySelector('#ttd-topics-api-key-field');
        if (!apiKeyField) {
          Drupal.ttd_topics.debug.log('No API key field found for glow effect');
          return;
        }

        Drupal.ttd_topics.debug.log(`Adding field glow: ${type}`);

        // Force remove existing glow classes immediately
        const hadSuccess = apiKeyField.classList.contains('field-glow-success');
        const hadError = apiKeyField.classList.contains('field-glow-error');
        Drupal.ttd_topics.debug.log(`Removing existing glows - had success: ${hadSuccess}, had error: ${hadError}`);

        apiKeyField.classList.remove('field-glow-success', 'field-glow-error');

        // Force a repaint before adding new class
        apiKeyField.offsetHeight; // Trigger reflow

        // Add appropriate glow with a slight delay to ensure CSS transitions work
        setTimeout(() => {
          if (type === 'success') {
            apiKeyField.classList.add('field-glow-success');
            Drupal.ttd_topics.debug.log('Added success glow class');
            Drupal.ttd_topics.debug.log('Field classes now:', apiKeyField.className);
          } else if (type === 'error') {
            apiKeyField.classList.add('field-glow-error');
            Drupal.ttd_topics.debug.log('Added error glow class');
            Drupal.ttd_topics.debug.log('Field classes now:', apiKeyField.className);
          }
        }, 50); // Increased delay to ensure proper application
      }

      function resetValidation() {
        Drupal.ttd_topics.debug.log('Resetting validation state');
        const indicator = context.querySelector('#api-key-validation-indicator');
        const apiKeyField = context.querySelector('#ttd-topics-api-key-field');

        if (indicator) {
          indicator.style.display = 'none';
          indicator.style.opacity = '0';
          indicator.style.visibility = 'hidden';
          Drupal.ttd_topics.debug.log('Reset indicator visibility');
        }

        if (apiKeyField) {
          // Check current state before removing
          const hadSuccess = apiKeyField.classList.contains('field-glow-success');
          const hadError = apiKeyField.classList.contains('field-glow-error');
          Drupal.ttd_topics.debug.log(`Resetting field glow - had success: ${hadSuccess}, had error: ${hadError}`);

          // Remove glow with proper timing
          apiKeyField.classList.remove('field-glow-success', 'field-glow-error');
          Drupal.ttd_topics.debug.log('Removed all glow classes in reset');
        }
      }
    }
  };

  function initializeToggles(context) {
    // Find checkboxes with ttd_topics-toggle class within the context
    const toggleCheckboxes = context.querySelectorAll('input[type="checkbox"].ttd_topics-toggle');
    
    toggleCheckboxes.forEach(function(checkbox) {
      // Skip if already processed
      if (checkbox.dataset.toggleProcessed) {
        return;
      }
      
      // Mark as processed
      checkbox.dataset.toggleProcessed = 'true';
      
      // Find the form-item wrapper
      const formItem = checkbox.closest('.form-item');
      if (!formItem) return;
      
      // Create toggle container
      const toggleContainer = document.createElement('div');
      toggleContainer.className = 'ttd_topics-toggle';
      
      // Create slider element
      const slider = document.createElement('span');
      slider.className = 'ttd_topics-toggle-slider';
      
      // Move checkbox into toggle container (keep original, don't clone)
      const checkboxParent = checkbox.parentNode;
      toggleContainer.appendChild(checkbox);
      toggleContainer.appendChild(slider);
      
      // Insert toggle container where checkbox was
      checkboxParent.appendChild(toggleContainer);
      
      // Update slider state based on checkbox
      function updateSlider() {
        if (checkbox.checked) {
          slider.classList.add('active');
        } else {
          slider.classList.remove('active');
        }
      }
      
      // Set initial state
      updateSlider();
      
      // Add change listener to checkbox
      checkbox.addEventListener('change', updateSlider);
      
      // Make slider clickable
      slider.addEventListener('click', function(e) {
        e.stopPropagation();
        checkbox.click(); // Use native click to properly trigger all events
      });
      
      // Update label click behavior
      const label = formItem.querySelector('label');
      if (label) {
        // Remove the original for attribute to prevent double-clicking
        label.removeAttribute('for');
        label.addEventListener('click', function(e) {
          e.preventDefault();
          checkbox.click(); // Use native click
        });
      }
    });
  }

})(Drupal, drupalSettings, jQuery);
