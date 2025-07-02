(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.ttdTopicsSelect2 = {
    attach: function (context, settings) {
      Drupal.ttd_topics.debug.log('TtdTopics Select2 behavior attached');
      
      // Check if Select2 is available
      if (typeof $.fn.select2 === 'undefined') {
        Drupal.ttd_topics.debug.error('TtdTopics: Select2 library not loaded');
        return;
      }
      
      // Find Select2 elements
      const select2Elements = $('.ttd-topics-select2', context);
      Drupal.ttd_topics.debug.log('Found ' + select2Elements.length + ' Select2 elements');
      
      // Initialize Select2 on the content types field
      select2Elements.once('ttd-topics-select2').each(function () {
        const $element = $(this);
        Drupal.ttd_topics.debug.log('Initializing Select2 on element:', this);
        
        try {
          $element.select2({
            width: '100%',
            placeholder: $element.attr('data-placeholder') || 'Select options...',
            allowClear: true,
            theme: 'default',
            containerCssClass: 'ttd-topics-select2-container',
            dropdownCssClass: 'ttd-topics-select2-dropdown'
          });
          
          // Ensure form values are properly synchronized when changes occur
          $element.on('select2:select select2:unselect', function (e) {
            Drupal.ttd_topics.debug.log('Select2 selection changed:', e.type);
            // Trigger change event on the original element to ensure Drupal form handling
            $(this).trigger('change');
          });
          
          // Fix layout issues immediately and after initialization
          const $formItem = $element.closest('.form-item, .js-form-item');
          const $description = $formItem.find('.description');
          
          // Immediate fix - apply styles right away
          if ($description.length) {
            $formItem.css({
              'min-height': '140px',
              'overflow': 'visible',
              'position': 'relative',
              'padding-bottom': '30px'
            });
            
            $description.css({
              'position': 'relative',
              'z-index': '10',
              'display': 'block',
              'overflow': 'visible',
              'margin-top': '12px',
              'clear': 'both',
              'line-height': '1.5'
            });
            
            Drupal.ttd_topics.debug.log('Select2 layout fixed immediately');
          }
          
          // Additional fix after Select2 fully renders
          setTimeout(function() {
            const $select2Container = $formItem.find('.select2-container');
            if ($description.length && $select2Container.length) {
              // Ensure description remains visible and properly positioned
              $description.css({
                'display': 'block',
                'visibility': 'visible',
                'opacity': '1'
              });
              
              Drupal.ttd_topics.debug.log('Select2 layout verified after render');
            }
          }, 50); // Shorter timeout for quicker response
          
          Drupal.ttd_topics.debug.log('Select2 initialized successfully');
        } catch (error) {
          Drupal.ttd_topics.debug.error('Select2 initialization failed:', error);
        }
      });
    }
  };

})(jQuery, Drupal);
