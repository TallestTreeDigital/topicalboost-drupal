(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.ttdSchemaImages = {
    attach: function (context) {
      once('ttd-schema-images', '.ttd-schema-images-wrap', context).forEach(function (wrapper) {
        var $wrapper = $(wrapper);
        var nid = $wrapper.data('nid');
        var $status = $wrapper.find('.ttd-schema-status');
        var $formats = $wrapper.find('.ttd-schema-formats');
        var $focalPicker = $wrapper.find('.ttd-focal-picker');
        var focalX = 0.5;
        var focalY = 0.5;

        // Get CSRF token for POST requests.
        var csrfToken = null;
        $.ajax({
          url: Drupal.url('session/token'),
          async: false,
          dataType: 'text',
          success: function (token) { csrfToken = token; }
        });

        function showMessage(text, isError) {
          var cls = isError ? 'messages--error' : 'messages--status';
          var $msg = $('<div class="messages ' + cls + '" role="alert">' + Drupal.checkPlain(text) + '</div>');
          $wrapper.prepend($msg);
          setTimeout(function () { $msg.fadeOut(function () { $msg.remove(); }); }, 5000);
        }

        function loadStatus() {
          $.ajax({
            url: Drupal.url('api/topicalboost/schema-images/status'),
            data: { nid: nid },
            dataType: 'json',
            success: function (data) {
              if (!data.success) return;
              renderStatus(data);
            }
          });
        }

        function renderStatus(data) {
          var images = data.images || {};
          var imageCount = Object.keys(images).length;

          // Update status indicator.
          var statusClass = imageCount === 3 ? 'ready' : (imageCount > 0 ? 'partial' : 'missing');
          var statusText = imageCount === 3 ? 'All formats ready' :
            (imageCount > 0 ? imageCount + '/3 formats ready' : 'No schema images');
          $status.html('<span class="ttd-schema-status-icon ttd-schema-status-' + statusClass + '"></span> ' + statusText);

          // Update format previews.
          var ratios = ['16x9', '4x3', '1x1'];
          var labels = {'16x9': '16:9 (1200x675)', '4x3': '4:3 (900x675)', '1x1': '1:1 (675x675)'};
          var descriptions = {
            '16x9': 'Google Search, Article snippets',
            '4x3': 'Google News, Social cards',
            '1x1': 'Knowledge Panel, square displays'
          };

          $formats.empty();
          ratios.forEach(function (ratio) {
            var img = images[ratio];
            var hasImage = !!img;
            var $format = $('<div class="ttd-schema-format"></div>');

            if (hasImage) {
              $format.append('<div class="ttd-schema-preview">' +
                '<img src="' + img.url + '" alt="Schema ' + ratio + '">' +
                '<span class="ttd-schema-checkmark">&#10003;</span>' +
                '</div>');
            } else {
              $format.append('<div class="ttd-schema-preview ttd-schema-preview--empty">' +
                '<span class="ttd-schema-placeholder">No image</span>' +
                '</div>');
            }

            $format.append('<div class="ttd-schema-format-info">' +
              '<strong>' + labels[ratio] + '</strong>' +
              '<small>' + descriptions[ratio] + '</small>' +
              '</div>');
            $formats.append($format);
          });

          // Update focal point if available.
          if (data.focal_point) {
            focalX = data.focal_point.x;
            focalY = data.focal_point.y;
            updateFocalMarker();
          }

          // Source image info.
          var $source = $wrapper.find('.ttd-schema-source-info');
          if (data.source) {
            var cls = data.source.suitable ? 'suitable' : 'unsuitable';
            $source.html('<span class="ttd-schema-source-' + cls + '">' + Drupal.checkPlain(data.source.message) + '</span>');
          }
        }

        function updateFocalMarker() {
          var $marker = $focalPicker.find('.ttd-focal-marker');
          if ($marker.length) {
            $marker.css({ left: (focalX * 100) + '%', top: (focalY * 100) + '%' });
          }
          $wrapper.find('.ttd-focal-coords').text(
            'x: ' + focalX.toFixed(2) + ', y: ' + focalY.toFixed(2)
          );
        }

        // Generate images button.
        $wrapper.find('.ttd-schema-generate-btn').on('click', function () {
          var $btn = $(this);
          $btn.prop('disabled', true).text('Generating...');

          $.ajax({
            url: Drupal.url('api/topicalboost/schema-images/generate'),
            method: 'POST',
            data: {
              nid: nid,
              focal_x: focalX,
              focal_y: focalY
            },
            headers: { 'X-CSRF-Token': csrfToken },
            dataType: 'json',
            success: function (data) {
              $btn.prop('disabled', false).text('Generate Schema Images');
              if (data.success) {
                loadStatus();
              } else {
                showMessage(data.message || 'Generation failed', true);
              }
            },
            error: function () {
              $btn.prop('disabled', false).text('Generate Schema Images');
              showMessage('Request failed', true);
            }
          });
        });

        // Clear images button.
        $wrapper.find('.ttd-schema-clear-btn').on('click', function () {
          if (!confirm('Clear all schema images for this content?')) return;

          $.ajax({
            url: Drupal.url('api/topicalboost/schema-images/clear'),
            method: 'POST',
            data: { nid: nid },
            headers: { 'X-CSRF-Token': csrfToken },
            dataType: 'json',
            success: function () {
              loadStatus();
            }
          });
        });

        // Upload button - uses generate endpoint with a file ID.
        $wrapper.find('.ttd-schema-upload-btn').on('click', function () {
          var $input = $wrapper.find('.ttd-schema-file-input');
          $input.trigger('click');
        });

        $wrapper.find('.ttd-schema-file-input').on('change', function () {
          var files = this.files;
          if (!files.length) return;

          // Upload via Drupal's file system first, then generate from the uploaded file.
          var formData = new FormData();
          formData.append('files[schema_image]', files[0]);

          var $btn = $wrapper.find('.ttd-schema-upload-btn');
          $btn.prop('disabled', true).text('Uploading...');

          // Upload the file, then call generate with the resulting fid.
          $.ajax({
            url: Drupal.url('file/upload/node/' + nid + '/field_ttd_schema_16x9'),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-Token': csrfToken },
            dataType: 'json',
            success: function (data) {
              var fid = data && data.fid ? data.fid[0].value : null;
              if (fid) {
                // Now generate schema images from this uploaded file.
                $.ajax({
                  url: Drupal.url('api/topicalboost/schema-images/generate'),
                  method: 'POST',
                  data: { nid: nid, fid: fid, focal_x: focalX, focal_y: focalY },
                  headers: { 'X-CSRF-Token': csrfToken },
                  dataType: 'json',
                  success: function (genData) {
                    $btn.prop('disabled', false).text('Upload Custom Image');
                    if (genData.success) {
                      loadStatus();
                    } else {
                      showMessage(genData.message || 'Generation failed', true);
                    }
                  },
                  error: function () {
                    $btn.prop('disabled', false).text('Upload Custom Image');
                    showMessage('Generation failed after upload', true);
                  }
                });
              } else {
                $btn.prop('disabled', false).text('Upload Custom Image');
                showMessage('Upload failed', true);
              }
            },
            error: function () {
              $btn.prop('disabled', false).text('Upload Custom Image');
              showMessage('Upload failed', true);
            }
          });
        });

        // Focal point picker.
        $focalPicker.on('click', function (e) {
          var offset = $(this).offset();
          var w = $(this).width();
          var h = $(this).height();
          focalX = Math.max(0, Math.min(1, (e.pageX - offset.left) / w));
          focalY = Math.max(0, Math.min(1, (e.pageY - offset.top) / h));
          updateFocalMarker();
        });

        // Lightbox for image previews.
        $formats.on('click', '.ttd-schema-preview img', function () {
          var src = $(this).attr('src');
          var $lightbox = $('<div class="ttd-schema-lightbox">' +
            '<div class="ttd-schema-lightbox-close">&times;</div>' +
            '<img src="' + src + '">' +
            '</div>');
          $('body').append($lightbox);
          $lightbox.on('click', function () { $lightbox.remove(); });
        });

        // Load initial status.
        loadStatus();
      });
    }
  };
})(jQuery, Drupal, once);
