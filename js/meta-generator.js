/**
 * @file
 * Meta Generator JavaScript for Drupal.
 *
 * SEO-focused meta title/description generation with keyword selection,
 * option review, and live validation.
 */

(function ($, Drupal, drupalSettings, once) {
    'use strict';

    const MetaGenerator = {
        // Configuration
        config: {
            titleMaxLength: 60,
            titleWarningLength: 65,
            descriptionMaxLength: 160,
            descriptionWarningLength: 165,
            debounceDelay: 500,
            minKeywords: 2
        },

        // State
        state: {
            currentStep: 1,
            selectedKeywords: [],
            generatedVariations: [],
            selectedVariation: null,
            editedTitle: '',
            editedDescription: '',
            isGenerating: false
        },

        // DOM Elements (set during init)
        $container: null,
        $stepContainers: {},
        debounceTimer: null,

        /**
         * Initialize the meta generator
         */
        init: function($container, options) {
            this.$container = $container;
            this.options = $.extend({}, this.config, options || {});

            // Reset state
            this.state = {
                currentStep: 1,
                selectedKeywords: [],
                generatedVariations: [],
                selectedVariation: null,
                editedTitle: '',
                editedDescription: '',
                isGenerating: false
            };

            this.render();
            this.bindEvents();
        },

        /**
         * Render the complete UI
         */
        render: function() {
            const html = `
                <div class="ttd-meta-generator">
                    <div class="ttd-meta-steps">
                        <div class="ttd-meta-step-indicator">
                            <span class="ttd-step-dot active" data-step="1">1</span>
                            <span class="ttd-step-line"></span>
                            <span class="ttd-step-dot" data-step="2">2</span>
                            <span class="ttd-step-line"></span>
                            <span class="ttd-step-dot" data-step="3">3</span>
                        </div>
                    </div>

                    <div class="ttd-meta-step-container" data-step="1">
                        ${this.renderStep1()}
                    </div>

                    <div class="ttd-meta-step-container" data-step="2" style="display: none;">
                        ${this.renderStep2()}
                    </div>

                    <div class="ttd-meta-step-container" data-step="3" style="display: none;">
                        ${this.renderStep3()}
                    </div>
                </div>
            `;

            this.$container.html(html);
            this.cacheElements();
        },

        /**
         * Cache DOM elements for performance
         */
        cacheElements: function() {
            this.$stepContainers = {
                1: this.$container.find('[data-step="1"]'),
                2: this.$container.find('[data-step="2"]'),
                3: this.$container.find('[data-step="3"]')
            };
        },

        /**
         * Render Step 1: Keyword Selection
         */
        renderStep1: function() {
            return `
                <div class="ttd-meta-step ttd-meta-step-keywords">
                    <h4 class="ttd-meta-step-title">${Drupal.t('Select Target Keywords')}</h4>
                    <p class="ttd-meta-help-text">${Drupal.t('Select keywords you want to rank for. Green = easier, Red = more competitive.')}</p>

                    <div class="ttd-meta-keywords-list" id="ttd-meta-keywords-list">
                        <div class="ttd-meta-loading">
                            <span class="ttd-spinner"></span>
                            ${Drupal.t('Loading topics...')}
                        </div>
                    </div>

                    <div class="ttd-meta-step-footer">
                        <span class="ttd-meta-selection-count">${Drupal.t('0 keywords selected (min @min)', {'@min': this.options.minKeywords})}</span>
                        <button type="button" class="button button--primary ttd-meta-generate-btn" disabled>
                            ${Drupal.t('Generate SEO Options')}
                        </button>
                    </div>
                </div>
            `;
        },

        /**
         * Render Step 2: Review Generated Options
         */
        renderStep2: function() {
            return `
                <div class="ttd-meta-step ttd-meta-step-options">
                    <h4 class="ttd-meta-step-title">${Drupal.t('Review Generated Options')}</h4>
                    <p class="ttd-meta-help-text">${Drupal.t('Select your preferred title and description combination.')}</p>

                    <div class="ttd-meta-options-list" id="ttd-meta-options-list">
                        <!-- Options will be rendered here -->
                    </div>

                    <div class="ttd-meta-step-footer">
                        <button type="button" class="button ttd-meta-back-btn" data-step="1">
                            ${Drupal.t('Back')}
                        </button>
                        <button type="button" class="button button--primary ttd-meta-edit-btn" disabled>
                            ${Drupal.t('Edit & Finalize')}
                        </button>
                    </div>
                </div>
            `;
        },

        /**
         * Render Step 3: Inline Edit with Live Validation
         */
        renderStep3: function() {
            return `
                <div class="ttd-meta-step ttd-meta-step-edit">
                    <h4 class="ttd-meta-step-title">${Drupal.t('Edit & Finalize')}</h4>

                    <div class="ttd-meta-edit-fields">
                        <div class="ttd-meta-field-group">
                            <label for="ttd-meta-title">${Drupal.t('Meta Title')}</label>
                            <div class="ttd-meta-input-wrapper">
                                <input type="text" id="ttd-meta-title" class="ttd-meta-input form-text" maxlength="70" />
                                <span class="ttd-meta-char-count" data-field="title">
                                    <span class="count">0</span>/${this.options.titleMaxLength}
                                </span>
                            </div>
                        </div>

                        <div class="ttd-meta-field-group">
                            <label for="ttd-meta-description">${Drupal.t('Meta Description')}</label>
                            <div class="ttd-meta-input-wrapper">
                                <textarea id="ttd-meta-description" class="ttd-meta-input form-textarea" rows="3" maxlength="170"></textarea>
                                <span class="ttd-meta-char-count" data-field="description">
                                    <span class="count">0</span>/${this.options.descriptionMaxLength}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ttd-meta-validation-panel">
                        <h5>${Drupal.t('SEO Analysis')}</h5>
                        <div class="ttd-meta-detected-keywords" id="ttd-meta-detected-keywords">
                            <!-- Detected keywords will be shown here -->
                        </div>
                        <div class="ttd-meta-serp-preview">
                            <h5>${Drupal.t('SERP Preview')}</h5>
                            <div class="ttd-serp-result">
                                <div class="ttd-serp-title" id="ttd-serp-title"></div>
                                <div class="ttd-serp-url" id="ttd-serp-url"></div>
                                <div class="ttd-serp-description" id="ttd-serp-description"></div>
                            </div>
                        </div>
                    </div>

                    <div class="ttd-meta-step-footer">
                        <button type="button" class="button ttd-meta-back-btn" data-step="2">
                            ${Drupal.t('Back')}
                        </button>
                        <button type="button" class="button button--primary ttd-meta-save-btn">
                            ${Drupal.t('Save to Post')}
                        </button>
                    </div>
                </div>
            `;
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Keyword checkbox changes
            this.$container.on('change', '.ttd-meta-keyword-checkbox', function() {
                self.handleKeywordChange();
            });

            // Generate button click
            this.$container.on('click', '.ttd-meta-generate-btn', function() {
                if (!$(this).prop('disabled')) {
                    self.generateMetaOptions();
                }
            });

            // Option selection
            this.$container.on('change', '.ttd-meta-option-radio', function() {
                self.handleOptionSelect($(this).val());
            });

            // Edit button click
            this.$container.on('click', '.ttd-meta-edit-btn', function() {
                if (!$(this).prop('disabled')) {
                    self.goToStep(3);
                }
            });

            // Back button clicks
            this.$container.on('click', '.ttd-meta-back-btn', function() {
                const step = parseInt($(this).data('step'), 10);
                self.goToStep(step);
            });

            // Title/Description input changes (live validation)
            this.$container.on('input', '#ttd-meta-title, #ttd-meta-description', function() {
                self.handleInputChange();
            });

            // Save button click
            this.$container.on('click', '.ttd-meta-save-btn', function() {
                self.saveMetaToPost();
            });
        },

        /**
         * Load topics/keywords from the post
         */
        loadKeywords: function(topics) {
            const $list = this.$container.find('#ttd-meta-keywords-list');

            if (!topics || topics.length === 0) {
                $list.html('<p class="ttd-meta-no-topics">' + Drupal.t('No topics available. Run analysis first to detect topics.') + '</p>');
                return;
            }

            let html = '';
            topics.forEach((topic, index) => {
                const kdBadge = this.getKDBadge(topic.keyword_difficulty);
                const checked = index < this.options.minKeywords ? 'checked' : '';

                html += `
                    <label class="ttd-meta-keyword-item">
                        <input type="checkbox" class="ttd-meta-keyword-checkbox"
                            value="${this.escapeHtml(topic.name)}"
                            data-ttd-id="${topic.ttd_id || ''}"
                            data-kd="${topic.keyword_difficulty || ''}"
                            data-volume="${topic.search_volume || ''}"
                            ${checked} />
                        <span class="ttd-meta-keyword-badge ${kdBadge.class}" title="KD: ${topic.keyword_difficulty || 'N/A'}">${kdBadge.label}</span>
                        <span class="ttd-meta-keyword-name">${this.escapeHtml(topic.name)}</span>
                        ${topic.search_volume ? `<span class="ttd-meta-keyword-volume">${this.formatVolume(topic.search_volume)}</span>` : ''}
                    </label>
                `;
            });

            $list.html(html);
            this.handleKeywordChange();
        },

        /**
         * Get KD badge based on keyword difficulty
         */
        getKDBadge: function(kd) {
            if (kd === null || kd === undefined || kd === '') {
                return { class: 'ttd-kd-unknown', label: '?' };
            }

            const kdNum = parseFloat(kd);
            if (kdNum < 30) {
                return { class: 'ttd-kd-easy', label: Drupal.t('Easy') };
            } else if (kdNum <= 50) {
                return { class: 'ttd-kd-medium', label: Drupal.t('Medium') };
            } else {
                return { class: 'ttd-kd-hard', label: Drupal.t('Hard') };
            }
        },

        /**
         * Format search volume for display
         */
        formatVolume: function(volume) {
            if (!volume) return '';
            const num = parseInt(volume, 10);
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        },

        /**
         * Handle keyword checkbox change
         */
        handleKeywordChange: function() {
            const $checked = this.$container.find('.ttd-meta-keyword-checkbox:checked');
            this.state.selectedKeywords = [];

            $checked.each((i, el) => {
                this.state.selectedKeywords.push({
                    name: $(el).val(),
                    ttd_id: $(el).data('ttd-id'),
                    kd: $(el).data('kd'),
                    volume: $(el).data('volume')
                });
            });

            const count = this.state.selectedKeywords.length;
            const $countText = this.$container.find('.ttd-meta-selection-count');
            const $generateBtn = this.$container.find('.ttd-meta-generate-btn');

            $countText.text(Drupal.t('@count keyword(s) selected (min @min)', {'@count': count, '@min': this.options.minKeywords}));

            if (count >= this.options.minKeywords) {
                $generateBtn.prop('disabled', false);
                $countText.removeClass('ttd-meta-count-warning');
            } else {
                $generateBtn.prop('disabled', true);
                $countText.addClass('ttd-meta-count-warning');
            }
        },

        /**
         * Generate meta options via API
         */
        generateMetaOptions: function() {
            if (this.state.isGenerating) return;

            this.state.isGenerating = true;
            const $btn = this.$container.find('.ttd-meta-generate-btn');
            const originalText = $btn.text();

            $btn.prop('disabled', true).html('<span class="ttd-spinner"></span> ' + Drupal.t('Generating...'));

            const keywords = this.state.selectedKeywords.map(k => k.name);

            // Call the API (platform-specific implementation via callback)
            if (typeof this.options.onGenerate === 'function') {
                this.options.onGenerate(keywords, (response) => {
                    this.state.isGenerating = false;
                    $btn.prop('disabled', false).text(originalText);

                    if (response.success && response.data && response.data.variations) {
                        this.state.generatedVariations = response.data.variations;
                        this.renderOptions();
                        this.goToStep(2);
                    } else {
                        this.showError(response.data?.message || Drupal.t('Failed to generate meta options'));
                    }
                });
            }
        },

        /**
         * Render generated options in Step 2
         */
        renderOptions: function() {
            const $list = this.$container.find('#ttd-meta-options-list');
            let html = '';

            this.state.generatedVariations.forEach((variation, index) => {
                const highlightedTitle = this.highlightKeywords(variation.title);
                const highlightedDesc = this.highlightKeywords(variation.description);

                html += `
                    <label class="ttd-meta-option-item">
                        <input type="radio" name="ttd-meta-option" class="ttd-meta-option-radio" value="${index}" />
                        <div class="ttd-meta-option-content">
                            <div class="ttd-meta-option-title">${highlightedTitle}</div>
                            <div class="ttd-meta-option-description">${highlightedDesc}</div>
                            <div class="ttd-meta-option-meta">
                                <span class="ttd-meta-char-info">${Drupal.t('Title')}: ${variation.title.length}/${this.options.titleMaxLength}</span>
                                <span class="ttd-meta-char-info">${Drupal.t('Desc')}: ${variation.description.length}/${this.options.descriptionMaxLength}</span>
                            </div>
                        </div>
                    </label>
                `;
            });

            $list.html(html);
        },

        /**
         * Highlight keywords in text
         */
        highlightKeywords: function(text) {
            let result = this.escapeHtml(text);

            this.state.selectedKeywords.forEach(keyword => {
                const regex = new RegExp('(' + this.escapeRegex(keyword.name) + ')', 'gi');
                result = result.replace(regex, '<mark class="ttd-keyword-highlight">$1</mark>');
            });

            return result;
        },

        /**
         * Handle option selection
         */
        handleOptionSelect: function(index) {
            this.state.selectedVariation = parseInt(index, 10);
            this.$container.find('.ttd-meta-edit-btn').prop('disabled', false);
        },

        /**
         * Navigate to a specific step
         */
        goToStep: function(step) {
            this.state.currentStep = step;

            // Update step indicators
            this.$container.find('.ttd-step-dot').removeClass('active completed');
            for (let i = 1; i <= 3; i++) {
                const $dot = this.$container.find(`.ttd-step-dot[data-step="${i}"]`);
                if (i < step) {
                    $dot.addClass('completed');
                } else if (i === step) {
                    $dot.addClass('active');
                }
            }

            // Show/hide step containers
            this.$container.find('.ttd-meta-step-container').hide();
            this.$stepContainers[step].show();

            // If going to step 3, populate the edit fields
            if (step === 3 && this.state.selectedVariation !== null) {
                const variation = this.state.generatedVariations[this.state.selectedVariation];
                this.state.editedTitle = variation.title;
                this.state.editedDescription = variation.description;

                this.$container.find('#ttd-meta-title').val(variation.title);
                this.$container.find('#ttd-meta-description').val(variation.description);

                this.updateValidation();
            }
        },

        /**
         * Handle input changes in Step 3
         */
        handleInputChange: function() {
            clearTimeout(this.debounceTimer);

            this.debounceTimer = setTimeout(() => {
                this.state.editedTitle = this.$container.find('#ttd-meta-title').val();
                this.state.editedDescription = this.$container.find('#ttd-meta-description').val();
                this.updateValidation();
            }, this.options.debounceDelay);
        },

        /**
         * Update live validation panel
         */
        updateValidation: function() {
            const title = this.state.editedTitle;
            const description = this.state.editedDescription;

            // Update character counts
            this.updateCharCount('title', title.length);
            this.updateCharCount('description', description.length);

            // Detect keywords
            this.detectKeywords(title, description);

            // Update SERP preview
            this.updateSerpPreview(title, description);
        },

        /**
         * Update character count display
         */
        updateCharCount: function(field, count) {
            const $counter = this.$container.find(`.ttd-meta-char-count[data-field="${field}"]`);
            const $count = $counter.find('.count');
            const maxLength = field === 'title' ? this.options.titleMaxLength : this.options.descriptionMaxLength;
            const warningLength = field === 'title' ? this.options.titleWarningLength : this.options.descriptionWarningLength;

            $count.text(count);

            $counter.removeClass('ttd-count-ok ttd-count-warning ttd-count-danger');
            if (count > warningLength) {
                $counter.addClass('ttd-count-danger');
            } else if (count > maxLength) {
                $counter.addClass('ttd-count-warning');
            } else {
                $counter.addClass('ttd-count-ok');
            }
        },

        /**
         * Detect keywords in title and description
         */
        detectKeywords: function(title, description) {
            const $container = this.$container.find('#ttd-meta-detected-keywords');
            const combined = (title + ' ' + description).toLowerCase();
            let html = '';
            let foundAny = false;

            this.state.selectedKeywords.forEach(keyword => {
                const keywordLower = keyword.name.toLowerCase();
                const matchType = this.getMatchType(combined, keywordLower);

                if (matchType) {
                    foundAny = true;
                    const kdBadge = this.getKDBadge(keyword.kd);
                    html += `
                        <div class="ttd-meta-detected-keyword">
                            <span class="ttd-meta-keyword-badge ${kdBadge.class}">${kdBadge.label}</span>
                            <span class="ttd-meta-keyword-name">${this.escapeHtml(keyword.name)}</span>
                            <span class="ttd-meta-match-type ttd-match-${matchType.type}">${matchType.label}</span>
                        </div>
                    `;
                }
            });

            if (!foundAny) {
                html = '<div class="ttd-meta-keyword-warning">' + Drupal.t('No target keywords detected in your meta content') + '</div>';
            }

            $container.html(html);
        },

        /**
         * Get match type for keyword detection
         */
        getMatchType: function(text, keyword) {
            // Exact match
            if (text.includes(keyword)) {
                return { type: 'exact', label: Drupal.t('Exact') };
            }

            // Plural/singular match
            const plural = keyword + 's';
            const singular = keyword.endsWith('s') ? keyword.slice(0, -1) : null;
            if (text.includes(plural) || (singular && text.includes(singular))) {
                return { type: 'plural', label: Drupal.t('Plural') };
            }

            // Word order match (all words present but different order)
            const words = keyword.split(' ').filter(w => w.length > 2);
            if (words.length > 1) {
                const allPresent = words.every(word => text.includes(word));
                if (allPresent) {
                    return { type: 'word-order', label: Drupal.t('Word Order') };
                }
            }

            // Stemmed match (basic stemming)
            const stemmedWords = words.map(w => w.replace(/(ing|ed|s|ly)$/, ''));
            const stemmedPresent = stemmedWords.every(word => text.includes(word));
            if (stemmedPresent && stemmedWords.some(w => w !== keyword)) {
                return { type: 'stemmed', label: Drupal.t('Stemmed') };
            }

            return null;
        },

        /**
         * Update SERP preview
         */
        updateSerpPreview: function(title, description) {
            const $title = this.$container.find('#ttd-serp-title');
            const $url = this.$container.find('#ttd-serp-url');
            const $desc = this.$container.find('#ttd-serp-description');

            // Truncate title at ~60 chars for SERP display
            let displayTitle = title;
            if (title.length > 60) {
                displayTitle = title.substring(0, 57) + '...';
            }

            // Truncate description at ~160 chars
            let displayDesc = description;
            if (description.length > 160) {
                displayDesc = description.substring(0, 157) + '...';
            }

            $title.text(displayTitle || Drupal.t('Page Title'));
            $desc.text(displayDesc || Drupal.t('Meta description will appear here...'));

            // Set URL from options or fallback
            const url = this.options.postUrl || window.location.origin + '/example-post/';
            $url.text(url);
        },

        /**
         * Save meta to post
         */
        saveMetaToPost: function() {
            const title = this.state.editedTitle;
            const description = this.state.editedDescription;

            if (!title || !description) {
                this.showError(Drupal.t('Please enter both title and description'));
                return;
            }

            const $btn = this.$container.find('.ttd-meta-save-btn');
            const originalText = $btn.text();
            $btn.prop('disabled', true).html('<span class="ttd-spinner"></span> ' + Drupal.t('Saving...'));

            if (typeof this.options.onSave === 'function') {
                this.options.onSave({ title, description }, (response) => {
                    $btn.prop('disabled', false).text(originalText);

                    if (response.success) {
                        this.showSuccess(Drupal.t('Meta saved successfully!'));
                        if (typeof this.options.onSaveComplete === 'function') {
                            this.options.onSaveComplete({ title, description });
                        }
                    } else {
                        this.showError(response.data?.message || Drupal.t('Failed to save meta'));
                    }
                });
            }
        },

        /**
         * Show error message
         */
        showError: function(message) {
            const $error = $('<div class="ttd-meta-message ttd-meta-error"></div>').text(message);
            this.$container.find('.ttd-meta-generator').prepend($error);
            setTimeout(() => $error.fadeOut(() => $error.remove()), 5000);
        },

        /**
         * Show success message
         */
        showSuccess: function(message) {
            const $success = $('<div class="ttd-meta-message ttd-meta-success"></div>').text(message);
            this.$container.find('.ttd-meta-generator').prepend($success);
            setTimeout(() => $success.fadeOut(() => $success.remove()), 3000);
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            if (typeof text !== 'string') {
                text = String(text || '');
            }
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Escape regex special characters
         */
        escapeRegex: function(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
    };

    // Export for global use
    window.TTDMetaGenerator = MetaGenerator;

    /**
     * Drupal behavior for meta generator.
     */
    Drupal.behaviors.ttdMetaGenerator = {
        attach: function (context, settings) {
            const $container = $(once('ttd-meta-generator', '#ttd-meta-generator-container', context));

            if ($container.length === 0) {
                return;
            }

            const config = settings.ttdMetaGenerator || {};
            const nodeId = config.nodeId || $container.data('node-id');
            const postUrl = config.postUrl || window.location.href;
            const apiBase = config.apiBase || '/api/topicalboost/meta';
            const csrfToken = config.nonce || '';

            if (!nodeId) {
                return;
            }

            // Initialize the meta generator
            function initMetaGenerator() {
                MetaGenerator.init($container, {
                    postUrl: postUrl,
                    minKeywords: 2,
                    onGenerate: function(keywords, callback) {
                        $.ajax({
                            url: apiBase + '/generate',
                            type: 'POST',
                            contentType: 'application/json',
                            headers: {
                                'X-CSRF-Token': csrfToken
                            },
                            data: JSON.stringify({
                                node_id: nodeId,
                                keywords: keywords
                            }),
                            success: callback,
                            error: function() {
                                callback({ success: false, data: { message: Drupal.t('Network error') } });
                            }
                        });
                    },
                    onSave: function(meta, callback) {
                        $.ajax({
                            url: apiBase + '/save',
                            type: 'POST',
                            contentType: 'application/json',
                            headers: {
                                'X-CSRF-Token': csrfToken
                            },
                            data: JSON.stringify({
                                node_id: nodeId,
                                meta_title: meta.title,
                                meta_description: meta.description
                            }),
                            success: callback,
                            error: function() {
                                callback({ success: false, data: { message: Drupal.t('Network error') } });
                            }
                        });
                    },
                    onSaveComplete: function(meta) {
                        // Update the preview if it exists
                        $('.ttd-meta-preview-title').text(meta.title);
                        $('.ttd-meta-preview-desc').text(meta.description);
                    }
                });

                // Load keywords
                $.ajax({
                    url: apiBase + '/keywords/' + nodeId,
                    type: 'GET',
                    success: function(response) {
                        if (response.success && response.data && response.data.keywords) {
                            MetaGenerator.loadKeywords(response.data.keywords);
                        }
                    }
                });
            }

            // Track if already initialized
            var initialized = false;

            function tryInit() {
                if (!initialized && $container.is(':visible')) {
                    initialized = true;
                    initMetaGenerator();
                }
            }

            // Check if container is visible now
            tryInit();

            // Also listen for details element toggle (in case it starts collapsed)
            var $details = $container.closest('details');
            if ($details.length) {
                $details.on('toggle', function() {
                    if (this.open) {
                        tryInit();
                    }
                });
            }

            // Handle regenerate button
            $(once('ttd-meta-regenerate', '.ttd-meta-regenerate-btn', context)).on('click', function() {
                $('.ttd-meta-existing').hide();
                $container.show();
                initialized = false; // Allow re-init
                initMetaGenerator();
            });
        }
    };

})(jQuery, Drupal, drupalSettings, once);
