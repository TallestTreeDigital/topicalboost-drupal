/**
 * @file
 * Combined editor tabs for the Drupal post editor.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.ttdCombinedTabs = {
    attach: function (context) {
      $(once('ttd-combined-tabs', '.ttd-combined-tabbed', context)).each(function () {
        const $combined = $(this);

        $combined.on('click', '.ttd-combined-tab', function () {
          const $tab = $(this);
          const tabId = $tab.data('tab');

          $combined.find('.ttd-combined-tab')
            .removeClass('ttd-combined-tab-active')
            .attr('aria-selected', 'false');
          $tab
            .addClass('ttd-combined-tab-active')
            .attr('aria-selected', 'true');

          $combined.find('.ttd-combined-panel')
            .removeClass('ttd-combined-panel-active');
          $combined.find('.ttd-combined-panel[data-panel="' + tabId + '"]')
            .addClass('ttd-combined-panel-active');

          Drupal.attachBehaviors($combined.find('.ttd-combined-panel[data-panel="' + tabId + '"]')[0]);
        });

        $combined.on('click', '.ttd-meta-gen-quick-btn', function (e) {
          e.preventDefault();
          $combined.find('.ttd-combined-tab[data-tab="seo"]').trigger('click');
        });

        $combined.on('click', '.ttd-wide-rejected-toggle', function (e) {
          e.preventDefault();
          const $list = $(this).closest('.ttd-topics-section').find('.ttd-topics-list');
          $list.toggleClass('show-rejected');
          $(this).text($list.hasClass('show-rejected') ? Drupal.t('hide') : $(this).data('count') + ' hidden');
        });

        $combined.find('.ttd-wide-rejected-toggle').each(function () {
          $(this).data('count', parseInt($(this).text(), 10));
        });

        $combined.on('click', '.ttd-add-manual-link', function () {
          const $search = $combined.find('#ttd-topics-search');
          $search.addClass('ttd-search-highlight').trigger('focus');
          setTimeout(function () {
            $search.removeClass('ttd-search-highlight');
          }, 1500);
        });
      });
    }
  };

})(jQuery, Drupal, once);
