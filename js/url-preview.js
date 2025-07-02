(function (Drupal) {
  'use strict';

  Drupal.behaviors.ttd_topicsUrlPreview = {
    attach: function (context, settings) {
      const input = document.getElementById('topic-url-path-input');
      const preview = document.getElementById('url-path-preview');
      
      if (input && preview) {
        // Update preview on input
        input.addEventListener('input', function() {
          let value = this.value.trim();
          
          // Normalize the path
          if (value && !value.startsWith('/')) {
            value = '/' + value;
          }
          if (value && !value.endsWith('/')) {
            value = value + '/';
          }
          
          // Update the preview
          preview.textContent = value || '/topics/';
        });
      }
    }
  };
})(Drupal); 