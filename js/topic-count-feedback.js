(function ($, Drupal, once) {
  'use strict';

  /**
   * Topic count feedback behavior - Integrated UX approach
   */
  Drupal.behaviors.topicCountFeedback = {
    attach: function (context, settings) {
      // Wait for form states to resolve before looking for the field
      setTimeout(function() {
        attachFeedbackBehavior(context, settings);
      }, 100);
    }
  };

  function attachFeedbackBehavior(context, settings) {
    // Try multiple possible field selectors
    let minFrequencyField = $('#edit-tabs-container-content-settings-post-topic-minimum-display-count', context);
    
    // If not found, try alternative selectors
    if (minFrequencyField.length === 0) {
      minFrequencyField = $('input[name="post_topic_minimum_display_count"]', context);
    }
    if (minFrequencyField.length === 0) {
      minFrequencyField = $('input[name*="post_topic_minimum_display_count"]', context);
    }

    if (minFrequencyField.length === 0) {
      return;
    }

    // Use Drupal's once to ensure this only runs once per element
    $(once('ttd_topics-feedback', minFrequencyField)).each(function() {
      const field = $(this);
      
      // Check if content types are enabled
      let enabledContentTypes = $('#edit-tabs-container-content-settings-enabled-content-types', context);
      if (enabledContentTypes.length === 0) {
        enabledContentTypes = $('select[name="enabled_content_types[]"]', context);
      }
      if (enabledContentTypes.length === 0) {
        enabledContentTypes = $('select[name*="enabled_content_types"]', context);
      }
      
      if (enabledContentTypes.length > 0 && enabledContentTypes.val().length === 0) {
        return;
      }

      // Check if there's an integrated feedback area from the slider
      let descriptionEl = field.next('.frequency-slider-container').find('.integrated-feedback-area');
      
      // If no integrated area, try to find the original description
      if (descriptionEl.length === 0) {
        // Try multiple ways to find the description element
        descriptionEl = field.siblings('.description');
        
        // If not found as sibling, try in parent container
        if (descriptionEl.length === 0) {
          descriptionEl = field.parent().find('.description');
        }
        
        // If still not found, look for any element containing the description text
        if (descriptionEl.length === 0) {
          const descText = "Only display topics that appear in at least this many posts";
          descriptionEl = field.closest('.form-item, .form-wrapper').find(`*:contains("${descText}")`).last();
        }
        
        // If still not found, create one after the field
        if (descriptionEl.length === 0) {
          descriptionEl = $('<div class="description description--topic-feedback"></div>');
          field.after(descriptionEl);
        }
      }
      

      
      const originalDescription = descriptionEl.text();

      let debounceTimer;
      const DEBOUNCE_DELAY = 300; // Reduced for more responsive feel

      /**
       * Update the description with integrated topic count
       */
      function updateTopicCount(minFrequency) {
        clearTimeout(debounceTimer);

        debounceTimer = setTimeout(function () {
          fetchTopicCount(minFrequency);
        }, DEBOUNCE_DELAY);
      }

      /**
       * Fetch topic count and update description inline
       */
      function fetchTopicCount(minFrequency) {
        // Update description with loading state
        updateDescription('<em>Calculating...</em>', 'loading');

        $.ajax({
          url: '/api/topicalboost/topic-count',
          method: 'GET',
          data: { min_frequency: minFrequency },
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          xhrFields: {
            withCredentials: true
          },
          success: function (response) {
            const count = response.count;
            const total = response.total;
            const percentage = response.percentage;

            let statusText = '';
            let statusClass = '';

            if (count === 0) {
              statusText = `<br><strong>Result:</strong> No topics will be displayed.`;
              statusClass = 'warning';
            } else {
              statusText = `<br><strong>Result:</strong> ${count.toLocaleString()} topics (${percentage}% of ${total.toLocaleString()}) will be displayed.`;
              statusClass = percentage < 20 ? 'warning' : 'success';
            }

            updateDescription(statusText, statusClass);
          },
          error: function (xhr, status, error) {
            let errorMsg = 'Unable to calculate';
            if (xhr.status === 403) {
              errorMsg = 'Access denied. Please check permissions.';
            } else if (xhr.status === 404) {
              errorMsg = 'API endpoint not found.';
            } else if (xhr.status === 500) {
              errorMsg = 'Server error occurred.';
            }
            
            updateDescription('<strong>Error:</strong> ' + errorMsg, 'error');
          }
        });
      }

      /**
       * Update the integrated feedback area with new text and status
       */
      function updateDescription(text, statusClass) {
        // Find or create the integrated feedback area inside the slider container
        let feedbackArea = $('.frequency-slider-container .integrated-feedback-area');
        if (feedbackArea.length === 0) {
          feedbackArea = $('<div class="integrated-feedback-area"></div>');
          $('.frequency-slider-container').append(feedbackArea);
        }
        
        // If we have a result, format it with the new structure
        if (text.includes('<strong>Result:</strong>')) {
          const resultMatch = text.match(/<strong>Result:<\/strong>\s*(.+)/);
          if (resultMatch) {
            const resultText = resultMatch[1];
            
            // Determine the appropriate status class based on content
            let feedbackClass = 'loading';
            if (resultText.includes('Calculating')) {
              feedbackClass = 'loading';
            } else if (resultText.includes('No topics will be displayed')) {
              feedbackClass = 'error';
            } else if (resultText.includes('Error:') || resultText.includes('Unable to calculate')) {
              feedbackClass = 'error';
            } else {
              // Parse the percentage to determine status
              const percentageMatch = resultText.match(/\((\d+(?:\.\d+)?)%/);
              if (percentageMatch) {
                const percentage = parseFloat(percentageMatch[1]);
                if (percentage < 5) {
                  feedbackClass = 'warning'; // Very few topics
                } else if (percentage < 20) {
                  feedbackClass = 'warning'; // Limited topics
                } else {
                  feedbackClass = 'success'; // Good range
                }
              } else {
                feedbackClass = 'success';
              }
            }
            
            // Create structured feedback HTML for the integrated area
            const feedbackHtml = `
              <div class="topic-feedback-result ${feedbackClass}">
                <div class="result-value">${resultText}</div>
              </div>
            `;
            
            feedbackArea.html(feedbackHtml);
          } else {
            // Fallback to simple formatting
            feedbackArea.html(`<div class="topic-feedback-result ${statusClass}"><div class="result-value">${text}</div></div>`);
          }
        } else {
          // Just update the text without special formatting
          feedbackArea.html(`<div class="topic-feedback-result ${statusClass}"><div class="result-value">${text}</div></div>`);
        }
        
        // Clear any old feedback from the description field and restore original static text
        if (descriptionEl.find('.topic-feedback-result').length > 0) {
          descriptionEl.html('Controls which topics appear on your site based on how often they\'re mentioned. Set to 5 to only show topics mentioned in at least 5 posts. Lower values (1-3) show more topics including rare ones. Higher values (10+) show only frequently discussed topics. This affects topic pages, topic lists, and frontend displays.');
        }
      }

      // Handle input changes with debouncing
      field.on('input change', function () {
        const value = parseInt($(this).val()) || 1;
        updateTopicCount(value);
      });

      // Initial load
      const initialValue = parseInt(field.val()) || 1;
      updateTopicCount(initialValue);

      // Watch for content type changes
      enabledContentTypes.on('change', function() {
        if ($(this).val().length === 0) {
          // No content types selected - clear the feedback
          descriptionEl.html('');
        } else {
          // Content types selected - update count
          updateTopicCount(parseInt(field.val()) || 1);
        }
      });
    });
  }

  /**
   * Convert number field to slider behavior (optional enhancement)
   */
  Drupal.behaviors.minFrequencySlider = {
    attach: function (context, settings) {
      // Wait for form states to resolve and then check again periodically
      setTimeout(function() {
        attachSliderBehavior(context, settings);
      }, 200);
      
      // Also listen for form state changes
      $(document).on('state:visible', function(e) {
        if ($(e.target).is('input[name*="post_topic_minimum_display_count"]')) {
          setTimeout(function() {
            attachSliderBehavior(context, settings);
          }, 100);
        }
      });
    }
  };

  function attachSliderBehavior(context, settings) {
    // Try multiple possible field selectors
    let minFrequencyField = $('#edit-tabs-container-content-settings-post-topic-minimum-display-count', context);
    
    // If not found, try alternative selectors
    if (minFrequencyField.length === 0) {
      minFrequencyField = $('input[name="post_topic_minimum_display_count"]', context);
    }
    if (minFrequencyField.length === 0) {
      minFrequencyField = $('input[name*="post_topic_minimum_display_count"]', context);
    }

    if (minFrequencyField.length === 0) {
      return;
    }

    // Check if field is visible (not hidden by form states)
    if (!minFrequencyField.is(':visible')) {
      return;
    }

    // Use Drupal's once to ensure this only runs once per element
    $(once('ttd_topics-slider', minFrequencyField)).each(function() {
      const field = $(this);
      
      // Check if content types are enabled
      let enabledContentTypes = $('#edit-tabs-container-content-settings-enabled-content-types', context);
      if (enabledContentTypes.length === 0) {
        enabledContentTypes = $('select[name="enabled_content_types[]"]', context);
      }
      if (enabledContentTypes.length === 0) {
        enabledContentTypes = $('select[name*="enabled_content_types"]', context);
      }
      
      if (enabledContentTypes.length > 0 && enabledContentTypes.val().length === 0) {
        return;
      }

      // Check if slider already exists
      if (field.next('.frequency-slider-container').length > 0) {
        return;
      }

      // Create modern slider structure
      const sliderContainer = $('<div class="frequency-slider-container"></div>');
      
      // Create header with value display only (no redundant title)
      const sliderHeader = $('<div class="slider-header"></div>');
      const sliderValueDisplay = $('<div class="slider-value-display"></div>');
      
      // Create the actual slider
      const slider = $('<input type="range" class="frequency-slider" min="1" max="100" step="1">');
      
      // Create range labels
      const rangeLabels = $('<div class="slider-range-labels"><span>1</span><span>25</span><span>50</span><span>75</span><span>100</span></div>');
      
      // Create container for recommendation zones (will be populated dynamically)
      const recommendationZones = $('<div class="recommendation-zones"></div>');

      // Function to update slider gradient
      function updateSliderGradient(value) {
        const progress = ((value - 1) / 99) * 100;
        slider.css({
          'background': `linear-gradient(90deg, #007cba 0%, #007cba ${progress}%, #e9ecef ${progress}%, #e9ecef 100%)`
        });
      }

      // Set initial values
      const currentValue = parseInt(field.val()) || 5;
      slider.val(currentValue);
      sliderValueDisplay.text(currentValue);
      updateSliderGradient(currentValue);

      // Assemble header
      sliderHeader.append(sliderValueDisplay);
      
      // Create integrated feedback area
      const feedbackArea = $('<div class="integrated-feedback-area"></div>');
      
      // Assemble container
      sliderContainer.append(sliderHeader);
      sliderContainer.append(slider);
      sliderContainer.append(rangeLabels);
      sliderContainer.append(recommendationZones);
      sliderContainer.append(feedbackArea);

      // Insert after the input field and hide the original
      field.after(sliderContainer);
      field.hide();

      // Load dynamic recommendations
      loadRecommendations(recommendationZones);

      // Function to load and display dynamic recommendations
      function loadRecommendations(container) {
        $.ajax({
          url: '/api/topicalboost/recommendations',
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          xhrFields: {
            withCredentials: true
          },
          success: function(response) {
            createRecommendationZones(container, response);
          },
          error: function() {
            // Fallback to static recommendation if API fails
            createStaticRecommendation(container);
          }
        });
      }

      // Function to create dynamic recommendation zones
      function createRecommendationZones(container, data) {
        const zones = ['conservative', 'balanced', 'selective'];
        
        zones.forEach(function(zoneType, index) {
          const zone = data[zoneType];
          if (!zone) return;
          
          // Calculate position on slider (1-100 scale)
          let startPos = ((zone.min - 1) / 99) * 100;
          let endPos = ((zone.max - 1) / 99) * 100;
          let width = endPos - startPos;
          
          // Ensure minimum width for readability (at least 8%)
          const minWidth = 8;
          if (width < minWidth) {
            width = minWidth;
            // Adjust end position to maintain width
            endPos = startPos + width;
          }
          
          // Add spacing between zones (2% buffer)
          if (index > 0) {
            startPos = Math.max(startPos, (index * 12)); // Space zones at least 12% apart
            endPos = startPos + width;
          }
          
          // Ensure zones don't extend beyond 100%
          if (endPos > 100) {
            endPos = 100;
            startPos = Math.max(0, endPos - width);
          }
          
          // Create more descriptive labels following NNG principles
          const descriptiveLabels = {
            'conservative': `Show More Topics`,
            'balanced': `Balanced Approach`, 
            'selective': `Show Fewer Topics`
          };
          
          const tooltips = {
            'conservative': `Show more topics by requiring fewer posts (${zone.min}-${zone.max}). ${zone.description}`,
            'balanced': `Balanced approach (${zone.min}-${zone.max} posts). ${zone.description}`,
            'selective': `Show fewer, higher-quality topics (${zone.min}-${zone.max} posts). ${zone.description}`
          };
          
          const zoneEl = $(`
            <div class="recommendation-zone ${zoneType}" 
                 style="left: ${startPos}%; width: ${width}%;"
                 title="${tooltips[zoneType]}"
                 data-min="${zone.min}" data-max="${zone.max}">
              <span class="zone-label">${descriptiveLabels[zoneType]}</span>
            </div>
          `);
          
          container.append(zoneEl);
        });
      }

      // Function to create static fallback recommendation
      function createStaticRecommendation(container) {
        const staticZone = $(`
          <div class="recommendation-zone balanced static" 
               style="left: 15%; width: 20%;"
               title="Balanced approach (5-15 posts) - moderate topic filtering">
            <span class="zone-label">Balanced Approach</span>
          </div>
        `);
        container.append(staticZone);
      }

      // Sync slider and input
      slider.on('input', function() {
        const value = parseInt($(this).val());
        field.val(value).trigger('change');
        sliderValueDisplay.text(value);
        updateSliderGradient(value);
      });

      field.on('input change', function() {
        const value = parseInt($(this).val()) || 1;
        slider.val(value);
        sliderValueDisplay.text(value);
        updateSliderGradient(value);
      });
    });
  }

})(jQuery, Drupal, once);