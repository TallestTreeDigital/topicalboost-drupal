/**
 * @file
 * Meta Generator JavaScript for Drupal.
 *
 * SEO-focused meta title/description generation with 2-column layout.
 * Keywords on top, Titles | Descriptions side by side below.
 */

(function ($, Drupal, drupalSettings, once) {
    'use strict';

    // Debounce utility
    function debounce(fn, delay) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    const MetaGenerator = {
        // Configuration
        config: {
            titleMaxLength: 60,
            titleWarningLength: 55,
            descriptionMaxLength: 160,
            descriptionWarningLength: 150,
            debounceDelay: 150,
            minKeywords: 1,
            numOptions: 5
        },

        // State
        state: {
            selectedKeywords: [],
            titleOptions: [],
            descriptionOptions: [],
            selectedTitleIndex: null,
            selectedDescIndex: null,
            isGenerating: false,
            hasGenerated: false
        },

        // DOM Elements
        $container: null,

        /**
         * Initialize the meta generator
         */
        init: function($container, options) {
            this.$container = $container;
            this.options = $.extend({}, this.config, options || {});

            // Reset state
            this.state = {
                selectedKeywords: [],
                titleOptions: [],
                descriptionOptions: [],
                selectedTitleIndex: null,
                selectedDescIndex: null,
                isGenerating: false,
                hasGenerated: false
            };

            this.render();
            this.bindEvents();

            // If existing meta is passed, show it in the UI
            if (this.options.existingMeta && this.options.existingMeta.title && this.options.existingMeta.description) {
                this.loadExistingMeta(this.options.existingMeta);
                return;
            }

            // Check for saved draft in localStorage
            if (this.restoreFromLocalStorage()) {
                this.renderRestoredState();
            }
        },

        /**
         * Load existing saved meta into the UI
         */
        loadExistingMeta: function(existingMeta) {
            // Hide the PHP-rendered preview at top - we'll show SERP preview below columns
            this.$container.siblings('.ttd-meta-existing').hide();

            // Set up state with existing values as the single option
            this.state.titleOptions = [existingMeta.title];
            this.state.descriptionOptions = [existingMeta.description];
            this.state.selectedTitleIndex = 0;
            this.state.selectedDescIndex = 0;
            this.state.hasGenerated = true;

            // Render the options
            this.renderTitleOptions();
            this.renderDescriptionOptions();

            // Pre-select the options
            this.$container.find('.ttd-title-radio[value="0"]').prop('checked', true);
            this.$container.find('.ttd-desc-radio[value="0"]').prop('checked', true);

            // Update Generate button to Regenerate
            this.$container.find('.ttd-meta-generate-btn')
                .removeClass('button button--primary')
                .addClass('button-link')
                .text(Drupal.t('Regenerate'));

            // Show the SERP preview below columns with saved state
            this.renderInlinePreview(existingMeta.title, existingMeta.description, true);
        },

        /**
         * Render restored state from localStorage
         */
        renderRestoredState: function() {
            // Render the options
            this.renderTitleOptions();
            this.renderDescriptionOptions();

            // Select the saved selections
            if (this.state.selectedTitleIndex !== null) {
                this.$container.find('.ttd-title-radio[value="' + this.state.selectedTitleIndex + '"]').prop('checked', true);
            }
            if (this.state.selectedDescIndex !== null) {
                this.$container.find('.ttd-desc-radio[value="' + this.state.selectedDescIndex + '"]').prop('checked', true);
            }

            // Update save button state
            this.updateSaveButton();

            // Update Generate button to say Regenerate (as link)
            this.$container.find('.ttd-meta-generate-btn')
                .removeClass('button button--primary')
                .addClass('button-link')
                .text(Drupal.t('Regenerate'));
        },

        /**
         * Render the 2-column layout with keywords on top
         */
        render: function() {
            const html = `
                <div class="ttd-meta-generator">
                    <!-- Keywords Row -->
                    <div class="ttd-meta-keywords-row">
                        <span class="ttd-meta-keywords-label">${Drupal.t('Focus topic:')}</span>
                        <div class="ttd-meta-keywords-chips" id="ttd-keywords-list">
                            <span class="ttd-meta-loading-inline">${Drupal.t('Loading...')}</span>
                        </div>
                        <div class="ttd-meta-keywords-actions">
                            <button type="button" class="button button--primary ttd-meta-generate-btn" disabled>
                                ${Drupal.t('Generate')}
                            </button>
                        </div>
                    </div>

                    <!-- Two Column Layout -->
                    <div class="ttd-meta-two-columns">
                        <!-- Titles Column -->
                        <div class="ttd-meta-column ttd-meta-column-titles">
                            <div class="ttd-meta-column-header">${Drupal.t('Title')}</div>
                            <div class="ttd-meta-column-content" id="ttd-titles-list">
                                <div class="ttd-meta-column-empty">
                                    ${Drupal.t('Select keyword and click Generate')}
                                </div>
                            </div>
                        </div>

                        <!-- Descriptions Column -->
                        <div class="ttd-meta-column ttd-meta-column-descriptions">
                            <div class="ttd-meta-column-header">${Drupal.t('Description')}</div>
                            <div class="ttd-meta-column-content" id="ttd-descriptions-list">
                                <div class="ttd-meta-column-empty">
                                    ${Drupal.t('Select keyword and click Generate')}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inline Preview (shown after auto-save) -->
                    <div class="ttd-meta-inline-preview" id="ttd-inline-preview" style="display: none;">
                    </div>

                </div>
            `;

            this.$container.html(html);
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Keyword radio changes (single selection)
            this.$container.on('change', '.ttd-meta-keyword-radio', function() {
                self.handleKeywordChange();
            });

            // Generate button click
            this.$container.on('click', '.ttd-meta-generate-btn', function() {
                if (!$(this).prop('disabled')) {
                    self.generateMetaOptions();
                }
            });

            // Title option selection
            this.$container.on('change', '.ttd-title-radio', function() {
                self.state.selectedTitleIndex = parseInt($(this).val(), 10);
                self.checkAutoSave();
            });

            // Description option selection
            this.$container.on('change', '.ttd-desc-radio', function() {
                self.state.selectedDescIndex = parseInt($(this).val(), 10);
                self.checkAutoSave();
            });

            // Debounced auto-save check
            const debouncedAutoSave = debounce(function() {
                self.checkAutoSave();
            }, 500);

            // Title input changes - update stored value and char count
            this.$container.on('input', '.ttd-title-input', function() {
                const index = parseInt($(this).data('index'), 10);
                const value = $(this).val();
                self.state.titleOptions[index] = value;
                self.updateCharCount($(this).closest('.ttd-meta-option-editable').find('.ttd-meta-option-char-count'), value.length, self.options.titleMaxLength, self.options.titleWarningLength);
                // Auto-resize textarea
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
                // Re-check auto-save if editing selected option (debounced)
                if (index === self.state.selectedTitleIndex) {
                    debouncedAutoSave();
                }
            });

            // Description input changes - update stored value and char count
            this.$container.on('input', '.ttd-desc-input', function() {
                const index = parseInt($(this).data('index'), 10);
                const value = $(this).val();
                self.state.descriptionOptions[index] = value;
                self.updateCharCount($(this).closest('.ttd-meta-option-editable').find('.ttd-meta-option-char-count'), value.length, self.options.descriptionMaxLength, self.options.descriptionWarningLength);
                // Auto-resize textarea
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
                // Re-check auto-save if editing selected option (debounced)
                if (index === self.state.selectedDescIndex) {
                    debouncedAutoSave();
                }
            });
        },

        /**
         * Load topics/keywords from the post
         */
        loadKeywords: function(topics) {
            const $list = this.$container.find('#ttd-keywords-list');
            const $columns = this.$container.find('.ttd-meta-two-columns');
            const $keywordsRow = this.$container.find('.ttd-meta-keywords-row');

            if (!topics || topics.length === 0) {
                $list.html('<span class="ttd-meta-no-keywords">' + Drupal.t('No Main/About topics. Add manually or drag from mentions.') + '</span>');
                $columns.hide();
                $keywordsRow.addClass('ttd-meta-keywords-standalone');
                return;
            }

            $columns.show();
            $keywordsRow.removeClass('ttd-meta-keywords-standalone');

            // Separate mainEntity from about topics
            const mainEntity = topics.filter(t => t.tier === 'mainEntity');
            const aboutTopics = topics.filter(t => t.tier === 'about');
            const allTopics = [...mainEntity, ...aboutTopics];
            const displayTopics = allTopics.length > 0 ? allTopics : topics;

            // Find best keyword to pre-select (lowest KD, or main entity)
            let bestIndex = 0;
            if (mainEntity.length > 0) {
                bestIndex = 0; // Main entity is first
            } else {
                // Find lowest KD among about topics
                let lowestKD = Infinity;
                displayTopics.forEach((topic, index) => {
                    const kd = parseFloat(topic.keyword_difficulty) || 100;
                    if (kd < lowestKD) {
                        lowestKD = kd;
                        bestIndex = index;
                    }
                });
            }

            let html = '';

            displayTopics.forEach((topic, index) => {
                const isMainEntity = topic.tier === 'mainEntity';
                const preChecked = (index === bestIndex);

                html += this.renderKeywordChip(topic, preChecked, isMainEntity);
            });

            $list.html(html);
            this.handleKeywordChange();
        },

        /**
         * Render a single keyword chip (radio button for single selection)
         */
        renderKeywordChip: function(topic, preChecked, isMainEntity) {
            const kdInfo = this.getKDBadge(topic.keyword_difficulty);
            // Only use traffic_potential (no fallback to search_volume)
            const volume = topic.traffic_potential || 0;
            const volumeStr = volume ? this.formatVolume(volume) : '';
            const kdNum = topic.keyword_difficulty !== null && topic.keyword_difficulty !== undefined ? Math.round(topic.keyword_difficulty) : null;

            // Difficulty dot tooltip - only shows difficulty (volume is already visible in chip)
            const kdTooltip = kdNum !== null
                ? Drupal.t('Difficulty: @kd/100 (@label)', {'@kd': kdNum, '@label': kdInfo.label})
                : Drupal.t('No difficulty data');

            const chipClasses = ['ttd-meta-keyword-chip'];
            if (preChecked) chipClasses.push('is-checked');
            if (isMainEntity) chipClasses.push('is-main-entity');

            return `
                <label class="${chipClasses.join(' ')}">
                    <input type="radio" name="ttd-target-keyword" class="ttd-meta-keyword-radio"
                        value="${this.escapeAttr(topic.name)}"
                        data-kd="${topic.keyword_difficulty || ''}"
                        data-volume="${volume || ''}"
                        ${preChecked ? 'checked' : ''} />
                    <span class="ttd-chip-name">${this.escapeHtml(topic.name)}</span>
                    ${volumeStr ? '<span class="ttd-chip-volume" title="' + Drupal.t('Traffic Potential') + '">' + volumeStr + '</span>' : ''}
                    <span class="ttd-chip-kd ${kdInfo.class}" title="${kdTooltip}"></span>
                </label>
            `;
        },

        /**
         * Get KD badge based on keyword difficulty
         */
        getKDBadge: function(kd) {
            if (kd === null || kd === undefined || kd === '') {
                return { class: 'ttd-kd-unknown', label: '?' };
            }
            const kdNum = parseFloat(kd);
            if (kdNum <= 30) {
                return { class: 'ttd-kd-easy', label: Drupal.t('Easy') };
            } else if (kdNum <= 60) {
                return { class: 'ttd-kd-medium', label: Drupal.t('Med.') };
            } else if (kdNum <= 80) {
                return { class: 'ttd-kd-hard', label: Drupal.t('Hard') };
            } else {
                return { class: 'ttd-kd-very-hard', label: Drupal.t('Very Hard') };
            }
        },

        /**
         * Format search volume for display
         */
        formatVolume: function(volume) {
            if (!volume) return '';
            const num = parseInt(volume, 10);
            if (num >= 1000000) {
                const val = num / 1000000;
                return (val % 1 === 0 ? val.toFixed(0) : val.toFixed(1)) + 'M/mo';
            } else if (num >= 1000) {
                const val = num / 1000;
                return (val % 1 === 0 ? val.toFixed(0) : val.toFixed(1)) + 'K/mo';
            }
            return num + '/mo';
        },

        /**
         * Handle keyword radio change (single selection)
         */
        handleKeywordChange: function() {
            const $radios = this.$container.find('.ttd-meta-keyword-radio');
            const $chips = this.$container.find('.ttd-meta-keyword-chip');
            this.state.selectedKeywords = [];

            // Remove checked class from all chips
            $chips.removeClass('is-checked');

            // Find the selected radio
            const $selected = $radios.filter(':checked');
            if ($selected.length) {
                $selected.closest('.ttd-meta-keyword-chip').addClass('is-checked');
                this.state.selectedKeywords.push({
                    name: $selected.val(),
                    kd: $selected.data('kd'),
                    volume: $selected.data('volume')
                });
            }

            const $generateBtn = this.$container.find('.ttd-meta-generate-btn');
            $generateBtn.prop('disabled', this.state.selectedKeywords.length === 0);
        },

        /**
         * Generate meta options via API
         */
        generateMetaOptions: function() {
            if (this.state.isGenerating) return;

            this.state.isGenerating = true;
            const $btn = this.$container.find('.ttd-meta-generate-btn');
            const $titlesCol = this.$container.find('#ttd-titles-list');
            const $descsCol = this.$container.find('#ttd-descriptions-list');

            $btn.prop('disabled', true).text(Drupal.t('Generating...'));

            // Show inline loading state in each column
            $titlesCol.html('<div class="ttd-meta-column-loading"><span class="ttd-spinner"></span></div>');
            $descsCol.html('<div class="ttd-meta-column-loading"><span class="ttd-spinner"></span></div>');

            const keywords = this.state.selectedKeywords.map(k => k.name);

            if (typeof this.options.onGenerate === 'function') {
                this.options.onGenerate(keywords, (response) => {
                    this.state.isGenerating = false;

                    if (response.success && response.data && response.data.variations) {
                        this.state.hasGenerated = true;
                        $btn.prop('disabled', false)
                            .removeClass('button button--primary')
                            .addClass('button-link')
                            .text(Drupal.t('Regenerate'));

                        // Unescape any backslash-escaped quotes from API response
                        this.state.titleOptions = response.data.variations.map(v => this.unescapeText(v.title));
                        this.state.descriptionOptions = response.data.variations.map(v => this.unescapeText(v.description));

                        this.renderTitleOptions();
                        this.renderDescriptionOptions();

                        // Save generated options to localStorage immediately
                        this.saveToLocalStorage();

                        // No auto-selection - user must actively choose
                        this.updateSaveButton();
                    } else {
                        $btn.prop('disabled', false).text(Drupal.t('Generate'));
                        this.showError(response.data?.message || Drupal.t('Failed to generate meta options'));
                        $titlesCol.html('<div class="ttd-meta-column-empty">' + Drupal.t('Generation failed. Try again.') + '</div>');
                        $descsCol.html('<div class="ttd-meta-column-empty">' + Drupal.t('Generation failed. Try again.') + '</div>');
                    }
                });
            }
        },

        /**
         * Render title options with editable textareas
         */
        renderTitleOptions: function() {
            const $list = this.$container.find('#ttd-titles-list');
            let html = '';

            this.state.titleOptions.forEach((title, index) => {
                const charCount = title.length;
                const charClass = this.getCharCountClass(charCount, this.options.titleMaxLength, this.options.titleWarningLength);

                html += `
                    <div class="ttd-meta-option-editable">
                        <input type="radio" name="ttd-title-select" class="ttd-title-radio" value="${index}" />
                        <div class="ttd-meta-option-input-wrapper">
                            <textarea class="ttd-meta-option-input ttd-title-input" data-index="${index}">${this.escapeHtml(title)}</textarea>
                            <span class="ttd-meta-option-char-count ${charClass}">${charCount}/${this.options.titleMaxLength}</span>
                        </div>
                    </div>
                `;
            });

            $list.html(html);
        },

        /**
         * Render description options with editable textareas
         */
        renderDescriptionOptions: function() {
            const $list = this.$container.find('#ttd-descriptions-list');
            let html = '';

            this.state.descriptionOptions.forEach((desc, index) => {
                const charCount = desc.length;
                const charClass = this.getCharCountClass(charCount, this.options.descriptionMaxLength, this.options.descriptionWarningLength);

                html += `
                    <div class="ttd-meta-option-editable">
                        <input type="radio" name="ttd-desc-select" class="ttd-desc-radio" value="${index}" />
                        <div class="ttd-meta-option-input-wrapper">
                            <textarea class="ttd-meta-option-input ttd-desc-input" data-index="${index}">${this.escapeHtml(desc)}</textarea>
                            <span class="ttd-meta-option-char-count ${charClass}">${charCount}/${this.options.descriptionMaxLength}</span>
                        </div>
                    </div>
                `;
            });

            $list.html(html);
        },

        /**
         * Get character count CSS class
         */
        getCharCountClass: function(count, max, warning) {
            if (count > max) return 'ttd-char-danger';
            if (count > warning) return 'ttd-char-warning';
            return '';
        },

        /**
         * Count how many selected keywords appear in text
         */
        countKeywordsInText: function(text) {
            if (!text || this.state.selectedKeywords.length === 0) return 0;

            let count = 0;
            const lowerText = text.toLowerCase();

            this.state.selectedKeywords.forEach(keyword => {
                if (lowerText.includes(keyword.name.toLowerCase())) {
                    count++;
                }
            });

            return count;
        },

        /**
         * Update character count display
         */
        updateCharCount: function($el, count, max, warning) {
            $el.text(count + '/' + max)
               .removeClass('ttd-char-warning ttd-char-danger')
               .addClass(this.getCharCountClass(count, max, warning));
        },

        /**
         * Update Save button state
         */
        updateSaveButton: function() {
            const canSave = this.state.selectedTitleIndex !== null &&
                           this.state.selectedDescIndex !== null &&
                           this.state.titleOptions.length > 0 &&
                           this.state.descriptionOptions.length > 0;

            const $saveBtn = this.$container.find('.ttd-meta-save-btn');
            $saveBtn.prop('disabled', !canSave);
        },

        /**
         * Check if we can auto-save (both selections made) and do so
         */
        checkAutoSave: function() {
            // Both must be selected
            if (this.state.selectedTitleIndex === null || this.state.selectedDescIndex === null) {
                // Hide preview if only one is selected
                this.$container.find('#ttd-inline-preview').hide();
                return;
            }

            const title = this.state.titleOptions[this.state.selectedTitleIndex];
            const description = this.state.descriptionOptions[this.state.selectedDescIndex];

            if (!title || !description) return;

            // Check character limits - don't auto-save if over
            const titleOver = title.length > this.options.titleMaxLength;
            const descOver = description.length > this.options.descriptionMaxLength;

            if (titleOver || descOver) {
                // Show warning preview but don't save
                this.renderInlinePreview(title, description, false);
                return;
            }

            // Auto-save
            this.autoSaveMetaToPost(title, description);
        },

        /**
         * Auto-save meta to post (silent save without replacing UI)
         */
        autoSaveMetaToPost: function(title, description) {
            const self = this;

            if (typeof this.options.onSave === 'function') {
                this.options.onSave({ title, description }, (response) => {
                    if (response.success) {
                        // Clear localStorage draft
                        self.clearLocalStorage();

                        // Update the form's changed timestamp to prevent "modified by another user" errors
                        // The node was saved programmatically, so we need to sync the form's timestamp
                        if (response.data && response.data.changed) {
                            const $changedField = $('input[name="changed"]');
                            if ($changedField.length) {
                                $changedField.val(response.data.changed);
                            }
                        }

                        // Show inline preview with saved state
                        self.renderInlinePreview(title, description, true);

                        if (typeof self.options.onSaveComplete === 'function') {
                            self.options.onSaveComplete({ title, description });
                        }
                    } else {
                        // Show preview with error state
                        self.renderInlinePreview(title, description, false, response.data?.message || Drupal.t('Save failed'));
                    }
                });
            }
        },

        /**
         * Render inline preview below the columns
         */
        renderInlinePreview: function(title, description, isSaved, errorMsg) {
            const $preview = this.$container.find('#ttd-inline-preview');
            // Look for visible PHP-rendered preview as sibling
            const $existingPreview = this.$container.siblings('.ttd-meta-existing').filter(':visible');

            const titleLen = title.length;
            const descLen = description.length;
            const titleOver = titleLen > this.options.titleMaxLength;
            const descOver = descLen > this.options.descriptionMaxLength;

            // Show status indicator in keywords row (higher visibility)
            this.showStatusIndicator(isSaved, errorMsg, titleOver, descOver, titleLen, descLen);

            // If there's a visible "Current Generated Meta" section, update that instead of showing SERP preview
            if ($existingPreview.length) {
                $existingPreview.find('.ttd-meta-preview-title').text(title);
                $existingPreview.find('.ttd-meta-preview-desc').text(description);
                // Hide the inline SERP preview since we have the existing preview
                $preview.hide();
                return;
            }

            // Highlight keywords in title and description
            const highlightedTitle = this.highlightKeywords(title);
            const highlightedDesc = this.highlightKeywords(description);

            // Get site info for realistic preview
            const siteUrl = this.options.postUrl || window.location.hostname;
            const siteName = siteUrl.replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0];
            const breadcrumb = siteName + ' › ' + (this.options.postSlug || 'article');

            const html = `
                <div class="ttd-preview-serp">
                    <div class="ttd-preview-serp-header">
                        <span class="ttd-preview-serp-favicon"></span>
                        <div class="ttd-preview-serp-site">
                            <span class="ttd-preview-serp-sitename">${this.escapeHtml(siteName)}</span>
                            <span class="ttd-preview-serp-breadcrumb">${this.escapeHtml(breadcrumb)}</span>
                        </div>
                    </div>
                    <div class="ttd-preview-serp-title">${highlightedTitle}</div>
                    <div class="ttd-preview-serp-desc">${highlightedDesc}</div>
                </div>
            `;

            $preview.html(html).show();
        },

        /**
         * Show status indicator in keywords row
         */
        showStatusIndicator: function(isSaved, errorMsg, titleOver, descOver, titleLen, descLen) {
            let $indicator = this.$container.find('.ttd-status-indicator');

            if (!$indicator.length) {
                $indicator = $('<span class="ttd-status-indicator"></span>');
                this.$container.find('.ttd-meta-keywords-actions').prepend($indicator);
            }

            // Determine status
            if (errorMsg) {
                $indicator.attr('class', 'ttd-status-indicator ttd-status-error').text(errorMsg);
            } else if (titleOver || descOver) {
                let overMsg = [];
                if (titleOver) overMsg.push(Drupal.t('Title @over over', {'@over': titleLen - this.options.titleMaxLength}));
                if (descOver) overMsg.push(Drupal.t('Desc @over over', {'@over': descLen - this.options.descriptionMaxLength}));
                $indicator.attr('class', 'ttd-status-indicator ttd-status-warning').text(overMsg.join(', '));
            } else if (isSaved) {
                $indicator.attr('class', 'ttd-status-indicator ttd-status-saved').text(Drupal.t('Saved'));
            }

            // Fade out after delay for saved state
            if (isSaved && !titleOver && !descOver) {
                $indicator.stop(true).css('opacity', 1);
                setTimeout(function() {
                    $indicator.animate({ opacity: 0.6 }, 1500);
                }, 2000);
            }
        },

        /**
         * Highlight keywords in text
         */
        highlightKeywords: function(text) {
            if (!text || this.state.selectedKeywords.length === 0) {
                return this.escapeHtml(text);
            }

            let html = this.escapeHtml(text);

            // Sort by length (longest first) to avoid partial matches
            const sorted = this.state.selectedKeywords.slice().sort((a, b) => b.name.length - a.name.length);

            sorted.forEach(kw => {
                const escaped = this.escapeHtml(kw.name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const regex = new RegExp('\\b' + escaped + '\\b', 'gi');
                const kd = parseFloat(kw.kd) || 50;
                let diffClass = 'ttd-kd-medium';
                if (kd <= 30) diffClass = 'ttd-kd-easy';
                else if (kd <= 60) diffClass = 'ttd-kd-medium';
                else if (kd <= 80) diffClass = 'ttd-kd-hard';
                else diffClass = 'ttd-kd-very-hard';

                html = html.replace(regex, function(match) {
                    return '<span class="ttd-keyword-highlight-span ' + diffClass + '">' + match + '</span>';
                });
            });

            return html;
        },

        /**
         * Save current state to localStorage
         */
        saveToLocalStorage: function() {
            if (!this.options.nodeId || !window.localStorage) return;

            const data = {
                titleOptions: this.state.titleOptions,
                descriptionOptions: this.state.descriptionOptions,
                selectedTitleIndex: this.state.selectedTitleIndex,
                selectedDescIndex: this.state.selectedDescIndex,
                selectedKeywords: this.state.selectedKeywords,
                timestamp: Date.now()
            };

            try {
                localStorage.setItem('ttd_meta_draft_' + this.options.nodeId, JSON.stringify(data));
                // Show brief save indicator
                this.showAutoSaveIndicator();
            } catch (e) {
                // localStorage might be full or disabled
            }
        },

        /**
         * Show brief auto-save indicator
         */
        showAutoSaveIndicator: function() {
            let $indicator = this.$container.find('.ttd-autosave-indicator');

            if (!$indicator.length) {
                $indicator = $('<span class="ttd-autosave-indicator"></span>');
                this.$container.find('.ttd-meta-keywords-actions').prepend($indicator);
            }

            // Show text and fade out
            $indicator.text(Drupal.t('Draft saved')).stop(true).css('opacity', 1);
            setTimeout(function() {
                $indicator.animate({ opacity: 0 }, 400, function() {
                    $(this).text('');
                });
            }, 1500);
        },

        /**
         * Restore state from localStorage if available
         */
        restoreFromLocalStorage: function() {
            if (!this.options.nodeId || !window.localStorage) return false;

            try {
                const saved = localStorage.getItem('ttd_meta_draft_' + this.options.nodeId);
                if (!saved) return false;

                const data = JSON.parse(saved);

                // Check if data is less than 7 days old
                if (data.timestamp && (Date.now() - data.timestamp) > 7 * 24 * 60 * 60 * 1000) {
                    this.clearLocalStorage();
                    return false;
                }

                // Restore state - but don't restore selections, user must re-select
                if (data.titleOptions && data.titleOptions.length > 0) {
                    this.state.titleOptions = data.titleOptions;
                    this.state.descriptionOptions = data.descriptionOptions || [];
                    this.state.selectedTitleIndex = null;
                    this.state.selectedDescIndex = null;
                    this.state.selectedKeywords = data.selectedKeywords || [];
                    this.state.hasGenerated = true;
                    return true;
                }
            } catch (e) {
                // Invalid JSON or other error
            }

            return false;
        },

        /**
         * Clear localStorage for this node
         */
        clearLocalStorage: function() {
            if (!this.options.nodeId || !window.localStorage) return;
            try {
                localStorage.removeItem('ttd_meta_draft_' + this.options.nodeId);
            } catch (e) {}
        },

        /**
         * Update metrics for a specific topic chip (called when sidebar fetches new data)
         */
        updateTopicMetrics: function(topicName, trafficPotential, keywordDifficulty) {
            if (!this.$container) return;

            const $chip = this.$container.find('.ttd-meta-keyword-radio[value="' + this.escapeAttr(topicName) + '"]').closest('.ttd-meta-keyword-chip');
            if (!$chip.length) return;

            // Update data attributes
            const $radio = $chip.find('.ttd-meta-keyword-radio');
            $radio.attr('data-volume', trafficPotential || '');
            $radio.attr('data-kd', keywordDifficulty || '');

            // Update volume display
            const volumeStr = trafficPotential ? this.formatVolume(trafficPotential) : '';
            let $volume = $chip.find('.ttd-chip-volume');
            if (volumeStr) {
                if ($volume.length) {
                    $volume.text(volumeStr);
                } else {
                    // Insert after chip name
                    $chip.find('.ttd-chip-name').after('<span class="ttd-chip-volume" title="' + Drupal.t('Traffic Potential') + '">' + volumeStr + '</span>');
                }
            } else if ($volume.length) {
                $volume.remove();
            }

            // Update KD dot
            const kdInfo = this.getKDBadge(keywordDifficulty);
            const kdNum = keywordDifficulty !== null && keywordDifficulty !== undefined ? Math.round(keywordDifficulty) : null;
            const kdTooltip = kdNum !== null
                ? Drupal.t('Difficulty: @kd/100 (@label)', {'@kd': kdNum, '@label': kdInfo.label})
                : Drupal.t('No difficulty data');

            const $kdDot = $chip.find('.ttd-chip-kd');
            $kdDot.attr('class', 'ttd-chip-kd ' + kdInfo.class).attr('title', kdTooltip);
        },

        /**
         * Show error message
         */
        showError: function(message) {
            const $error = $('<div class="ttd-meta-message ttd-meta-error"></div>').text(message);
            this.$container.find('.ttd-meta-generator').prepend($error);
            setTimeout(function() { $error.fadeOut(function() { $error.remove(); }); }, 5000);
        },

        /**
         * Show success message
         */
        showSuccess: function(message) {
            const $success = $('<div class="ttd-meta-message ttd-meta-success"></div>').text(message);
            this.$container.find('.ttd-meta-generator').prepend($success);
            setTimeout(function() { $success.fadeOut(function() { $success.remove(); }); }, 3000);
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
         * Escape attribute value
         */
        escapeAttr: function(text) {
            if (typeof text !== 'string') {
                text = String(text || '');
            }
            return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        /**
         * Unescape backslash-escaped characters from API responses
         */
        unescapeText: function(text) {
            if (typeof text !== 'string') {
                return text;
            }
            return text.replace(/\\"/g, '"').replace(/\\'/g, "'");
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
            const postSlug = config.postSlug || '';
            const apiBase = config.apiBase || '/api/topicalboost/meta';
            const csrfToken = config.nonce || '';

            if (!nodeId) {
                return;
            }

            // Initialize the meta generator
            function initMetaGenerator() {
                MetaGenerator.init($container, {
                    nodeId: nodeId,
                    postUrl: postUrl,
                    postSlug: postSlug,
                    minKeywords: 1,
                    existingMeta: config.existingMeta || null,
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
