(function (Drupal, once) {
    'use strict';

    var groupOrder = {
        'Features': 0,
        'Bug Fixes': 1,
        'Performance': 2,
        'Refactoring': 3,
        'Maintenance': 4,
        'Documentation': 5,
        'Styling': 6,
        'Testing': 7,
    };

    Drupal.behaviors.ttdChangelog = {
        attach: function (context) {
            once('ttd-changelog', '#ttd-changelog-container', context).forEach(function (container) {
                var loaded = false;

                document.querySelectorAll('[data-tab="tab-changelog"]').forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        if (!loaded) {
                            loaded = true;
                            loadChangelog(container);
                        }
                    });
                });

                if (window.location.hash === '#changelog') {
                    loaded = true;
                    loadChangelog(container);
                }
            });
        }
    };

    function loadChangelog(container) {
        fetch('/api/topicalboost/changelog')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            renderChangelog(container, data.releases || []);
        })
        .catch(function () {
            container.innerHTML = '<p>Unable to load changelog.</p>';
        });
    }

    function renderChangelog(container, releases) {
        if (!releases.length) {
            container.innerHTML = '<p>No changelog entries found.</p>';
            return;
        }

        var html = '';
        releases.forEach(function (release) {
            html += '<div class="ttd-changelog-release">';
            html += '<h3>' + Drupal.checkPlain(release.version || 'Unreleased');
            if (release.timestamp) {
                var date = new Date(release.timestamp * 1000);
                html += ' <span class="ttd-changelog-date">' + date.toLocaleDateString() + '</span>';
            }
            html += '</h3>';

            var groups = {};
            (release.commits || []).forEach(function (commit) {
                var group = commit.group || 'Other';
                if (!groups[group]) groups[group] = [];
                groups[group].push(commit);
            });

            var sortedGroups = Object.keys(groups).sort(function (a, b) {
                return (groupOrder[a] !== undefined ? groupOrder[a] : 99) -
                       (groupOrder[b] !== undefined ? groupOrder[b] : 99);
            });

            sortedGroups.forEach(function (group) {
                html += '<h4>' + Drupal.checkPlain(group) + '</h4><ul>';
                groups[group].forEach(function (commit) {
                    var msg = (commit.message || '').split('\n')[0]
                        .replace(/^(feat|fix|perf|refactor|chore|docs|style|test)(\(.*?\))?:\s*/i, '');
                    html += '<li>' + Drupal.checkPlain(msg) + '</li>';
                });
                html += '</ul>';
            });

            html += '</div>';
        });

        container.innerHTML = html;
    }

})(Drupal, once);
