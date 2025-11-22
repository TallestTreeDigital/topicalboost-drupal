// Check for required globals
if (typeof Drupal === 'undefined') {
  console.error('❌ Drupal is not defined! This file cannot function without Drupal.');
  throw new Error('Drupal global not available');
}

if (typeof once === 'undefined') {
  console.error('❌ once is not defined! This file cannot function without once.');
  throw new Error('once function not available');
}

// Immediately run the IIFE
((Drupal, once) => {
  'use strict';

  Drupal.behaviors.ttdCodeExamples = {
    attach: (context) => {
      const blocks = document.querySelectorAll('.ttd-code-block');

      once('ttd-code-examples', '.ttd-code-block', context).forEach((block, index) => {
        const header = block.querySelector('.ttd-code-header');
        const toggle = block.querySelector('.ttd-code-toggle');
        const content = block.querySelector('.ttd-code-content');

        if (!header || !toggle || !content) {
          console.error(`Missing required elements in code block ${index}`);
          return;
        }

        // Initialize state
        content.classList.remove('visible');
        toggle.setAttribute('aria-expanded', 'false');

        // Toggle handler for click and keyboard events
        const handleToggle = (e) => {
          // For keyboard events, only trigger on Enter or Space
          if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
            return;
          }

          e.preventDefault();
          e.stopPropagation();

          const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

          if (isExpanded) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<span class="ttd-code-toggle-icon">▶</span> Expand';
            content.classList.remove('visible');
          } else {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.innerHTML = '<span class="ttd-code-toggle-icon">▶</span> Collapse';
            content.classList.add('visible');
          }
        };

        // Attach listeners (click and keyboard for accessibility)
        header.addEventListener('click', handleToggle);
        toggle.addEventListener('click', handleToggle);
        toggle.addEventListener('keydown', handleToggle);
      });
    }
  };
})(Drupal, once);
