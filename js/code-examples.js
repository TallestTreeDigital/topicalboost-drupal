// DEBUG: Check if this file is loading at all
console.log('🔧 code-examples.js loaded');
console.log('🔧 Drupal available?', typeof Drupal !== 'undefined');
console.log('🔧 once available?', typeof once !== 'undefined');

// Check for required globals
if (typeof Drupal === 'undefined') {
  console.error('❌ Drupal is not defined! This file cannot function without Drupal.');
  throw new Error('Drupal global not available');
}

if (typeof once === 'undefined') {
  console.error('❌ once is not defined! This file cannot function without once.');
  throw new Error('once function not available');
}

console.log('✓ Both Drupal and once are available');

// Immediately run the IIFE
((Drupal, once) => {
  'use strict';

  console.log('✓ Code Examples IIFE executing');
  console.log('✓ Defining Drupal.behaviors.ttdCodeExamples');

  Drupal.behaviors.ttdCodeExamples = {
    attach: (context) => {
      console.log('✓ ttdCodeExamples.attach() called with context:', context);

      const blocks = document.querySelectorAll('.ttd-code-block');
      console.log('✓ Found', blocks.length, 'code blocks');

      once('ttd-code-examples', '.ttd-code-block', context).forEach((block, index) => {
        console.log(`✓ Processing block ${index}:`, block);
        console.log(`  📋 Block HTML (first 400 chars):`, block.innerHTML.substring(0, 400));

        const header = block.querySelector('.ttd-code-header');
        const toggle = block.querySelector('.ttd-code-toggle');
        const content = block.querySelector('.ttd-code-content');

        console.log(`  - Header found?`, !!header);
        if (header) {
          console.log(`    📋 Header HTML:`, header.innerHTML.substring(0, 400));
          const allButtons = header.querySelectorAll('button');
          console.log(`    🔘 Total buttons in header:`, allButtons.length);
          allButtons.forEach((btn, i) => {
            console.log(`      Button ${i} class="${btn.className}" text="${btn.textContent.trim().substring(0, 50)}"`);
          });
        }
        console.log(`  - Toggle found?`, !!toggle);
        console.log(`  - Content found?`, !!content);

        if (!header || !toggle || !content) {
          console.error(`❌ Block ${index}: Missing required elements`, { header, toggle, content });
          return;
        }

        // Initialize state
        content.classList.remove('visible');
        toggle.setAttribute('aria-expanded', 'false');
        console.log(`✓ Block ${index}: Initial state set`);

        // Toggle handler for click and keyboard events
        const handleToggle = (e) => {
          // For keyboard events, only trigger on Enter or Space
          if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
            return;
          }

          console.log('🖱️ Toggle clicked/pressed');
          e.preventDefault();
          e.stopPropagation();

          const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
          console.log('  - Currently expanded?', isExpanded);

          if (isExpanded) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<span class="ttd-code-toggle-icon">▶</span> Expand';
            content.classList.remove('visible');
            console.log('✓ Collapsed');
          } else {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.innerHTML = '<span class="ttd-code-toggle-icon">▶</span> Collapse';
            content.classList.add('visible');
            console.log('✓ Expanded');
          }
        };

        // Attach listeners (click and keyboard for accessibility)
        header.addEventListener('click', handleToggle);
        toggle.addEventListener('click', handleToggle);
        toggle.addEventListener('keydown', handleToggle);
        console.log(`✓ Block ${index}: Event listeners attached`);
      });
    }
  };

  console.log('✓ Drupal.behaviors.ttdCodeExamples defined successfully');
})(Drupal, once);

console.log('✓ code-examples.js finished loading');
