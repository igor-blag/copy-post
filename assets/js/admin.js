(function () {
    'use strict';

    var data = window.copyPostData || {};
    var strings = data.strings || {};
    var running = false;
    var stopRequested = false;

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function toBool(value) {
        return value === true || value === 1 || value === '1';
    }

    function parseIds(value) {
        return String(value || '')
            .split(',')
            .map(function (item) {
                return parseInt(item, 10);
            })
            .filter(function (item) {
                return !isNaN(item) && item > 0;
            });
    }

    function getPanel(element) {
        return element ? element.closest('.copy-post-panel') : null;
    }

    function getPostIds(panel) {
        var input = qs('.copy-post-post-ids', panel);
        return input ? parseIds(input.value) : [];
    }

    function setPostIds(panel, ids) {
        var unique = [];
        var input = qs('.copy-post-post-ids', panel);

        ids.forEach(function (id) {
            id = parseInt(id, 10);

            if (id > 0 && unique.indexOf(id) === -1) {
                unique.push(id);
            }
        });

        if (input) {
            input.value = unique.join(',');
        }

        panel.dataset.postIds = unique.join(',');
        updateCount(panel);
    }

    function updateCount(panel) {
        var count = qs('.copy-post-selected-count', panel);
        var ids = getPostIds(panel);

        if (count) {
            count.textContent = String(ids.length);
        }
    }

    function getTargets(panel) {
        return qsa('input[name="targets[]"]:checked', panel).map(function (input) {
            return parseInt(input.value, 10);
        }).filter(function (id) {
            return !isNaN(id) && id > 0;
        });
    }

    function getOptions(panel) {
        var keys = [
            'copy_meta',
            'copy_media',
            'copy_dates',
            'copy_authors',
            'copy_sticky',
            'rewrite_urls',
            'copy_comments'
        ];
        var options = {};

        keys.forEach(function (key) {
            var input = qs('input[name="' + key + '"]', panel);
            options[key] = input ? input.checked : toBool(data.settings && data.settings[key]);
        });

        var status = qs('select[name="status"]', panel);
        var duplicate = qs('select[name="duplicate"]', panel);

        options.status = status ? status.value : (data.settings.default_status || 'draft');
        options.duplicate = duplicate ? duplicate.value : (data.settings.duplicate_action || 'new');
        options.update_slug = toBool(data.settings && data.settings.update_slug);
        options.taxonomy_modes = {};
        options.taxonomy_terms = {};
        options.taxonomy_clear_unselected = {};

        qsa('.copy-post-taxonomy-target', panel).forEach(function (block) {
            var targetId = parseInt(block.dataset.targetId || '0', 10);

            if (!targetId) {
                return;
            }

            var modeInput = qs('.copy-post-taxonomy-mode:checked', block);
            var mode = modeInput ? modeInput.value : 'copy';
            options.taxonomy_modes[targetId] = mode;

            if (mode !== 'manual') {
                return;
            }

            var clearInput = qs('.copy-post-taxonomy-clear', block);
            options.taxonomy_clear_unselected[targetId] = clearInput && clearInput.checked ? 1 : 0;
            var terms = {};

            qsa('.copy-post-taxonomy', block).forEach(function (taxonomyBlock) {
                var taxonomy = taxonomyBlock.dataset.taxonomy || '';

                if (!taxonomy) {
                    return;
                }

                terms[taxonomy] = qsa('.copy-post-term-select option:checked', taxonomyBlock)
                    .map(function (option) {
                        return parseInt(option.value, 10);
                    })
                    .filter(function (id) {
                        return !isNaN(id) && id > 0;
                    });
            });

            options.taxonomy_terms[targetId] = terms;
        });

        return options;
    }

    function getSiteData(targetId) {
        var sites = data.sites || [];

        for (var i = 0; i < sites.length; i++) {
            if (parseInt(sites[i].blog_id, 10) === targetId) {
                return sites[i];
            }
        }

        return { blog_id: targetId, name: '#' + targetId, url: '' };
    }

    function showTaxonomyMessage(block, message, isError) {
        var element = qs('.copy-post-taxonomy-message', block);

        if (!element) {
            return;
        }

        element.textContent = message || '';
        element.classList.toggle('is-error', Boolean(isError));
    }

    function createTaxonomyBlock(panel, targetId) {
        var site = getSiteData(targetId);
        var block = document.createElement('div');
        block.className = 'copy-post-taxonomy-target';
        block.dataset.targetId = String(targetId);

        var header = document.createElement('div');
        header.className = 'copy-post-taxonomy-target__header';
        var name = document.createElement('strong');
        name.textContent = site.name || '#' + targetId;
        header.appendChild(name);

        if (site.url) {
            var url = document.createElement('span');
            url.className = 'copy-post-taxonomy-target__url';
            url.textContent = site.url;
            header.appendChild(url);
        }

        block.appendChild(header);

        var modes = document.createElement('div');
        modes.className = 'copy-post-taxonomy-target__modes';
        var defaultMode = toBool(data.settings && data.settings.copy_taxonomies) ? 'copy' : 'none';
        var modeLabels = {
            copy: strings.taxonomyCopy || 'Копировать с источника',
            manual: strings.taxonomyManual || 'Выбрать на сайте',
            none: strings.taxonomyNone || 'Не копировать'
        };

        Object.keys(modeLabels).forEach(function (value) {
            var label = document.createElement('label');
            label.className = 'copy-post-option';
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'copy_post_taxonomy_mode_' + targetId;
            input.value = value;
            input.className = 'copy-post-taxonomy-mode';
            input.checked = value === defaultMode;
            var text = document.createElement('span');
            text.textContent = modeLabels[value];
            label.appendChild(input);
            label.appendChild(text);
            modes.appendChild(label);
        });

        block.appendChild(modes);

        var manual = document.createElement('div');
        manual.className = 'copy-post-taxonomy-manual';
        manual.hidden = true;

        var clearLabel = document.createElement('label');
        clearLabel.className = 'copy-post-option copy-post-taxonomy-clear-label';
        var clearInput = document.createElement('input');
        clearInput.type = 'checkbox';
        clearInput.className = 'copy-post-taxonomy-clear';
        clearInput.checked = true;
        var clearText = document.createElement('span');
        clearText.textContent = strings.taxonomyClear || 'Очищать невыбранные таксономии на этом сайте';
        clearLabel.appendChild(clearInput);
        clearLabel.appendChild(clearText);
        manual.appendChild(clearLabel);

        var clearHelp = document.createElement('p');
        clearHelp.className = 'description';
        clearHelp.textContent = strings.taxonomyClearHelp || 'Если снять, таксономии без выбора останутся как на целевом сайте.';
        manual.appendChild(clearHelp);

        var list = document.createElement('div');
        list.className = 'copy-post-taxonomy-list';
        manual.appendChild(list);

        var message = document.createElement('p');
        message.className = 'copy-post-taxonomy-message';
        manual.appendChild(message);

        block.appendChild(manual);
        block.dataset.loaded = '';

        return block;
    }

    function ensureTaxonomyBlocks(panel) {
        var container = qs('.copy-post-taxonomy-targets', panel);

        if (!container) {
            return;
        }

        var selected = getTargets(panel);
        var existing = qsa('.copy-post-taxonomy-target', container);

        existing.forEach(function (block) {
            var targetId = parseInt(block.dataset.targetId || '0', 10);

            if (selected.indexOf(targetId) === -1) {
                block.remove();
            }
        });

        selected.forEach(function (targetId) {
            if (!qs('.copy-post-taxonomy-target[data-target-id="' + targetId + '"]', container)) {
                container.appendChild(createTaxonomyBlock(panel, targetId));
            }
        });

        var empty = qs('.copy-post-taxonomy-empty', panel);

        if (empty) {
            empty.hidden = selected.length > 0;
        }
    }

    function loadTaxonomies(panel, block, targetId, force, selectedExtra) {
        if (!panel._taxonomies) {
            panel._taxonomies = {};
        }

        if (!force && panel._taxonomies[targetId]) {
            block.dataset.loaded = '1';
            renderTaxonomies(block, panel._taxonomies[targetId], selectedExtra);
            return Promise.resolve();
        }

        showTaxonomyMessage(block, strings.taxonomyLoading || 'Загрузка таксономий…', false);

        return request('copy_post_terms', {
            target_id: targetId,
            post_type: panel.dataset.postType || ''
        }).then(function (result) {
            panel._taxonomies[targetId] = result && result.taxonomies ? result.taxonomies : [];
            block.dataset.loaded = '1';
            showTaxonomyMessage(block, '', false);
            renderTaxonomies(block, panel._taxonomies[targetId], selectedExtra);
        }).catch(function (error) {
            block.dataset.loaded = '';
            showTaxonomyMessage(block, error.message || strings.taxonomyError || 'Не удалось загрузить таксономии.', true);
        });
    }

    function renderTaxonomies(block, taxonomies, selectedExtra) {
        var list = qs('.copy-post-taxonomy-list', block);

        if (!list) {
            return;
        }

        var selected = new Set();

        qsa('.copy-post-term-select option:checked', list).forEach(function (option) {
            selected.add(parseInt(option.value, 10));
        });

        if (selectedExtra && selectedExtra.forEach) {
            selectedExtra.forEach(function (id) {
                selected.add(parseInt(id, 10));
            });
        }

        list.innerHTML = '';

        if (!taxonomies || taxonomies.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'copy-post-taxonomy-note';
            empty.textContent = strings.taxonomyEmpty || 'У этого типа записи на целевом сайте нет таксономий.';
            list.appendChild(empty);
            return;
        }

        taxonomies.forEach(function (taxonomy) {
            renderTaxonomyBlock(list, taxonomy, selected);
        });
    }

    function renderTaxonomyBlock(list, taxonomy, selected) {
        var wrap = document.createElement('div');
        wrap.className = 'copy-post-taxonomy';
        wrap.dataset.taxonomy = taxonomy.name;

        var label = document.createElement('label');
        label.className = 'copy-post-taxonomy__label';
        label.textContent = taxonomy.label;
        wrap.appendChild(label);

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'copy-post-term-search';
        search.placeholder = strings.taxonomySearch || 'Поиск терминов…';
        search.setAttribute('aria-label', strings.taxonomySearch || 'Поиск терминов');
        wrap.appendChild(search);

        var select = document.createElement('select');
        select.className = 'copy-post-term-select';
        select.multiple = true;
        select.size = 7;

        (taxonomy.terms || []).forEach(function (term) {
            var option = document.createElement('option');
            option.value = String(term.id);
            option.textContent = (term.depth > 0 ? '— '.repeat(term.depth) : '') + term.name;
            option.selected = selected.has(parseInt(term.id, 10));
            select.appendChild(option);
        });

        wrap.appendChild(select);

        if (!taxonomy.terms || taxonomy.terms.length === 0) {
            var noTerms = document.createElement('p');
            noTerms.className = 'copy-post-taxonomy-note';
            noTerms.textContent = strings.taxonomyNoTerms || 'Терминов пока нет.';
            wrap.appendChild(noTerms);
        }

        if (taxonomy.can_create) {
            wrap.appendChild(createTermRow(taxonomy, false));
        }

        list.appendChild(wrap);
    }

    function createTermRow(taxonomy, isParent) {
        var row = document.createElement('div');
        row.className = 'copy-post-term-create';

        if (isParent) {
            return row;
        }

        if (taxonomy.hierarchical) {
            var parent = document.createElement('select');
            parent.className = 'copy-post-new-term-parent';
            parent.setAttribute('aria-label', strings.taxonomyParent || 'Родительский термин');
            var top = document.createElement('option');
            top.value = '0';
            top.textContent = strings.taxonomyTopLevel || '— верхний уровень —';
            parent.appendChild(top);

            (taxonomy.terms || []).forEach(function (term) {
                var option = document.createElement('option');
                option.value = String(term.id);
                option.textContent = (term.depth > 0 ? '— '.repeat(term.depth) : '') + term.name;
                parent.appendChild(option);
            });

            row.appendChild(parent);
        }

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'copy-post-new-term';
        input.placeholder = strings.taxonomyNewTerm || 'Название нового термина';
        input.setAttribute('aria-label', strings.taxonomyNewTerm || 'Название нового термина');
        row.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-small copy-post-create-term';
        button.textContent = strings.taxonomyCreate || 'Создать';
        row.appendChild(button);

        return row;
    }

    function filterTerms(taxonomyBlock, term) {
        term = (term || '').toLowerCase().trim();

        qsa('.copy-post-term-select option', taxonomyBlock).forEach(function (option) {
            var text = (option.textContent || '').toLowerCase();
            option.hidden = term !== '' && text.indexOf(term) === -1;
        });
    }

    function createTerm(button) {
        var taxonomyBlock = button.closest('.copy-post-taxonomy');
        var targetBlock = button.closest('.copy-post-taxonomy-target');
        var panel = getPanel(button);

        if (!taxonomyBlock || !targetBlock || !panel) {
            return;
        }

        var targetId = parseInt(targetBlock.dataset.targetId || '0', 10);
        var taxonomy = taxonomyBlock.dataset.taxonomy || '';
        var input = qs('.copy-post-new-term', taxonomyBlock);
        var parentSelect = qs('.copy-post-new-term-parent', taxonomyBlock);
        var name = input ? input.value.trim() : '';

        if (!targetId || !taxonomy || name === '') {
            return;
        }

        var parent = parentSelect ? parseInt(parentSelect.value, 10) || 0 : 0;
        var select = qs('.copy-post-term-select', taxonomyBlock);
        var selected = new Set();

        if (select) {
            Array.prototype.forEach.call(select.selectedOptions, function (option) {
                selected.add(parseInt(option.value, 10));
            });
        }

        button.disabled = true;
        showTaxonomyMessage(targetBlock, strings.taxonomyLoading || 'Загрузка таксономий…', false);

        request('copy_post_create_term', {
            target_id: targetId,
            taxonomy: taxonomy,
            name: name,
            parent: parent,
            post_type: panel.dataset.postType || ''
        }).then(function (result) {
            if (input) {
                input.value = '';
            }

            if (result && result.term && result.term.id) {
                selected.add(parseInt(result.term.id, 10));
            }

            return loadTaxonomies(panel, targetBlock, targetId, true, selected).then(function () {
                showTaxonomyMessage(targetBlock, strings.taxonomyCreated || 'Термин добавлен в список.', false);
            });
        }).catch(function (error) {
            showTaxonomyMessage(targetBlock, error.message || strings.error || 'Ошибка', true);
        }).then(function () {
            button.disabled = false;
        });
    }

    function setMessage(panel, message) {
        var element = qs('.copy-post-message', panel);

        if (element) {
            element.textContent = message || '';
            element.classList.toggle('is-visible', Boolean(message));
        }
    }

    function setRunning(panel, state) {
        running = state;
        panel.classList.toggle('is-running', state);

        qsa('.copy-post-start', panel).forEach(function (button) {
            button.disabled = state;
        });

        qsa('.copy-post-stop', panel).forEach(function (button) {
            button.disabled = !state;
        });
    }

    function setProgress(panel, processed, total, text) {
        var bar = qs('.copy-post-progress__bar span', panel);
        var label = qs('.copy-post-progress__text', panel);
        var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

        if (bar) {
            bar.style.width = percent + '%';
        }

        if (label) {
            label.textContent = text;
        }
    }

    function clearResults(panel) {
        var body = qs('.copy-post-results__table tbody', panel);
        var summary = qs('.copy-post-summary', panel);
        var retry = qs('.copy-post-retry', panel);

        if (body) {
            body.innerHTML = '';
        }

        if (summary) {
            summary.textContent = '';
        }

        if (retry) {
            retry.hidden = true;
        }

        panel._failedItems = [];
    }

    function resetPanel(panel) {
        var progress = qs('.copy-post-progress', panel);
        var results = qs('.copy-post-results', panel);

        qsa('input[name="targets[]"]', panel).forEach(function (input) {
            input.checked = false;
        });

        var selectAll = qs('.copy-post-select-all-sites', panel);

        if (selectAll) {
            selectAll.checked = false;
        }

        qsa('.copy-post-site', panel).forEach(function (site) {
            site.hidden = false;
            var badge = qs('.copy-post-site__badge', site);

            if (badge) {
                badge.hidden = true;
                badge.textContent = '';
            }
        });

        var search = qs('.copy-post-site-search', panel);

        if (search) {
            search.value = '';
        }

        var defaults = data.settings || {};
        var optionKeys = [
            'copy_meta',
            'copy_media',
            'copy_dates',
            'copy_authors',
            'copy_sticky',
            'rewrite_urls',
            'copy_comments'
        ];

        optionKeys.forEach(function (key) {
            var input = qs('input[name="' + key + '"]', panel);

            if (input) {
                input.checked = toBool(defaults[key]);
            }
        });

        var duplicate = qs('select[name="duplicate"]', panel);
        var status = qs('select[name="status"]', panel);

        if (duplicate) {
            duplicate.value = defaults.duplicate_action || 'new';
        }

        if (status) {
            status.value = defaults.default_status || 'draft';
        }

        if (progress) {
            progress.hidden = true;
        }

        if (results) {
            results.hidden = true;
        }

        setMessage(panel, '');
        clearResults(panel);
        setProgress(panel, 0, 0, '');
        setRunning(panel, false);

        var taxonomyTargets = qs('.copy-post-taxonomy-targets', panel);

        if (taxonomyTargets) {
            taxonomyTargets.innerHTML = '';
        }

        panel._taxonomies = {};
        var taxonomyEmpty = qs('.copy-post-taxonomy-empty', panel);

        if (taxonomyEmpty) {
            taxonomyEmpty.hidden = false;
        }

        updateCount(panel);
    }

    function closeModal() {
        var dialog = document.getElementById('copy-post-modal');

        if (!dialog || !dialog.open) {
            return;
        }

        if (running) {
            return;
        }

        dialog.close();
    }

    function openModal(postId, title) {
        var dialog = document.getElementById('copy-post-modal');

        if (!dialog) {
            return false;
        }

        var panel = qs('.copy-post-panel', dialog);

        if (!panel) {
            return false;
        }

        resetPanel(panel);
        setPostIds(panel, [parseInt(postId, 10)]);

        var subtitle = qs('.copy-post-modal__subtitle', dialog);

        if (subtitle) {
            subtitle.textContent = title ? '«' + title + '»' : 'Запись #' + postId;
        }

        if (!dialog.open) {
            dialog.showModal();
        }

        loadStatus(panel, parseInt(postId, 10));

        return true;
    }

    function loadStatus(panel, postId) {
        if (!postId || !data.ajaxUrl || !data.nonce) {
            return;
        }

        request('copy_post_status', { post_id: postId }).then(function (result) {
            var copies = result && result.copies ? result.copies : {};
            var keys = Object.keys(copies);

            keys.forEach(function (blogId) {
                var input = qs('input[name="targets[]"][value="' + blogId + '"]', panel);

                if (!input) {
                    return;
                }

                var site = input.closest('.copy-post-site');
                var badge = site ? qs('.copy-post-site__badge', site) : null;

                if (badge) {
                    badge.hidden = false;
                    badge.textContent = strings.existing || 'копия уже есть';
                }

                if (site) {
                    site.classList.add('has-copy');
                }
            });
        }).catch(function () {
            // Подсказка о существующих копиях не критична.
        });
    }

    function request(action, params) {
        var body = new URLSearchParams();

        body.append('action', action);
        body.append('nonce', data.nonce || '');

        Object.keys(params || {}).forEach(function (key) {
            var value = params[key];

            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', item);
                });
            } else {
                body.append(key, value);
            }
        });

        return fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error(strings.error || 'Ошибка ответа сервера');
            });
        }).then(function (json) {
            if (!json || !json.success) {
                var message = json && json.data && json.data.message
                    ? json.data.message
                    : (strings.error || 'Ошибка');

                throw new Error(message);
            }

            return json.data;
        });
    }

    function appendLink(cell, href, label) {
        if (!href) {
            return;
        }

        var link = document.createElement('a');
        link.href = href;
        link.textContent = label;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'copy-post-result__link';
        cell.appendChild(link);
    }

    function appendRow(panel, title, targetName, statusLabel, statusClass, message, editLink, viewLink, warnings) {
        var body = qs('.copy-post-results__table tbody', panel);

        if (!body) {
            return;
        }

        var row = document.createElement('tr');
        var titleCell = document.createElement('td');
        var targetCell = document.createElement('td');
        var resultCell = document.createElement('td');
        var badge = document.createElement('span');

        titleCell.textContent = title || '';
        targetCell.textContent = targetName || '';
        badge.className = 'copy-post-status ' + statusClass;
        badge.textContent = statusLabel || '';
        resultCell.appendChild(badge);

        if (message) {
            var messageElement = document.createElement('div');
            messageElement.className = statusClass === 'copy-post-status--error'
                ? 'copy-post-result__error'
                : 'copy-post-result__message';
            messageElement.textContent = message;
            resultCell.appendChild(messageElement);
        }

        if (editLink) {
            appendLink(resultCell, editLink, strings.open || 'Открыть');
        }

        if (viewLink) {
            appendLink(resultCell, viewLink, strings.view || 'На сайте');
        }

        if (warnings && warnings.length) {
            var warningsElement = document.createElement('div');
            warningsElement.className = 'copy-post-warning';
            warningsElement.textContent = (strings.warnings || 'Предупреждения') + ': ' + warnings.join('; ');
            resultCell.appendChild(warningsElement);
        }

        row.appendChild(titleCell);
        row.appendChild(targetCell);
        row.appendChild(resultCell);
        body.appendChild(row);
    }

    function renderRun(panel, result, counts, failedItems) {
        var title = result.source_title || ('#' + result.source_id);
        var results = result.results || [];

        results.forEach(function (item) {
            var targetName = item.target_name || ('#' + item.target_blog_id);

            if (item.error) {
                counts.error += 1;
                failedItems.push({
                    postId: result.source_id,
                    targetIds: [parseInt(item.target_blog_id, 10)]
                });
                appendRow(
                    panel,
                    title,
                    targetName,
                    strings.error || 'Ошибка',
                    'copy-post-status--error',
                    item.error,
                    '',
                    '',
                    item.warnings || []
                );
                return;
            }

            var action = item.action || '';

            if (counts[action] === undefined) {
                counts[action] = 0;
            }

            counts[action] += 1;

            var labels = {
                created: strings.created || 'Создана',
                updated: strings.updated || 'Обновлена',
                skipped: strings.skipped || 'Пропущена'
            };
            var classes = {
                created: 'copy-post-status--created',
                updated: 'copy-post-status--updated',
                skipped: 'copy-post-status--skipped'
            };

            appendRow(
                panel,
                title,
                targetName,
                labels[action] || action,
                classes[action] || '',
                '',
                item.edit_link || '',
                item.view_link || '',
                item.warnings || []
            );
        });
    }

    function renderRequestError(panel, item, message, counts, failedItems) {
        counts.error += Math.max(1, (item.targetIds || []).length);
        failedItems.push(item);

        appendRow(
            panel,
            '#' + item.postId,
            '—',
            strings.error || 'Ошибка',
            'copy-post-status--error',
            message,
            '',
            '',
            []
        );
    }

    function mergeFailedItems(items) {
        var merged = {};
        var out = [];

        items.forEach(function (item) {
            if (!merged[item.postId]) {
                merged[item.postId] = { postId: item.postId, targetIds: [] };
            }

            (item.targetIds || []).forEach(function (id) {
                id = parseInt(id, 10);

                if (id > 0 && merged[item.postId].targetIds.indexOf(id) === -1) {
                    merged[item.postId].targetIds.push(id);
                }
            });
        });

        Object.keys(merged).forEach(function (key) {
            out.push(merged[key]);
        });

        return out;
    }

    function formatSummary(counts) {
        var template = strings.summary || 'Создано: %1$d, обновлено: %2$d, пропущено: %3$d, ошибок: %4$d';

        return template
            .replace('%1$d', counts.created || 0)
            .replace('%2$d', counts.updated || 0)
            .replace('%3$d', counts.skipped || 0)
            .replace('%4$d', counts.error || 0);
    }

    function runItems(items, panel, retryMode) {
        if (running || !items.length) {
            return;
        }

        setMessage(panel, '');
        clearResults(panel);

        var progress = qs('.copy-post-progress', panel);
        var results = qs('.copy-post-results', panel);

        if (progress) {
            progress.hidden = false;
        }

        if (results) {
            results.hidden = true;
        }

        setRunning(panel, true);
        stopRequested = false;

        var counts = { created: 0, updated: 0, skipped: 0, error: 0 };
        var failedItems = [];
        var processed = 0;
        var total = items.length;
        var options = getOptions(panel);

        if (retryMode) {
            options.duplicate = 'update';
        }

        setProgress(panel, 0, total, (strings.copying || 'Копирование…') + ' 0 / ' + total);

        function next(index) {
            if (stopRequested || index >= items.length) {
                finish();
                return;
            }

            var item = items[index];
            var body = new URLSearchParams();

            body.append('action', 'copy_post_run');
            body.append('nonce', data.nonce || '');
            body.append('post_id', item.postId);
            (item.targetIds || []).forEach(function (id) {
                body.append('target_ids[]', id);
            });
            body.append('options', JSON.stringify(options));

            fetch(data.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (!json || !json.success) {
                    var message = json && json.data && json.data.message
                        ? json.data.message
                        : (strings.error || 'Ошибка');
                    throw new Error(message);
                }

                renderRun(panel, json.data, counts, failedItems);
            }).catch(function (error) {
                renderRequestError(panel, item, error.message, counts, failedItems);
            }).then(function () {
                processed += 1;
                setProgress(
                    panel,
                    processed,
                    total,
                    (strings.copying || 'Копирование…') + ' ' + processed + ' / ' + total
                );
                next(index + 1);
            });
        }

        function finish() {
            setRunning(panel, false);

            var summary = qs('.copy-post-summary', panel);
            var retry = qs('.copy-post-retry', panel);
            var progressText = qs('.copy-post-progress__text', panel);

            if (summary) {
                summary.textContent = formatSummary(counts);
            }

            if (retry) {
                panel._failedItems = mergeFailedItems(failedItems);
                retry.hidden = panel._failedItems.length === 0;
            }

            if (progressText) {
                progressText.textContent = stopRequested
                    ? (strings.stopped || 'Остановлено')
                    : (strings.done || 'Готово');
            }

            if (stopRequested) {
                setProgress(panel, processed, total, strings.stopped || 'Остановлено');
            } else {
                setProgress(panel, total, total, strings.done || 'Готово');
            }

            if (results) {
                results.hidden = false;
            }
        }

        next(0);
    }

    function startPanel(panel) {
        var postIds = getPostIds(panel);
        var targets = getTargets(panel);

        if (postIds.length === 0) {
            setMessage(panel, strings.selectPost || 'Выберите хотя бы одну запись.');
            return;
        }

        if (targets.length === 0) {
            setMessage(panel, strings.selectTarget || 'Выберите хотя бы один целевой сайт.');
            return;
        }

        ensureTaxonomyBlocks(panel);

        var manualBlocks = qsa('.copy-post-taxonomy-target', panel).filter(function (block) {
            var mode = qs('.copy-post-taxonomy-mode:checked', block);
            return mode && mode.value === 'manual';
        });

        for (var i = 0; i < manualBlocks.length; i++) {
            if (manualBlocks[i].dataset.loaded !== '1') {
                setMessage(panel, strings.taxonomyNotLoaded || 'Дождитесь загрузки таксономий для выбранных сайтов.');
                return;
            }
        }

        var items = postIds.map(function (postId) {
            return { postId: postId, targetIds: targets.slice() };
        });

        panel._queue = items;
        runItems(items, panel, false);
    }

    function syncPageSelection() {
        var page = qs('.copy-post-tools');
        var panel = page ? qs('.copy-post-panel', page) : null;

        if (!panel) {
            return;
        }

        if (!qs('.copy-post-post-checkbox', page)) {
            return;
        }

        var ids = qsa('.copy-post-post-checkbox:checked', page)
            .map(function (input) {
                return {
                    id: parseInt(input.value, 10),
                    depth: parseInt(input.dataset.depth || '0', 10)
                };
            })
            .sort(function (a, b) {
                if (a.depth !== b.depth) {
                    return a.depth - b.depth;
                }

                return a.id - b.id;
            })
            .map(function (item) {
                return item.id;
            });

        setPostIds(panel, ids);
    }

    function syncSelectAll(panel) {
        var targets = qsa('input[name="targets[]"]', panel);
        var checked = targets.filter(function (input) {
            return input.checked;
        });
        var selectAll = qs('.copy-post-select-all-sites', panel);

        if (selectAll) {
            selectAll.checked = targets.length > 0 && checked.length === targets.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < targets.length;
        }
    }

    function filterSites(panel, term) {
        term = (term || '').toLowerCase().trim();

        qsa('.copy-post-site', panel).forEach(function (site) {
            var name = site.dataset.siteName || '';
            var text = (site.textContent || '').toLowerCase();
            var matches = term === '' || name.indexOf(term) !== -1 || text.indexOf(term) !== -1;
            site.hidden = !matches;
        });
    }

    function init() {
        if (!data.ajaxUrl || !data.nonce) {
            return;
        }

        document.addEventListener('click', function (event) {
            var opener = event.target.closest('.copy-post-open');

            if (opener) {
                var postId = parseInt(opener.dataset.postId || opener.getAttribute('data-post-id'), 10);
                var title = opener.dataset.postTitle || opener.getAttribute('data-post-title') || '';

                if (postId > 0 && openModal(postId, title)) {
                    event.preventDefault();
                }

                return;
            }

            if (event.target.closest('.copy-post-close')) {
                event.preventDefault();
                closeModal();
                return;
            }

            var start = event.target.closest('.copy-post-start');

            if (start) {
                event.preventDefault();
                var panel = getPanel(start);

                if (panel) {
                    startPanel(panel);
                }

                return;
            }

            if (event.target.closest('.copy-post-stop')) {
                event.preventDefault();
                stopRequested = true;

                var runningPanel = qs('.copy-post-panel.is-running');

                if (runningPanel) {
                    setMessage(runningPanel, strings.stopped || 'Остановлено');
                }

                return;
            }

            var createTermButton = event.target.closest('.copy-post-create-term');

            if (createTermButton) {
                event.preventDefault();
                createTerm(createTermButton);
                return;
            }

            var retry = event.target.closest('.copy-post-retry');

            if (retry) {
                event.preventDefault();
                var retryPanel = getPanel(retry);

                if (retryPanel && retryPanel._failedItems && retryPanel._failedItems.length) {
                    runItems(retryPanel._failedItems, retryPanel, true);
                }
            }
        });

        document.addEventListener('change', function (event) {
            if (event.target.matches('.copy-post-select-all-sites')) {
                var panel = getPanel(event.target);

                if (!panel) {
                    return;
                }

                qsa('input[name="targets[]"]', panel).forEach(function (input) {
                    input.checked = event.target.checked;
                });

                syncSelectAll(panel);
                ensureTaxonomyBlocks(panel);
                return;
            }

            if (event.target.matches('input[name="targets[]"]')) {
                var targetPanel = getPanel(event.target);

                if (targetPanel) {
                    syncSelectAll(targetPanel);
                    ensureTaxonomyBlocks(targetPanel);
                }

                return;
            }

            if (event.target.matches('.copy-post-taxonomy-mode')) {
                var taxonomyBlock = event.target.closest('.copy-post-taxonomy-target');
                var taxonomyPanel = getPanel(event.target);

                if (!taxonomyBlock || !taxonomyPanel) {
                    return;
                }

                var manualContainer = qs('.copy-post-taxonomy-manual', taxonomyBlock);

                if (!manualContainer) {
                    return;
                }

                if (event.target.value === 'manual') {
                    manualContainer.hidden = false;
                    loadTaxonomies(
                        taxonomyPanel,
                        taxonomyBlock,
                        parseInt(taxonomyBlock.dataset.targetId || '0', 10),
                        false
                    );
                } else {
                    manualContainer.hidden = true;
                }

                return;
            }

            if (event.target.matches('.copy-post-post-checkbox')) {
                syncPageSelection();
                return;
            }

            if (event.target.matches('.copy-post-select-all-posts')) {
                var page = qs('.copy-post-tools');

                if (page) {
                    qsa('.copy-post-post-checkbox', page).forEach(function (input) {
                        input.checked = event.target.checked;
                    });

                    syncPageSelection();
                }
            }
        });

        document.addEventListener('input', function (event) {
            if (event.target.matches('.copy-post-site-search')) {
                var panel = getPanel(event.target);

                if (panel) {
                    filterSites(panel, event.target.value);
                }

                return;
            }

            if (event.target.matches('.copy-post-term-search')) {
                var taxonomyBlock = event.target.closest('.copy-post-taxonomy');

                if (taxonomyBlock) {
                    filterTerms(taxonomyBlock, event.target.value);
                }
            }
        });

        qsa('.copy-post-modal__close').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                closeModal();
            });
        });

        var dialog = document.getElementById('copy-post-modal');

        if (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    closeModal();
                }
            });

            dialog.addEventListener('cancel', function (event) {
                if (running) {
                    event.preventDefault();
                }
            });
        }

        qsa('.copy-post-panel').forEach(function (panel) {
            updateCount(panel);
            syncSelectAll(panel);
            ensureTaxonomyBlocks(panel);
        });

        syncPageSelection();

        window.addEventListener('beforeunload', function (event) {
            if (!running) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
