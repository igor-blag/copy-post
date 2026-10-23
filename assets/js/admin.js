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
            'copy_taxonomies',
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

        return options;
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
            'copy_taxonomies',
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
                return;
            }

            if (event.target.matches('input[name="targets[]"]')) {
                var targetPanel = getPanel(event.target);

                if (targetPanel) {
                    syncSelectAll(targetPanel);
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
