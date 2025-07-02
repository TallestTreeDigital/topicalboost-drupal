(function ($, Drupal) {
  Drupal.behaviors.ttdTopicsAdmin = {
    attach: function (context, settings) {
      $('#ttd_topics-run-analysis-wrapper button', context).once('ttd_topics-admin').on('click', function () {
        $(this).prop('disabled', TRUE);
      });
    }
  };
})(jQuery, Drupal);
