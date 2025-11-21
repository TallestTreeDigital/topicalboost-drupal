/**
 * TopicalBoost Coverage Page JavaScript
 * Handles API data fetching and comparison with local metrics.
 */

// Main metrics object handling comparison.
const siteMetrics = {
    apiData: {},
    localData: {},
    ajaxUrl: '/api/topicalboost/coverage/metrics',
    nonce: '',

    /**
     * Initialize the metrics handler.
     */
    init: function() {
      // Get settings from drupalSettings (Drupal 9+ way).
      if (typeof drupalSettings !== 'undefined' && drupalSettings.ttdCoverage) {
        this.nonce = drupalSettings.ttdCoverage.nonce || '';
        this.ajaxUrl = drupalSettings.ttdCoverage.ajaxUrl || this.ajaxUrl;
      }

      // Fallback: if no nonce, generate a basic one for safety.
      if (!this.nonce) {
        this.nonce = this.generateNonce();
      }

      this.cacheLocalData();
      this.attachEventListeners();
      this.fetchMetrics();
    },

    /**
     * Generate a basic nonce if not provided.
     */
    generateNonce: function() {
      return Math.random().toString(36).substr(2, 9);
    },

    /**
     * Cache local values from DOM.
     */
    cacheLocalData: function() {
      const self = this;
      document.querySelectorAll('.ttd-comparison-table [data-metric]').forEach(row => {
        const metric = row.getAttribute('data-metric');
        const localValElement = row.querySelector('.ttd-local-val');
        if (localValElement) {
          const value = localValElement.getAttribute('data-value');
          self.localData[metric] = parseFloat(value) || 0;
        }
      });
    },

    /**
     * Attach event listeners.
     */
    attachEventListeners: function() {
      const refreshBtn = document.getElementById('ttd-refresh-metrics');
      if (refreshBtn) {
        refreshBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.fetchMetrics(true);
        });
      }
    },

    /**
     * Fetch metrics from API.
     *
     * @param {boolean} forceRefresh
     *   Whether to bypass cache.
     */
    fetchMetrics: function(forceRefresh = false) {
      const refreshBtn = document.getElementById('ttd-refresh-metrics');
      if (refreshBtn) {
        refreshBtn.classList.add('loading');
      }

      const url = new URL(this.ajaxUrl, window.location.origin);
      url.searchParams.set('token', this.nonce);
      if (forceRefresh) {
        url.searchParams.set('force_refresh', '1');
      }

      fetch(url, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
        },
      })
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          this.displayMetrics(data);
          if (refreshBtn) {
            refreshBtn.classList.remove('loading');
          }
        })
        .catch(error => {
          console.error('Error fetching metrics:', error);
          const errorDiv = document.getElementById('ttd-api-error');
          if (errorDiv) {
            errorDiv.textContent = 'Failed to fetch metrics from API: ' + error.message;
            errorDiv.style.display = 'block';
          }
          if (refreshBtn) {
            refreshBtn.classList.remove('loading');
          }
        });
    },

    /**
     * Display metrics and update the page.
     *
     * @param {object} data
     *   API response data.
     */
    displayMetrics: function(data) {
      if (data.error) {
        const errorDiv = document.getElementById('ttd-api-error');
        if (errorDiv) {
          const errorText = 'Error: ' + data.error;
          errorDiv.textContent = errorText;
          errorDiv.innerHTML = errorText;
          errorDiv.style.display = 'block';
        }
        // Set placeholder values for error state.
        this.updateApiValues({
          posts: '-',
          topics: '-',
          relationships: '-',
          avg_relationships_per_post: '-',
        });
        this.updateCacheInfo('Error fetching data', false);
        return;
      }

      // Hide error message if previously shown.
      const errorDiv = document.getElementById('ttd-api-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
      }

      // Store API data.
      this.apiData = data;

      // Update API values in table.
      this.updateApiValues(data);

      // Compare local vs API and update status indicators.
      this.updateTableStatus();

      // Update cache info.
      const isCached = !data.cached_at || (Date.now() - new Date(data.cached_at).getTime()) > 0;
      this.updateCacheInfo(data.cached_at, true);
    },

    /**
     * Update API values in the table.
     *
     * @param {object} data
     *   API response data.
     */
    updateApiValues: function(data) {
      const mapping = {
        'api-posts-val': 'posts',
        'api-topics-val': 'topics',
        'api-relationships-val': 'relationships',
        'api-avg-val': 'avg_relationships_per_post',
      };

      for (const [elementId, dataKey] of Object.entries(mapping)) {
        const element = document.getElementById(elementId);
        if (element) {
          const value = data[dataKey];
          if (value === undefined || value === '-') {
            element.textContent = '-';
          } else {
            // Format avg_relationships_per_post to 2 decimal places
            if (dataKey === 'avg_relationships_per_post') {
              element.textContent = parseFloat(value).toFixed(2);
            } else {
              element.textContent = value;
            }
          }
        }
      }
    },

    /**
     * Update table status indicators by comparing local vs API.
     */
    updateTableStatus: function() {
      const metrics = ['posts', 'topics', 'relationships', 'avg_relationships'];

      metrics.forEach(metric => {
        const row = document.querySelector(`[data-metric="${metric}"]`);
        if (row) {
          this.updateRowStatus(row, metric);
        }
      });
    },

    /**
     * Update individual row status.
     *
     * @param {Element} row
     *   The table row element.
     * @param {string} metric
     *   The metric name.
     */
    updateRowStatus: function(row, metric) {
      const statusCell = row.querySelector('.ttd-status-cell');
      if (!statusCell) {
        return;
      }

      let localValue = this.localData[metric];
      let apiValue = this.apiData[metric];

      // Handle undefined/missing values.
      if (apiValue === undefined || apiValue === null || apiValue === '-') {
        statusCell.innerHTML = '<span>-</span>';
        return;
      }

      // For avg_relationships.
      if (metric === 'avg_relationships') {
        localValue = this.localData.avg_topics_per_post || 0;
        apiValue = this.apiData.avg_relationships_per_post || 0;
      }

      // Compare values.
      const difference = Math.abs(localValue - apiValue);
      const percentDifference = apiValue > 0 ?
        (difference / apiValue) * 100 :
        0;

      // Determine status class and text.
      let statusClass = 'ttd-status-synced';
      let statusText = 'In sync';

      if (percentDifference > 0.1) {
        if (localValue > apiValue) {
          if (percentDifference > 10) {
            statusClass = 'ttd-status-local-much-higher';
            statusText = `Local +${Math.round(percentDifference)}%`;
          } else {
            statusClass = 'ttd-status-local-higher';
            statusText = `Local +${Math.round(percentDifference)}%`;
          }
        } else {
          if (percentDifference > 10) {
            statusClass = 'ttd-status-api-much-higher';
            statusText = `API +${Math.round(percentDifference)}%`;
          } else {
            statusClass = 'ttd-status-api-higher';
            statusText = `API +${Math.round(percentDifference)}%`;
          }
        }
      }

      // Update row class and status text.
      row.className = statusClass;
      statusCell.innerHTML = `<span>${statusText}</span>`;
    },

    /**
     * Update cache info display.
     *
     * @param {string} cachedAt
     *   The timestamp when data was cached.
     * @param {boolean} isFresh
     *   Whether data is fresh from API.
     */
    updateCacheInfo: function(cachedAt, isFresh) {
      const cacheInfoElement = document.getElementById('ttd-cache-info');
      if (!cacheInfoElement) {
        return;
      }

      let cacheText = 'Data cached (10 min refresh)';
      if (isFresh === false) {
        cacheText = 'Unable to fetch data from API';
      } else if (cachedAt) {
        const date = new Date(cachedAt);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

        if (diff < 5) {
          cacheText = 'Fresh from API';
        } else if (diff < 60) {
          cacheText = `Updated ${diff} seconds ago`;
        } else if (diff < 3600) {
          const minutes = Math.floor(diff / 60);
          cacheText = `Updated ${minutes} minute${minutes > 1 ? 's' : ''} ago`;
        } else {
          cacheText = 'Data cached';
        }
      }

      cacheInfoElement.textContent = cacheText;
    },
  };

// Initialize when the page loads
if (typeof Drupal !== 'undefined') {
  Drupal.behaviors.ttdCoverage = {
    attach: function(context) {
      // Check if coverage container exists in the full document
      const container = document.querySelector('.ttd-coverage-section');
      if (container) {
        siteMetrics.init();
      }
    },
  };
}
