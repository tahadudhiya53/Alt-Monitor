/* global Craft */
(function() {
    'use strict';

    // Scan progress: refreshes the progress bar while a scan runs, and reloads the page when it
    // ends so the numbers shown are the finished scan's.
    function pollScan(container) {
        var bar = container.querySelector('[data-progress-bar]');
        var text = container.querySelector('[data-progress-text]');

        function poll() {
            Craft.sendActionRequest('GET', 'alt-monitor/dashboard/progress').then(function(response) {
                var scan = response.data.scan;

                if (!scan || !scan.active) {
                    window.location.reload();
                    return;
                }

                bar.style.width = scan.progress + '%';
                bar.parentNode.setAttribute('aria-valuenow', scan.progress);
                text.textContent = Craft.t('alt-monitor', '{type}: {scanned, number} of {total, number} ({progress}%)', {
                    type: scan.typeLabel,
                    scanned: scan.scanned,
                    total: scan.total,
                    progress: scan.progress,
                });
                setTimeout(poll, 3000);
            }).catch(function() {
                text.textContent = Craft.t('alt-monitor', 'Couldn’t load progress. Reload the page to try again.');
            });
        }

        setTimeout(poll, 3000);
    }

    // The settings page's connection test, run with the values being edited.
    function connectionTest(button) {
        var result = document.getElementById(button.getAttribute('aria-controls'));

        button.addEventListener('click', function() {
            button.classList.add('loading');
            button.disabled = true;
            result.textContent = Craft.t('alt-monitor', 'Testing…');
            result.className = '';

            Craft.sendActionRequest('POST', 'alt-monitor/settings/test-connection').then(function(response) {
                var data = response.data;
                result.textContent = data.message;
                result.className = data.reachable && data.modelAvailable ? 'success' : 'error';
            }).catch(function(error) {
                result.textContent = (error.response && error.response.data && error.response.data.message) || Craft.t('alt-monitor', 'The test could not be run.');
                result.className = 'error';
            }).finally(function() {
                button.classList.remove('loading');
                button.disabled = false;
            });
        });
    }

    // “Select all” for list checkboxes.
    function selectAll(box) {
        var table = box.closest('table');

        box.addEventListener('change', function() {
            table.querySelectorAll('input[type="checkbox"][data-select]:not(:disabled)').forEach(function(item) {
                item.checked = box.checked;
            });
        });
    }

    // Describing images in the browser: a worker runs the image model; this fetches each image
    // through Craft, hands it to the worker with what the image already has (so it looks for
    // something new), and stores what it wrote as a suggestion for review. Progress shows in a
    // popup (a <dialog>) with the image, its new description and a Stop button; on the image
    // model page the same object reports into the page's download panel instead.
    function Describer(panel) {
        var self = this;
        self.panel = panel;
        self.config = JSON.parse(panel.getAttribute('data-config'));
        self.bar = panel.querySelector('[data-bar]');
        self.status = panel.querySelector('[data-status]');
        self.preview = panel.querySelector('[data-preview]');
        self.name = panel.querySelector('[data-name]');
        self.result = panel.querySelector('[data-result]');
        self.stopButton = panel.querySelector('[data-stop]');
        self.closeButton = panel.querySelector('[data-close]');
        self.review = panel.querySelector('[data-review]');
        self.eta = panel.querySelector('[data-eta]');
        self.title = panel.querySelector('.altmonitor-dialog-head h2');
        self.titleText = self.title ? self.title.textContent : '';
        // The images (assetId-siteId) given a new suggestion this run, to update on Close.
        self.changed = [];
        self.stopped = false;
        self.running = false;
        self.finished = false;
        self.worker = null;
        self.loading = null;
        self.downloading = false;
        self.pending = {};
        self.nextId = 0;
        self.previewUrl = null;
        // Called instead of updating the page's changed parts when the popup is closed after a run.
        self.onFinish = null;

        self.stopButton.addEventListener('click', function() {
            self.stop();
        });

        if (self.closeButton) {
            self.closeButton.addEventListener('click', function() {
                self.close();
            });
        }

        // Esc stops a run first; once it has ended, it closes the popup.
        panel.addEventListener('cancel', function(event) {
            event.preventDefault();
            self.running ? self.stop() : self.close();
        });

        // ...and only that: in an asset editor slide-out, Craft would also close the slide-out.
        panel.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
            }
        });
    }

    Describer.prototype.open = function() {
        if (this.panel.tagName === 'DIALOG' && !this.panel.open) {
            this.panel.showModal();
        }

        this.panel.hidden = false;
    };

    // Shows a status and, with a number, the progress; with 'busy', a moving bar for work whose
    // length isn't known (starting a model from the browser's cache).
    Describer.prototype.say = function(text, percent) {
        var track = this.bar.parentNode;

        this.open();
        this.status.textContent = text;

        if (percent === 'busy') {
            track.classList.add('altmonitor-progress--busy');
            track.removeAttribute('aria-valuenow');
        } else if (typeof percent === 'number') {
            var wasBusy = track.classList.contains('altmonitor-progress--busy');
            track.classList.remove('altmonitor-progress--busy');
            this.setBar(percent, wasBusy);
        }
    };

    // `instant` skips the bar's animation, so a reset doesn't visibly slide back from before.
    Describer.prototype.setBar = function(percent, instant) {
        var bar = this.bar;

        if (instant) {
            bar.style.transition = 'none';
        }

        bar.style.width = percent + '%';
        bar.parentNode.setAttribute('aria-valuenow', Math.round(percent));

        if (instant) {
            void bar.offsetWidth;
            bar.style.transition = '';
        }
    };

    // Clears everything a previous run left (image, name, text, progress, time left) before the
    // popup opens, so it never shows old information, even for a moment.
    Describer.prototype.begin = function() {
        this.bar.parentNode.classList.remove('altmonitor-progress--busy');
        this.setBar(0, true);
        this.status.textContent = '';
        this.loadPercent = 0;
        this.clearPreview();

        if (this.name) {
            this.name.textContent = '';
        }

        if (this.result) {
            this.result.textContent = '';
            this.result.hidden = true;
        }

        this.estimate(null);
    };

    Describer.prototype.clearPreview = function() {
        if (this.preview) {
            this.preview.hidden = true;
            this.preview.removeAttribute('src');
        }

        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    };

    // Stops at once: the worker is ended, so the image being described is dropped, not stored.
    Describer.prototype.stop = function() {
        this.stopped = true;

        if (this.running) {
            this.say(Craft.t('alt-monitor', 'Stopping…'));
            this.reset(new Error('stopped'));
        }
    };

    // Closing after a run updates only what changed (the described images' rows, the image's
    // suggestion), never reloading, so the scroll position and anything typed stay.
    Describer.prototype.close = function() {
        var changed = this.changed;

        if (this.panel.tagName === 'DIALOG') {
            this.panel.close();
        }

        if (this.finished) {
            this.finished = false;
            this.changed = [];

            if (this.onFinish) {
                this.onFinish();
                return;
            }

            enableDescribeButtons();

            if (changed.length) {
                refreshAfterDescribing(changed);
            }
        }
    };

    // About how long the rest takes. Shown only when the number of images is known.
    Describer.prototype.estimate = function(milliseconds) {
        if (!this.eta) {
            return;
        }

        if (milliseconds === null) {
            this.eta.hidden = true;
            return;
        }

        this.eta.textContent = milliseconds < 60000
            ? Craft.t('alt-monitor', 'Less than a minute left')
            : Craft.t('alt-monitor', 'About {minutes, number} {minutes, plural, =1{minute} other{minutes}} left', {minutes: Math.ceil(milliseconds / 60000)});
        this.eta.hidden = false;
    };

    Describer.prototype.show = function(image, name, text) {
        if (this.preview && image) {
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = URL.createObjectURL(image);
            this.preview.src = this.previewUrl;
            this.preview.hidden = false;
        }

        if (this.name && name !== undefined) {
            this.name.textContent = name || '';
        }

        if (this.result) {
            this.result.textContent = text || '';
            this.result.className = 'altmonitor-dialog-result';
            this.result.hidden = !text;
        }
    };

    // What happened to the current image, shown under it; the popup keeps no list of past images.
    Describer.prototype.note = function(name, text, kind) {
        if (this.result) {
            this.result.textContent = text;
            this.result.className = 'altmonitor-dialog-result is-' + kind;
            this.result.hidden = !text;
        }
    };

    // The worker, created once, and its one message handler. Every request carries an id; its
    // answer (ready, description, translation or error) settles the matching promise.
    Describer.prototype.ensureWorker = function() {
        var self = this;

        if (self.worker) {
            return self.worker;
        }

        if (typeof WebAssembly !== 'object' || typeof Worker !== 'function') {
            throw new Error(Craft.t('alt-monitor', 'This browser can’t run the image model. Use a current version of Chrome, Edge, Firefox or Safari.'));
        }

        self.worker = createWorker(self.config.worker);
        self.worker.onmessage = function(event) {
            var message = event.data;

            if (message.type === 'progress') {
                // Reported too while a model is read from the browser's cache, which is no
                // download: only the image model page, which downloads, shows megabytes.
                if (!self.downloading) {
                    self.say(self.translating ? Craft.t('alt-monitor', 'Starting the translation model…') : Craft.t('alt-monitor', 'Starting the image model…'), 'busy');

                    return;
                }

                // Measured against the whole download, known in advance: the files start one
                // after another, so their own total grows, and the bar would fall back. It
                // never goes backwards, and reaches 100% only when the model is ready.
                var total = Math.max(message.total, (self.expectedMegabytes || 0) * 1e6);
                self.loadPercent = Math.max(self.loadPercent || 0, Math.min(99, message.loaded / total * 100));
                self.say(Craft.t('alt-monitor', self.downloading === 'translation' ? 'Downloading the translation model (once): {loaded} of {total} MB' : 'Downloading the image model (once): {loaded} of {total} MB', {
                    loaded: Math.min(Math.round(message.loaded / 1e6), Math.round(total / 1e6)),
                    total: Math.round(total / 1e6),
                }), self.loadPercent);
            } else if (message.type === 'translator') {
                self.status.textContent = Craft.t('alt-monitor', 'Starting the translation model…');
            } else if (message.type === 'trying') {
                self.status.textContent = Craft.t('alt-monitor', 'Already used. Trying a different description ({attempt, number})…', {attempt: message.attempt + 1});
            } else if (message.id !== null && message.id !== undefined && self.pending[message.id]) {
                var pending = self.pending[message.id];
                delete self.pending[message.id];

                if (message.type === 'error') {
                    pending.reject(new Error(message.message));
                } else {
                    pending.resolve(message);
                }
            } else if (message.type === 'error') {
                self.reset(new Error(message.message));
            }
        };
        // The worker itself died (e.g. out of memory): nothing it was doing will answer.
        self.worker.onerror = function(event) {
            self.reset(new Error(event.message || 'The image model stopped working.'));
        };

        return self.worker;
    };

    // Sends the worker a request; resolves to its answer.
    Describer.prototype.request = function(message) {
        var self = this;

        return new Promise(function(resolve, reject) {
            try {
                self.ensureWorker();
            } catch (error) {
                reject(error);
                return;
            }

            message.id = ++self.nextId;
            self.pending[message.id] = {resolve: resolve, reject: reject};
            self.worker.postMessage(message);
        });
    };

    // Loads the image model (only that: translation models are separate downloads).
    Describer.prototype.load = function() {
        var self = this;

        if (self.loading) {
            return self.loading;
        }

        self.expectedMegabytes = self.config.modelMegabytes || 0;
        self.loadPercent = 0;
        self.say(self.downloading ? Craft.t('alt-monitor', 'Loading the image model…') : Craft.t('alt-monitor', 'Starting the image model…'), self.downloading ? 0 : 'busy');
        self.loading = self.request({type: 'load', config: self.config, image: true, translations: []});
        self.loading.catch(function() {
            self.loading = null;
        });

        return self.loading;
    };

    // Loads (on the image model page: downloads) one translation model, without the image model.
    Describer.prototype.loadTranslation = function(model) {
        var translations = this.config.translations || {};
        var known = Object.keys(translations).map(function(siteId) {
            return translations[siteId];
        }).find(function(translation) {
            return translation.model === model;
        });

        this.downloading = 'translation';
        this.expectedMegabytes = known ? known.megabytes : 0;
        this.loadPercent = 0;
        this.say(Craft.t('alt-monitor', 'Starting the translation model…'), 0);

        return this.request({type: 'load', config: this.config, image: false, translations: [model]});
    };

    // Ends the worker, failing anything still waiting on it, so the next run starts afresh.
    Describer.prototype.reset = function(error) {
        var pending = this.pending;
        this.pending = {};
        this.loading = null;

        if (this.worker) {
            this.worker.terminate();
            this.worker = null;
        }

        Object.keys(pending).forEach(function(id) {
            pending[id].reject(error || new Error('The image model stopped working.'));
        });
    };

    // What this browser already wrote for an image, so describing it again doesn't go in circles.
    function history(assetId, siteId) {
        return recall('localStorage', 'history.' + assetId + '-' + siteId) || [];
    }

    function addToHistory(assetId, siteId, text) {
        var list = history(assetId, siteId).filter(function(known) {
            return known !== text;
        });
        list.push(text);
        remember('localStorage', 'history.' + assetId + '-' + siteId, list.slice(-8));
    }

    // Describes one image and stores the description, translated into the site's language when
    // this browser has downloaded that language's translation model (never downloaded here).
    // Resolves to 'described', 'same' (nothing new was found), 'stopped' or 'failed'.
    Describer.prototype.describe = function(assetId, siteId, label) {
        var self = this;
        var translation = (self.config.translations || {})[siteId] || null;

        if (translation && (self.downloadedTranslations || []).indexOf(translation.model) === -1) {
            translation = null;
        }

        // The worker died (it can run out of memory): the run stops, rather than fetching every
        // image left only to fail it.
        if (!self.worker) {
            return Promise.reject(new Error('The image model stopped working.'));
        }

        // The previous image and its text go while this one is fetched.
        self.clearPreview();
        self.show(null, label, '');
        var url = Craft.getActionUrl('alt-monitor/suggestions/image', {assetId: assetId, siteId: siteId});
        var known = [];
        var name = label;

        return fetch(url, {credentials: 'same-origin'}).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            try {
                known = JSON.parse(decodeURIComponent(response.headers.get('X-AltMonitor-Known') || '%5B%5D'));
                name = decodeURIComponent(response.headers.get('X-AltMonitor-Name') || '') || label;
            } catch (e) {}

            return response.blob();
        }).then(function(image) {
            if (!self.worker) {
                throw new Error('The image model stopped working.');
            }

            self.show(image, name, '');

            return self.request({type: 'describe', image: image, avoid: known.concat(history(assetId, siteId)), translate: translation ? translation.model : null});
        }).then(function(answer) {
            if (!answer.fresh) {
                self.note(name, Craft.t('alt-monitor', 'No new description found. It would repeat the current one.'), 'same');

                return 'same';
            }

            self.show(null, undefined, answer.text);

            return Craft.sendActionRequest('POST', 'alt-monitor/suggestions/store', {
                data: {assetId: assetId, siteId: siteId, text: answer.text, translated: answer.translated ? 1 : 0},
            }).then(function(response) {
                addToHistory(assetId, siteId, answer.text);

                if (!response.data.suggestion) {
                    self.note(name, Craft.t('alt-monitor', 'No new description found. It would repeat the current one.'), 'same');

                    return 'same';
                }

                self.note(name, '“' + response.data.suggestion.text + '”', 'described');

                return 'described';
            });
        }).catch(function(error) {
            if (self.stopped) {
                return 'stopped';
            }

            if (!self.worker) {
                throw error;
            }

            console.warn('AltMonitor could not describe asset ' + assetId + ':', error);
            self.note(name, Craft.t('alt-monitor', 'Couldn’t describe this one.'), 'failed');

            return 'failed';
        });
    };

    // Describes the given images, or (with none given) every image the server lists as wanting a
    // suggestion, one at a time.
    Describer.prototype.run = function(images) {
        var self = this;
        var counts = {described: 0, same: 0, failed: 0};
        var total = images ? images.length : null;
        var after = 0;
        // How long each image took, for the time left; about 10 seconds each until one is done.
        var times = [];
        var GUESS = 10000;

        // One run at a time: a second click must not undo a Stop or start a parallel walk.
        if (self.running) {
            return Promise.resolve();
        }

        self.running = true;
        self.stopped = false;
        self.finished = false;
        self.translating = false;
        self.changed = [];
        self.begin();

        if (self.title) {
            self.title.textContent = self.titleText;
        }

        self.stopButton.hidden = false;
        self.stopButton.disabled = false;

        if (self.closeButton) {
            self.closeButton.hidden = true;
        }

        if (self.review) {
            self.review.hidden = true;
        }

        function next() {
            if (images) {
                return Promise.resolve(images.splice(0, images.length));
            }

            return Craft.sendActionRequest('GET', 'alt-monitor/suggestions/next', {params: {after: after}}).then(function(response) {
                var batch = response.data.images;

                // The first answer says how many images there are in all.
                if (total === null && typeof response.data.total === 'number' && response.data.total > 0) {
                    total = response.data.total;
                }

                if (batch.length) {
                    after = batch[batch.length - 1].auditId;
                }

                return batch;
            });
        }

        function work(batch) {
            if (self.stopped || !batch.length) {
                return Promise.resolve();
            }

            var image = batch.shift();
            var done = counts.described + counts.same + counts.failed + 1;
            var started = Date.now();
            // An image the walk found after counting still gets described: the total grows.
            total = total !== null ? Math.max(total, done) : null;
            self.say(total
                ? Craft.t('alt-monitor', 'Describing image {done, number} of {total, number}…', {done: done, total: total})
                : Craft.t('alt-monitor', 'Describing image {done, number}…', {done: done}), total ? Math.max(4, (done - 1) / total * 100) : null);

            if (total) {
                var recent = times.slice(-5);
                var each = recent.length ? recent.reduce(function(sum, time) { return sum + time; }, 0) / recent.length : GUESS;
                self.estimate((total - done + 1) * each);
            }

            return self.describe(image.assetId, image.siteId, Craft.t('alt-monitor', 'Image {number, number}', {number: done})).then(function(outcome) {
                if (outcome !== 'stopped') {
                    counts[outcome]++;
                    times.push(Date.now() - started);
                }

                if (outcome === 'described') {
                    self.changed.push(image.assetId + '-' + image.siteId);
                }

                return work(batch);
            });
        }

        function loop() {
            return next().then(function(batch) {
                if (!batch.length || self.stopped) {
                    return;
                }

                return work(batch).then(function() {
                    return images ? undefined : loop();
                });
            });
        }

        function end(message) {
            self.running = false;
            self.finished = true;
            self.reset();
            self.say(message, 100);
            self.estimate(null);
            self.stopButton.hidden = true;

            if (self.closeButton) {
                self.closeButton.hidden = false;
                self.closeButton.focus();
            }

            if (self.review && counts.described > 0) {
                self.review.hidden = false;
                self.review.focus();
            }
        }

        function finished() {
            end(self.stopped
                ? Craft.t('alt-monitor', 'Stopped: {described, number} new, {same, number} unchanged, {failed, number} failed.', counts)
                : Craft.t('alt-monitor', 'Done: {described, number} new, {same, number} unchanged, {failed, number} failed.', counts));
        }

        return downloadedTranslations(self.config).then(function(models) {
            self.downloadedTranslations = models;

            // Stop was pressed while the browser's cache was being read: nothing is loaded.
            if (self.stopped) {
                throw new Error('Stopped.');
            }

            return self.load();
        }).then(function() {
            return loop().then(finished, function(error) {
                enableDescribeButtons();
                end(Craft.t('alt-monitor', 'Stopped because of an error: {message}', {message: error.message}));
            });
        }, function(error) {
            enableDescribeButtons();

            if (self.stopped) {
                end(Craft.t('alt-monitor', 'Stopped: {described, number} new, {same, number} unchanged, {failed, number} failed.', counts));

                return;
            }

            end(Craft.t('alt-monitor', 'The image model could not be loaded: {message}', {message: error.message}));
        });
    };

    // Translates English suggestions into their site's language: only their text, without the
    // image model. Each is replaced by its translation, still waiting for review. `items` are
    // {id (the suggestion's), key (assetId-siteId), text, model}.
    Describer.prototype.translateSuggestions = function(items) {
        var self = this;
        var counts = {translated: 0, failed: 0};
        var total = items.length;

        if (self.running) {
            return Promise.resolve();
        }

        self.running = true;
        self.stopped = false;
        self.finished = false;
        self.translating = true;
        self.changed = [];
        self.begin();

        if (self.title) {
            self.title.textContent = Craft.t('alt-monitor', 'Translating suggestions');
        }

        self.stopButton.hidden = false;
        self.stopButton.disabled = false;

        if (self.closeButton) {
            self.closeButton.hidden = true;
        }

        if (self.review) {
            self.review.hidden = true;
        }

        function work(index) {
            if (self.stopped || index >= total) {
                return Promise.resolve();
            }

            var item = items[index];
            self.say(Craft.t('alt-monitor', 'Translating suggestion {done, number} of {total, number}…', {done: index + 1, total: total}), index / total * 100);
            self.show(null, item.text, '');

            return self.request({type: 'translate', config: self.config, text: item.text, model: item.model}).then(function(answer) {
                return Craft.sendActionRequest('POST', 'alt-monitor/suggestions/translate', {data: {id: item.id, text: answer.text}});
            }).then(function(response) {
                counts.translated++;
                self.changed.push(item.key);
                self.note(null, '“' + response.data.suggestion.text + '”', 'described');
            }, function(error) {
                if (self.stopped) {
                    return;
                }

                counts.failed++;
                console.warn('AltMonitor could not translate suggestion ' + item.id + ':', error);
                self.note(null, Craft.t('alt-monitor', 'Could not be translated.'), 'failed');
            }).then(function() {
                return work(index + 1);
            });
        }

        return work(0).then(function() {
            self.running = false;
            self.finished = true;
            self.translating = false;
            self.reset();
            self.say(self.stopped
                ? Craft.t('alt-monitor', 'Stopped: {translated, number} translated, {failed, number} could not be translated.', counts)
                : Craft.t('alt-monitor', 'Done: {translated, number} translated, {failed, number} could not be translated.', counts), 100);
            self.stopButton.hidden = true;

            if (self.closeButton) {
                self.closeButton.hidden = false;
                self.closeButton.focus();
            }

            if (self.review && counts.translated > 0) {
                self.review.hidden = false;
            }
        });
    };

    function enableDescribeButtons() {
        document.querySelectorAll('[data-describe-all], [data-describe-one], [data-describe-selected], [data-translate-suggestion]').forEach(function(button) {
            button.disabled = false;
        });

        // Buttons that need a selection go back to following it.
        document.querySelectorAll('[data-altmonitor-selection]').forEach(function(form) {
            form.dispatchEvent(new Event('change'));
        });
    }

    // After describing: the page is fetched again and only its parts marked
    // data-altmonitor-refresh are swapped (on the images list, only the described images' rows).
    // What was typed and selected in them is kept. If that fails, the page reloads.
    function refreshAfterDescribing(keys) {
        if (!window.fetch || !window.DOMParser) {
            window.location.reload();
            return;
        }

        fetch(window.location.href, {credentials: 'same-origin', headers: {Accept: 'text/html'}}).then(function(response) {
            if (!response.ok) {
                throw new Error(response.statusText);
            }

            return response.text();
        }).then(function(html) {
            var fresh = new DOMParser().parseFromString(html, 'text/html');

            document.querySelectorAll('[data-altmonitor-refresh]').forEach(function(part) {
                var name = part.getAttribute('data-altmonitor-refresh');
                var key = part.getAttribute('data-key');

                if (key !== null && keys.indexOf(key) === -1) {
                    return;
                }

                var replacement = Array.prototype.find.call(fresh.querySelectorAll('[data-altmonitor-refresh]'), function(candidate) {
                    return candidate.getAttribute('data-altmonitor-refresh') === name;
                });

                if (replacement) {
                    swapPart(part, document.importNode(replacement, true));
                }
            });
        }).catch(function() {
            window.location.reload();
        });
    }

    function swapPart(part, replacement) {
        var focused = part.contains(document.activeElement) ? document.activeElement : null;

        // Text typed and boxes ticked stay as they were.
        part.querySelectorAll('textarea[name], input[name]').forEach(function(field) {
            var twin = Array.prototype.find.call(replacement.querySelectorAll('textarea[name], input[name]'), function(candidate) {
                return candidate.name === field.name && candidate.type === field.type && (field.type !== 'checkbox' || candidate.value === field.value);
            });

            if (!twin) {
                return;
            }

            if (field.type === 'checkbox') {
                twin.checked = field.checked;
            } else if (field.type !== 'hidden' && field.value !== field.defaultValue) {
                twin.value = field.value;
            }
        });

        part.replaceWith(replacement);
        initLists(replacement);

        if (window.jQuery && Craft.cp && Craft.cp.elementThumbLoader) {
            Craft.cp.elementThumbLoader.load(window.jQuery(replacement));
        }

        // Kept text is still unsaved: say so again (Save button, hints).
        replacement.querySelectorAll('textarea[name^="alt"], input[type="text"][name^="alt"]').forEach(function(field) {
            if (field.value !== field.defaultValue) {
                field.dispatchEvent(new Event('input', {bubbles: true}));
            }
        });

        if (focused && focused.name) {
            var again = replacement.querySelector('[name="' + focused.name.replace(/"/g, '\\"') + '"]');

            if (again) {
                again.focus();
            }
        }

        var form = replacement.closest('[data-altmonitor-selection]');

        if (form) {
            form.dispatchEvent(new Event('change'));
        }
    }

    // A module worker must come from the page's own origin. Where Craft publishes its resources
    // elsewhere (a CDN), a same-origin stub imports the worker from there instead.
    function createWorker(url) {
        var absolute = new URL(url, window.location.href);

        if (absolute.origin === window.location.origin) {
            return new Worker(absolute.href, {type: 'module'});
        }

        var stub = URL.createObjectURL(new Blob(['import ' + JSON.stringify(absolute.href) + ';'], {type: 'text/javascript'}));

        return new Worker(stub, {type: 'module'});
    }

    // Whether this browser already has the image model. Transformers.js keeps every file it downloads
    // in Cache Storage under its own URL; these are the files describe-worker.js loads (q8 dtype).
    var MODEL_CACHE = 'transformers-cache';
    var MODEL_FILES = [
        'config.json',
        'preprocessor_config.json',
        'tokenizer.json',
        'onnx/vision_encoder_quantized.onnx',
        'onnx/embed_tokens_quantized.onnx',
        'onnx/encoder_model_quantized.onnx',
        'onnx/decoder_model_merged_quantized.onnx',
    ];
    // The files of each translation model (Opus-MT, q8) the worker loads.
    var TRANSLATION_FILES = [
        'config.json',
        'generation_config.json',
        'tokenizer.json',
        'tokenizer_config.json',
        'onnx/encoder_model_quantized.onnx',
        'onnx/decoder_model_merged_quantized.onnx',
    ];

    // The translation models this site's languages need, each once.
    function translationModels(config) {
        var models = [];

        Object.keys(config.translations || {}).forEach(function(siteId) {
            var model = config.translations[siteId].model;

            if (models.indexOf(model) === -1) {
                models.push(model);
            }
        });

        return models;
    }

    function cachedModelFiles(config) {
        return caches.open(MODEL_CACHE).then(function(cache) {
            return cache.keys().then(function(requests) {
                return {cache: cache, requests: requests};
            });
        });
    }

    // Resolves to 'ready', 'missing', 'no-cache' (the browser can't keep files for this site) or
    // 'unsupported' (the browser can't run the model at all).
    function modelStatus(config) {
        if (typeof WebAssembly !== 'object' || typeof Worker !== 'function') {
            return Promise.resolve('unsupported');
        }

        if (!window.isSecureContext || !window.caches) {
            return Promise.resolve('no-cache');
        }

        return cachedUrls().then(function(urls) {
            return hasFiles(urls, config.model, MODEL_FILES) ? 'ready' : 'missing';
        }).catch(function() {
            return 'no-cache';
        });
    }

    function cachedUrls() {
        return cachedModelFiles().then(function(found) {
            return found.requests.map(function(request) {
                return request.url;
            });
        });
    }

    function hasFiles(urls, model, files) {
        return files.every(function(file) {
            var path = '/' + model + '/resolve/main/' + file;

            return urls.some(function(url) {
                return url.slice(-path.length) === path;
            });
        });
    }

    // Whether this browser has one translation model (a separate download from the image model):
    // its files, and the runtime, which it needs too.
    function translationStatus(config, model) {
        if (typeof WebAssembly !== 'object' || typeof Worker !== 'function') {
            return Promise.resolve('unsupported');
        }

        if (!window.isSecureContext || !window.caches) {
            return Promise.resolve('no-cache');
        }

        return cachedUrls().then(function(urls) {
            return hasFiles(urls, model, TRANSLATION_FILES) && urls.some(function(url) {
                return isRuntimeFile(config, url);
            }) ? 'ready' : 'missing';
        }).catch(function() {
            return 'no-cache';
        });
    }

    // The translation models this browser has, of those the sites need.
    function downloadedTranslations(config) {
        if (!window.isSecureContext || !window.caches) {
            return Promise.resolve([]);
        }

        return cachedUrls().then(function(urls) {
            return translationModels(config).filter(function(model) {
                return hasFiles(urls, model, TRANSLATION_FILES);
            });
        }).catch(function() {
            return [];
        });
    }

    // The runtime's files, which the image model and the translation models share.
    function isRuntimeFile(config, url) {
        return url.indexOf('/onnxruntime-web') !== -1 || Boolean(config.wasmPaths && url.indexOf(config.wasmPaths) === 0);
    }

    // The image model's files and the runtime's, which together are what its download fetched.
    function isModelFile(config, url) {
        return url.indexOf('/' + config.model + '/') !== -1 || isRuntimeFile(config, url);
    }

    function modelSize(config) {
        return cachedModelFiles(config).then(function(found) {
            return Promise.all(found.requests.filter(function(request) {
                return isModelFile(config, request.url);
            }).map(function(request) {
                return found.cache.match(request).then(function(response) {
                    return parseInt(response && response.headers.get('content-length'), 10) || 0;
                });
            }));
        }).then(function(sizes) {
            return sizes.reduce(function(sum, size) {
                return sum + size;
            }, 0);
        });
    }

    // Removes the image model, or (with `model`) one translation model; the runtime goes too once
    // no model left in this browser needs it.
    function removeModel(config, model) {
        var inModel = function(url) {
            return url.indexOf('/' + (model || config.model) + '/') !== -1;
        };

        return cachedModelFiles(config).then(function(found) {
            var left = found.requests.filter(function(request) {
                return !inModel(request.url) && !isRuntimeFile(config, request.url);
            }).map(function(request) {
                return request.url;
            });
            var runtimeNeeded = [config.model].concat(translationModels(config)).some(function(other) {
                return left.some(function(url) {
                    return url.indexOf('/' + other + '/') !== -1;
                });
            });

            return Promise.all(found.requests.filter(function(request) {
                return inModel(request.url) || (!runtimeNeeded && isRuntimeFile(config, request.url));
            }).map(function(request) {
                return found.cache.delete(request);
            }));
        });
    }

    // Whether describing or translating must first ask on the image model page: when the model
    // isn't here, and also when this browser can't keep it (http://), where it would be downloaded
    // now. Once agreed to there, `notKept` stops asking again (checked by the callers).
    function needsConsent(status) {
        return status === 'missing' || status === 'no-cache';
    }

    // Small per-browser notes; storage can be unavailable (private windows), which is fine.
    function remember(storage, key, value) {
        try {
            value === null ? window[storage].removeItem('altmonitor.' + key) : window[storage].setItem('altmonitor.' + key, JSON.stringify(value));
        } catch (e) {}
    }

    function recall(storage, key) {
        try {
            return JSON.parse(window[storage].getItem('altmonitor.' + key));
        } catch (e) {
            return null;
        }
    }

    function describers() {
        var panel = document.querySelector('[data-altmonitor-describer]');

        return panel ? (panel.altmonitor = panel.altmonitor || new Describer(panel)) : null;
    }

    // Describes images, but first sends anyone whose browser doesn't have the model to the image
    // model page, which explains the download and asks before starting it. Once they have it,
    // they come back here and what they asked for continues.
    function describe(images, button) {
        var describer = describers();

        if (button) {
            button.disabled = true;
        }

        return modelStatus(describer.config).then(function(status) {
            if (needsConsent(status) && !recall('localStorage', 'notKept') && describer.config.setupUrl) {
                remember('sessionStorage', 'pending', {url: window.location.href, images: images});
                var setup = new URL(describer.config.setupUrl, window.location.href);
                setup.searchParams.set('return', window.location.href);
                window.location.href = setup.href;

                return;
            }

            return describer.run(images);
        });
    }

    // Translate buttons translate an English suggestion's text. The language's translation model
    // must be in this browser first: if it isn't, the image model page asks before downloading
    // it (that language only), then comes back here (`back`), where the translation runs once.
    function translate(describer, items, button, back, pendingExtra) {
        var translation = (describer.config.translations || {})[items[0].siteId];

        if (!translation) {
            return Promise.resolve();
        }

        items.forEach(function(item) {
            item.model = translation.model;
        });

        if (button) {
            button.disabled = true;
        }

        return translationStatus(describer.config, translation.model).then(function(status) {
            if (needsConsent(status) && !recall('localStorage', 'notKept.' + translation.model) && describer.config.setupUrl) {
                remember('sessionStorage', 'pending', Object.assign({url: window.location.href, translate: items}, pendingExtra || {}));
                var setup = new URL(describer.config.setupUrl, window.location.href);
                setup.searchParams.set('return', back || window.location.href);
                setup.searchParams.set('translation', translation.language);
                setup.hash = 'altmonitor-translations-heading';
                window.location.href = setup.href;

                return;
            }

            return describer.translateSuggestions(items);
        });
    }

    function translateItem(button) {
        var key = button.getAttribute('data-key');

        return {
            id: parseInt(button.getAttribute('data-translate-suggestion'), 10),
            key: key,
            siteId: keyToImage(key).siteId,
            text: button.getAttribute('data-text'),
        };
    }

    function keyToImage(key) {
        var parts = key.split('-');

        return {assetId: parseInt(parts[0], 10), siteId: parseInt(parts[1], 10)};
    }

    // The Describe buttons; set up once each, also in parts of the page updated in place.
    function describeButtons(root) {
        var fresh = function(button) {
            if (button.altmonitorBound) {
                return false;
            }

            button.altmonitorBound = true;

            return true;
        };

        root.querySelectorAll('[data-describe-all]').forEach(function(button) {
            fresh(button) && button.addEventListener('click', function() {
                describe(null, button);
            });
        });

        root.querySelectorAll('[data-describe-one]').forEach(function(button) {
            fresh(button) && button.addEventListener('click', function() {
                describe([keyToImage(button.getAttribute('data-describe-one'))], button);
            });
        });

        root.querySelectorAll('[data-describe-selected]').forEach(function(button) {
            fresh(button) && describeSelected(button);
        });

        // The asset editor's sidebar box sets up its own.
        root.querySelectorAll('[data-translate-suggestion]').forEach(function(button) {
            !button.closest('[data-altmonitor-sidebar]') && fresh(button) && button.addEventListener('click', function() {
                var describer = describers();

                if (describer) {
                    translate(describer, [translateItem(button)], button);
                }
            });
        });
    }

    function describeSelected(button) {
        button.addEventListener('click', function() {
            var form = button.closest('form');
            var keys = Array.prototype.map.call(form.querySelectorAll('input[name="selected[]"]:checked'), function(box) {
                return box.value;
            });

            if (!keys.length) {
                Craft.cp.displayError(Craft.t('alt-monitor', 'Select at least one image.'));
                return;
            }

            describe(keys.map(keyToImage), button);
        });
    }

    // Back from the image model page: continue what was asked for, once, if the model is now here.
    (function resumePending() {
        var pending = recall('sessionStorage', 'pending');
        var describer = describers();

        // A request from an asset editor's sidebar box is picked up by that box.
        if (!pending || !describer || pending.sidebar) {
            return;
        }

        remember('sessionStorage', 'pending', null);

        if (pending.url !== window.location.href) {
            return;
        }

        if (pending.translate) {
            var model = pending.translate[0].model;

            translationStatus(describer.config, model).then(function(status) {
                if (status === 'ready' || recall('localStorage', 'notKept.' + model)) {
                    describer.translateSuggestions(pending.translate);
                }
            });

            return;
        }

        modelStatus(describer.config).then(function(status) {
            if (status === 'ready' || recall('localStorage', 'notKept')) {
                document.querySelectorAll('[data-describe-all], [data-describe-one], [data-describe-selected]').forEach(function(button) {
                    button.disabled = true;
                });
                describer.run(pending.images);
            }
        });
    })();

    // The image model page.
    function ModelPage(root) {
        this.root = root;
        this.config = JSON.parse(root.getAttribute('data-config'));
        this.returnUrl = root.getAttribute('data-return');
        this.consent = root.querySelector('[data-consent]');
        this.agree = root.querySelector('[data-agree]');
        this.download = root.querySelector('[data-download]');
        this.describer = null;
        this.translationAgree = root.querySelector('[data-translation-agree]');
        root.querySelectorAll('[data-translation]').forEach(this.translationRow.bind(this));

        // Without the image model's part (images are described with Ollama), only translations.
        if (!this.consent) {
            return;
        }

        this.agree.addEventListener('change', function() {
            this.download.disabled = !this.agree.checked;
        }.bind(this));
        this.download.addEventListener('click', this.start.bind(this));
        root.querySelector('[data-model-state="downloading"] [data-stop]').addEventListener('click', this.cancel.bind(this));
        root.querySelector('[data-remove]').addEventListener('click', this.remove.bind(this));
        this.warnAboutConnection();
        this.check();
    }

    ModelPage.prototype.translationRow = function(row) {
        var self = this;
        var model = row.getAttribute('data-translation');
        var name = row.querySelector('th').textContent.trim();
        var panel = row.querySelector('[data-translation-state="downloading"]');
        var download = row.querySelector('[data-translation-download]');
        var error = row.querySelector('[data-translation-error]');
        var describer = null;

        panel.setAttribute('data-config', JSON.stringify(self.config));

        function show(state, message) {
            row.querySelectorAll('[data-translation-state]').forEach(function(element) {
                element.hidden = element.getAttribute('data-translation-state') !== state;
            });
            error.textContent = message || '';
            error.hidden = !message;
            download.disabled = !self.translationAgree.checked;
        }

        function check() {
            return translationStatus(self.config, model).then(function(status) {
                if (status === 'ready') {
                    remember('localStorage', 'notKept.' + model, null);
                    show('ready');
                } else if (status === 'missing') {
                    show('missing');
                } else if (status === 'no-cache') {
                    // It can still be downloaded, for now: it won't be kept, so say so.
                    show('missing', Craft.t('alt-monitor', 'Browsers only keep downloaded files for sites opened over HTTPS.'));
                } else {
                    show(null, Craft.t('alt-monitor', 'This browser can’t run the image model. Use a current version of Chrome, Edge, Firefox or Safari.'));
                }

                return status;
            });
        }

        self.translationAgree.addEventListener('change', function() {
            download.disabled = !self.translationAgree.checked;
        });

        download.addEventListener('click', function() {
            if (!self.translationAgree.checked) {
                return;
            }

            show('downloading');
            var current = describer = new Describer(panel);
            current.begin();

            current.loadTranslation(model).then(function() {
                current.reset();

                if (describer !== current) {
                    return;
                }

                describer = null;

                return translationStatus(self.config, model).then(function(status) {
                    var next = self.root.querySelector('[data-translation-continue]');

                    if (status !== 'ready') {
                        // It loaded but wasn't kept: don't keep sending people here for it. Going
                        // back still translates (loading it again there), as with the image model.
                        remember('localStorage', 'notKept.' + model, true);
                        show('not-kept');
                    } else {
                        remember('localStorage', 'notKept.' + model, null);
                        show('ready');
                        Craft.cp.displayNotice(Craft.t('alt-monitor', 'The {language} translation model is ready.', {language: name}));
                    }

                    if (next && row.hasAttribute('data-wanted')) {
                        next.hidden = false;
                        next.focus();
                    }
                });
            }, function(failure) {
                if (describer !== current) {
                    return;
                }

                describer = null;
                show('missing', Craft.t('alt-monitor', 'The translation model could not be loaded: {message}', {message: failure.message}));
            });
        });

        panel.querySelector('[data-stop]').addEventListener('click', function() {
            if (describer) {
                describer.reset();
                describer = null;
            }

            check();
        });

        row.querySelector('[data-translation-remove]').addEventListener('click', function() {
            if (!window.confirm(Craft.t('alt-monitor', 'Remove the {language} translation model from this browser?', {language: name}))) {
                return;
            }

            removeModel(self.config, model).then(function() {
                Craft.cp.displayNotice(Craft.t('alt-monitor', 'The {language} translation model was removed from this browser.', {language: name}));
                check();
            });
        });

        check().then(function() {
            if (row.hasAttribute('data-wanted')) {
                row.scrollIntoView({block: 'center'});
            }
        });
    };

    ModelPage.prototype.show = function(state) {
        this.root.querySelectorAll('[data-model-state]').forEach(function(element) {
            element.hidden = element.getAttribute('data-model-state') !== state;
        });
        // The go-ahead is only asked for when there is something to download.
        this.consent.hidden = ['missing', 'error', 'no-cache'].indexOf(state) === -1;
        this.download.disabled = !this.agree.checked;
    };

    ModelPage.prototype.check = function() {
        var self = this;

        return modelStatus(self.config).then(function(status) {
            self.show(status);

            if (status === 'ready') {
                remember('localStorage', 'notKept', null);
                self.describeReady();
            }

            return status;
        });
    };

    ModelPage.prototype.describeReady = function() {
        var details = this.root.querySelector('[data-ready-details]');
        var downloaded = recall('localStorage', 'downloadedAt');
        var parts = [];

        if (downloaded) {
            parts.push(Craft.t('alt-monitor', 'Downloaded on {date}.', {date: new Date(downloaded).toLocaleString()}));
        }

        details.textContent = parts.join(' ');
        modelSize(this.config).then(function(bytes) {
            if (bytes) {
                parts.push(Craft.t('alt-monitor', 'Uses about {size} MB of this browser’s storage.', {size: Math.round(bytes / 1e6)}));
                details.textContent = parts.join(' ');
            }
        }).catch(function() {});
    };

    ModelPage.prototype.start = function() {
        var self = this;

        if (!self.agree.checked) {
            return;
        }

        self.show('downloading');
        self.describer = new Describer(self.root.querySelector('[data-model-state="downloading"]'));
        self.describer.downloading = true;
        self.describer.begin();
        var describer = self.describer;

        describer.load().then(function() {
            describer.reset();

            if (self.describer !== describer) {
                return;
            }

            self.describer = null;
            remember('localStorage', 'downloadedAt', Date.now());

            return modelStatus(self.config).then(function(status) {
                if (status === 'ready') {
                    remember('localStorage', 'notKept', null);
                    Craft.cp.displayNotice(Craft.t('alt-monitor', 'The image model is ready.'));
                    self.show('ready');
                    self.describeReady();
                    var next = self.root.querySelector('[data-model-state="ready"] [data-continue]');

                    if (next) {
                        next.focus();
                    }
                } else {
                    // It loaded but wasn't kept (private window, storage blocked or full): don't
                    // keep sending people here, as downloading again wouldn't help.
                    remember('localStorage', 'notKept', true);
                    // This state has Continue describing, so the person can go back to their images.
                    self.show('not-kept');
                }
            });
        }, function(error) {
            if (self.describer !== describer) {
                return;
            }

            self.describer = null;
            self.root.querySelector('[data-error]').textContent = Craft.t('alt-monitor', 'The image model could not be loaded: {message}', {message: error.message});
            self.show('error');
        });
    };

    ModelPage.prototype.cancel = function() {
        if (this.describer) {
            this.describer.reset();
            this.describer = null;
        }

        this.check();
    };

    ModelPage.prototype.remove = function() {
        var self = this;

        if (!window.confirm(Craft.t('alt-monitor', 'Remove the image model from this browser? It downloads again the next time someone describes images here.'))) {
            return;
        }

        removeModel(self.config).then(function() {
            remember('localStorage', 'downloadedAt', null);
            Craft.cp.displayNotice(Craft.t('alt-monitor', 'The image model was removed from this browser.'));

            return self.check();
        });
    };

    ModelPage.prototype.warnAboutConnection = function() {
        var root = this.root;
        var connection = navigator.connection;
        // About 400 MB for the image model.
        var needed = 400e6;

        if (connection && (connection.saveData || connection.type === 'cellular')) {
            root.querySelector('[data-metered]').hidden = false;
        }

        if (navigator.storage && navigator.storage.estimate) {
            navigator.storage.estimate().then(function(estimate) {
                if (estimate.quota && estimate.quota - (estimate.usage || 0) < needed) {
                    root.querySelector('[data-low-storage]').hidden = false;
                }
            }).catch(function() {});
        }
    };


    // Filters apply as soon as one is chosen; search applies on Enter, or when it's cleared with ✕.
    function autoFilter(form) {
        form.querySelectorAll('select').forEach(function(select) {
            select.addEventListener('change', function() {
                form.submit();
            });
        });
        form.querySelectorAll('input[type="search"]').forEach(function(input) {
            var initial = input.value;

            input.addEventListener('search', function() {
                if (input.value === '' && initial !== '') {
                    form.submit();
                }
            });
        });
    }

    // Lists with checkboxes: say how many are selected, and only enable bulk buttons when some are.
    // While some are (or, where the bar can save them, text is typed), the bar is pinned to the
    // bottom of the screen, so its buttons are at hand however long the list.
    function selection(form) {
        var count = form.querySelector('[data-selected-count]');
        var idle = count ? count.textContent : '';
        var bar = form.querySelector('.altmonitor-bulk');
        var savesTyped = bar && bar.querySelector('[data-save-changed]');

        if (count) {
            count.setAttribute('role', 'status');
        }

        function typed() {
            return Array.prototype.some.call(form.querySelectorAll('textarea[name^="alt"], input[type="text"][name^="alt"]'), function(field) {
                return field.isConnected && field.value !== field.defaultValue;
            });
        }

        function pin(selected) {
            if (bar) {
                bar.classList.toggle('is-pinned', selected > 0 || (!!savesTyped && typed()));
            }
        }

        form.addEventListener('input', function() {
            pin(form.querySelectorAll('input[data-select]:checked').length);
        });

        function update() {
            var selected = form.querySelectorAll('input[data-select]:checked').length;
            var selectable = form.querySelectorAll('input[data-select]:not(:disabled)').length;
            var all = form.querySelector('[data-altmonitor-select-all]');

            if (all) {
                all.checked = selectable > 0 && selected === selectable;
                all.indeterminate = selected > 0 && selected < selectable;
            }

            if (count) {
                count.textContent = selected ? Craft.t('alt-monitor', '{count, number} selected', {count: selected}) : idle;
            }

            form.querySelectorAll('[data-needs-selection]').forEach(function(button) {
                button.disabled = selected === 0;
            });

            pin(selected);
        }

        form.addEventListener('change', update);
        update();
    }

    // Forms where alt text is typed (the lists and the image page): typed text isn't lost by
    // leaving the page by accident. The images list has one way to save: “Save changes (n)”,
    // shown while rows are changed, saves them in place (Enter saves the row it is typed in),
    // and the browser asks before leaving while something typed is neither saved nor being saved.
    var unsavedForms = [];

    function unsavedChanges(form) {
        var saveChanged = form.querySelector('[data-save-changed]');
        var handled = [];

        function fields() {
            return Array.prototype.slice.call(form.querySelectorAll('textarea[name^="alt"], input[type="text"][name^="alt"]'));
        }

        function isChanged(field) {
            return field.value !== field.defaultValue;
        }

        function rowOf(field) {
            return field.closest('tr');
        }

        function update() {
            var changed = fields().filter(isChanged);

            // A field that failed to save stops being marked once it is back to its saved text.
            fields().forEach(function(field) {
                if (!isChanged(field)) {
                    field.classList.remove('error');
                }
            });

            if (saveChanged) {
                saveChanged.hidden = changed.length === 0;
                saveChanged.textContent = Craft.t('alt-monitor', 'Save changes ({count, number})', {count: changed.length});
            }

            handled = [];
        }

        // Which typed fields the submission in progress saves.
        function saving(submitter) {
            var intent = submitter && submitter.name === 'intent' ? submitter.value : null;

            if (intent && intent !== 'save') {
                return [];
            }

            // “Save changes” saves every changed row, selected or not.
            if (saveChanged && (!submitter || submitter === saveChanged)) {
                return fields();
            }

            if (form.querySelector('input[data-select]')) {
                return fields().filter(function(field) {
                    var box = rowOf(field) && rowOf(field).querySelector('input[data-select]');
                    return box && box.checked;
                });
            }

            return fields();
        }

        if (saveChanged && window.Craft && Craft.sendActionRequest) {
            // Saves the changed rows in place, one after another, so the scroll position, the
            // selection and rows typed meanwhile stay. Without JavaScript the form is submitted
            // and the server saves the rows whose text differs from what the page showed.
            saveChanged.addEventListener('click', function(event) {
                event.preventDefault();

                if (saveChanged.classList.contains('loading')) {
                    return;
                }

                var changed = fields().filter(isChanged);
                var saved = 0;
                var failed = [];

                if (!changed.length) {
                    return;
                }

                saveChanged.classList.add('loading');

                changed.reduce(function(previous, field) {
                    return previous.then(function() {
                        return saveField(field).then(function() {
                            saved++;
                        }, function(message) {
                            failed.push(message);
                        });
                    });
                }, Promise.resolve()).then(function() {
                    saveChanged.classList.remove('loading');

                    if (saved) {
                        Craft.cp.displayNotice(Craft.t('alt-monitor', 'Alt text saved for {count, number} {count, plural, =1{image} other{images}}.', {count: saved}));
                    }

                    if (failed.length === 1) {
                        Craft.cp.displayError(failed[0]);
                    } else if (failed.length) {
                        Craft.cp.displayError(Craft.t('alt-monitor', '{failed, number} {failed, plural, =1{image} other{images}} could not be updated.', {failed: failed.length}));
                    }
                });
            });
        }

        // Enter saves the row it is typed in (it would otherwise submit the whole form).
        form.addEventListener('keydown', function(event) {
            var field = event.target;

            if (event.key !== 'Enter' || !saveChanged || field.tagName !== 'INPUT' || fields().indexOf(field) === -1) {
                return;
            }

            if (!window.Craft || !Craft.sendActionRequest) {
                return;
            }

            event.preventDefault();

            if (!isChanged(field) || field.getAttribute('aria-busy') === 'true') {
                return;
            }

            saveField(field).then(function(message) {
                Craft.cp.displayNotice(message);
            }, function(message) {
                Craft.cp.displayError(message);
            });
        });

        form.addEventListener('input', update);
        form.addEventListener('submit', function(event) {
            handled = saving(event.submitter);
        });

        form.altmonitorUnsaved = function() {
            return fields().filter(function(field) {
                return field.isConnected && isChanged(field) && handled.indexOf(field) === -1;
            }).length;
        };

        unsavedForms.push(form);
        update();
    }

    window.addEventListener('beforeunload', function(event) {
        var unsaved = unsavedForms.some(function(form) {
            return form.altmonitorUnsaved() > 0;
        });

        if (unsaved) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // Approves or rejects one suggestion without leaving the page.
    function decide(button, action, data, done) {
        button.classList.add('loading');
        button.disabled = true;

        return Craft.sendActionRequest('POST', 'alt-monitor/suggestions/' + action, {data: data}).then(function(response) {
            Craft.cp.displayNotice(response.data.message);
            done(response.data);
        }).catch(function(error) {
            var message = error.response && error.response.data && error.response.data.message;
            Craft.cp.displayError(message || (action === 'approve'
                ? Craft.t('alt-monitor', 'The suggestion could not be approved.')
                : Craft.t('alt-monitor', 'The suggestion could not be rejected.')));
            button.disabled = false;
        }).finally(function() {
            button.classList.remove('loading');
        });
    }

    // Alt text fields: while text is typed, the hints a scan would give for it, from the server's
    // analyzer (the one place alt text is graded), shown under the field. Nothing is saved. The
    // hints go away when the text is back to what is saved, whose status the page already shows.
    function liveCheck(field) {
        var match = /^(\d+)-(\d+)$/.exec(field.getAttribute('data-altmonitor-check') || '');
        var hint = document.createElement('div');
        var timer = null;
        var sequence = 0;

        if (!match || !window.Craft || !Craft.sendActionRequest) {
            return;
        }

        hint.className = 'altmonitor-live';
        hint.setAttribute('aria-live', 'polite');
        hint.hidden = true;
        field.insertAdjacentElement('afterend', hint);

        function show(data) {
            hint.textContent = '';

            var prefix = document.createElement('span');
            prefix.className = 'visually-hidden';
            prefix.textContent = Craft.t('alt-monitor', 'As typed:');
            hint.appendChild(prefix);

            if (data.status) {
                var badge = document.createElement('span');
                var dot = document.createElement('span');
                var label = document.createElement('span');
                badge.className = 'status-label ' + data.status.color;
                badge.setAttribute('data-live-status', data.status.value);
                dot.className = 'status';
                label.className = 'status-label-text';
                label.textContent = data.status.label;
                badge.appendChild(dot);
                badge.appendChild(label);
                hint.appendChild(badge);
            }

            (data.issues || []).forEach(function(text) {
                var issue = document.createElement('span');
                issue.className = 'altmonitor-live-issue';
                issue.textContent = text;
                hint.appendChild(issue);
            });

            hint.hidden = false;
        }

        function check() {
            var mine = ++sequence;

            if (field.value === field.defaultValue) {
                hint.hidden = true;
                return;
            }

            Craft.sendActionRequest('POST', 'alt-monitor/assets/check', {
                data: {assetId: match[1], siteId: match[2], alt: field.value},
            }).then(function(response) {
                // Only the answer for the latest text counts.
                if (mine === sequence && field.value !== field.defaultValue) {
                    show(response.data);
                }
            }).catch(function() {
                if (mine === sequence) {
                    hint.hidden = true;
                }
            });
        }

        field.addEventListener('input', function() {
            clearTimeout(timer);

            if (field.value === field.defaultValue) {
                sequence++;
                hint.hidden = true;
                return;
            }

            timer = setTimeout(check, 400);
        });
    }

    // Images list: a row's status badge and issues after its alt text changed.
    function showRowStatus(row, status, issues) {
        var badge = row.querySelector('[data-alt-status]');

        if (!badge || !status) {
            return;
        }

        badge.className = 'status-label ' + status.color;
        badge.setAttribute('data-alt-status', status.value);
        badge.querySelector('.status-label-text').textContent = status.label;
        badge.parentNode.querySelectorAll('.smalltext').forEach(function(issue) {
            issue.remove();
        });
        issues.forEach(function(label) {
            var issue = document.createElement('span');
            issue.className = 'smalltext light';
            issue.textContent = label;
            badge.parentNode.appendChild(issue);
        });
    }

    // Images list: saves one row's alt text in place, like “Use”, so the scroll position and what
    // is typed in other rows stay, then shows the row's new status and issues. Resolves with the
    // server's message; rejects with the reason, leaving the field marked as unsaved.
    function saveField(field) {
        var row = field.closest('tr');
        var match = /^alt\[(\d+)-(\d+)\]$/.exec(field.name);

        if (!row || !match) {
            return Promise.reject(Craft.t('alt-monitor', 'The alt text could not be saved.'));
        }

        var sent = field.value;
        var original = row.querySelector('input[type="hidden"][name^="original["]');
        field.setAttribute('aria-busy', 'true');
        field.classList.remove('error');

        return Craft.sendActionRequest('POST', 'alt-monitor/assets/save', {
            data: {assetId: match[1], siteId: match[2], alt: sent},
        }).then(function(response) {
            var data = response.data;
            var saved = typeof data.alt === 'string' ? data.alt : sent;

            // Text typed while saving stays, still marked as unsaved.
            if (field.value === sent) {
                field.value = saved;
            }
            field.defaultValue = saved;
            if (original) {
                original.value = saved;
            }
            field.dispatchEvent(new Event('input', {bubbles: true}));

            showRowStatus(row, data.status, data.issues || []);

            var suggestion = row.querySelector('[data-suggestion]');
            if (suggestion && data.hasSuggestion === false) {
                suggestion.remove();
            }

            return data.message;
        }, function(error) {
            var message = error.response && error.response.data && error.response.data.message;
            field.classList.add('error');
            throw message || Craft.t('alt-monitor', 'The alt text could not be saved.');
        }).finally(function() {
            field.removeAttribute('aria-busy');
        });
    }

    // Images list: “Use” approves the suggestion as it is and puts it in the row's field.
    function useSuggestion(button) {
        button.addEventListener('click', function() {
            var row = button.closest('tr');
            var text = button.getAttribute('data-text');

            decide(button, 'approve', {id: button.getAttribute('data-use-suggestion'), alt: text}, function(data) {
                var input = row.querySelector('input[name^="alt["]');

                showRowStatus(row, data.status, []);

                if (input) {
                    // Saved with the approval, so it isn't an unsaved change.
                    input.value = text;
                    input.defaultValue = text;
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                }

                var box = button.closest('[data-suggestion]');
                box.classList.add('is-used');
                box.textContent = Craft.t('alt-monitor', 'Approved and saved to the image.');
            });
        });
    }

    // Suggestions page: approve (with the edited text) or reject a row, which then leaves the
    // list. Focus moves on to the next row, so a page can be reviewed from the keyboard, and when
    // the last row has left, the next suggestions load in place.
    function reviewRow(button) {
        var approve = button.hasAttribute('data-approve');

        button.addEventListener('click', function() {
            var row = button.closest('tr');
            var id = button.getAttribute(approve ? 'data-approve' : 'data-reject');
            var field = row.querySelector('textarea');
            var data = approve ? {id: id, alt: field ? field.value : ''} : {id: id};
            var hadFocus = row.contains(document.activeElement);

            decide(button, approve ? 'approve' : 'reject', data, function() {
                var counter = document.querySelector('[data-pending-count]');
                var next = nextReviewRow(row);

                if (counter) {
                    counter.textContent = Math.max(0, parseInt(counter.textContent.replace(/\D/g, ''), 10) - 1);
                }

                row.classList.add('is-leaving');

                if (hadFocus && next) {
                    focusReviewRow(next);
                }

                setTimeout(function() {
                    var body = row.parentNode;
                    var form = row.closest('form');
                    row.remove();

                    // Its box may have been ticked: the count and the bar follow.
                    if (form) {
                        form.dispatchEvent(new Event('change'));
                    }

                    if (body && !body.querySelector('tr')) {
                        loadNextSuggestions();
                    }
                }, 300);
            });
        });
    }

    // The row to review after this one: the next still in the list, else the one before.
    function nextReviewRow(row) {
        var staying = function(other) {
            return other && !other.classList.contains('is-leaving');
        };
        var next = row.nextElementSibling;

        while (next && !staying(next)) {
            next = next.nextElementSibling;
        }

        if (next) {
            return next;
        }

        var previous = row.previousElementSibling;

        while (previous && !staying(previous)) {
            previous = previous.previousElementSibling;
        }

        return previous;
    }

    function focusReviewRow(row) {
        // The text to edit first, else the Approve button, else the image's link.
        var target = row.querySelector('textarea') || row.querySelector('[data-approve]') || row.querySelector('a[href]');

        if (target) {
            target.focus();
        }
    }

    // Suggestions page shortcuts, anywhere in a row: Ctrl/⌘ + Enter approves it (with the edited
    // text), Esc rejects it. Both move on to the next row.
    function reviewKeys(root) {
        root.addEventListener('keydown', function(event) {
            if (event.defaultPrevented || event.isComposing || event.altKey || event.shiftKey || !event.target.closest) {
                return;
            }

            var row = event.target.closest('#altmonitor-suggestions tbody tr');
            var target = null;

            if (!row || row.classList.contains('is-leaving')) {
                return;
            }

            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                target = row.querySelector('[data-approve]');
            } else if (event.key === 'Escape' && !event.ctrlKey && !event.metaKey) {
                target = row.querySelector('[data-reject]');
            }

            if (target) {
                event.preventDefault();
                event.stopPropagation();

                if (!target.disabled) {
                    target.click();
                }
            }
        });
    }

    // Suggestions page: “Approve all on this page” selects every row that can be approved and
    // approves them together, each with the text in its field, after asking once.
    function approveAll(button) {
        var form = button.closest('form');

        function rows() {
            return Array.prototype.filter.call(form.querySelectorAll('input[data-select]'), function(box) {
                return !box.closest('tr.is-leaving');
            });
        }

        button.hidden = rows().length === 0;

        button.addEventListener('click', function() {
            var boxes = rows();

            if (boxes.length === 0 || !window.confirm(Craft.t('alt-monitor', 'Approve all {count, number} suggestions on this page? Each one becomes its image’s alt text, including your edits.', {count: boxes.length}))) {
                return;
            }

            form.querySelectorAll('input[data-select]').forEach(function(box) {
                box.checked = boxes.indexOf(box) !== -1;
            });

            button.classList.add('loading');
            button.disabled = true;
            // requestSubmit, unlike submit(), lets the unsaved-text check see what is being saved.
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    }

    // Suggestions page: the list again, in place (the reviewed rows are gone, so this is the next
    // suggestions), keeping the keyboard where it was. Past the last page, the first page.
    function loadNextSuggestions(url) {
        var root = document.querySelector('[data-altmonitor-review]');

        if (!root || !window.fetch || !window.DOMParser) {
            window.location.reload();
            return;
        }

        root.classList.add('is-loading');
        root.setAttribute('aria-busy', 'true');

        fetch(url || window.location.href, {credentials: 'same-origin', headers: {Accept: 'text/html'}}).then(function(response) {
            if (!response.ok) {
                throw new Error(response.statusText);
            }

            return response.text();
        }).then(function(html) {
            var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-altmonitor-review]');
            var first = fresh && fresh.querySelector('[data-altmonitor-past-end]');

            if (!fresh) {
                throw new Error('Unexpected page');
            }

            if (first && !url) {
                return loadNextSuggestions(first.href);
            }

            root.innerHTML = fresh.innerHTML;

            // Craft loads thumbnails by script, as it does for content it loads itself.
            if (window.jQuery && Craft.cp && Craft.cp.elementThumbLoader) {
                Craft.cp.elementThumbLoader.load(window.jQuery(root));
            }

            if (url) {
                window.history.replaceState(null, '', url);
            }

            initLists(root);
            root.classList.remove('is-loading');
            root.removeAttribute('aria-busy');

            var row = root.querySelector('#altmonitor-suggestions tbody tr');
            var none = root.querySelector('.zilch');

            if (row) {
                focusReviewRow(row);
            } else if (none) {
                none.tabIndex = -1;
                none.focus();
            }

            if (Craft.cp && Craft.cp.announce) {
                Craft.cp.announce(row ? Craft.t('alt-monitor', 'Next suggestions loaded.') : (none ? none.textContent.trim() : ''));
            }
        }).catch(function() {
            window.location.reload();
        });
    }

    // Craft's asset editor (a page or a slide-out): AltMonitor's box in its sidebar. “Use” fills
    // the editor's own Alt Text field, so the asset is saved the usual way (and that save marks
    // the suggestion used); only where the editor has no such field is it approved right away.
    // Afterwards the box is rendered again, never the page, which would lose unsaved changes.
    function sidebarBox(box) {
        if (box.altmonitorReady) {
            return;
        }

        box.altmonitorReady = true;

        var form = box.closest('form');
        var image = {assetId: parseInt(box.getAttribute('data-asset-id'), 10), siteId: parseInt(box.getAttribute('data-audit-site-id'), 10)};
        var key = image.assetId + '-' + image.siteId;
        var use = box.querySelector('[data-sidebar-use]');
        var dismiss = box.querySelector('[data-sidebar-dismiss]');
        var describeButton = box.querySelector('[data-sidebar-describe]');
        var generate = box.querySelector('[data-sidebar-generate]');
        var panel = box.querySelector('[data-altmonitor-sidebar-describer]');
        var describer = panel ? new Describer(panel) : null;

        function altField() {
            // In a slide-out Craft namespaces the inputs (`abc123[alt]`); a custom field named
            // “alt” (`fields[alt]`, `abc123[fields][alt]`) is not the asset's Alt Text.
            var field = form && Array.prototype.find.call(form.querySelectorAll('textarea[name$="alt"], input[name$="alt"], textarea[name$="[alt]"], input[name$="[alt]"]'), function(input) {
                return input.name === 'alt' || (/^[^[\]]+\[alt\]$/.test(input.name) && input.name.indexOf('fields[') !== 0);
            });

            return field && !field.disabled && !field.readOnly ? field : null;
        }

        function refresh() {
            return Craft.sendActionRequest('GET', 'alt-monitor/assets/sidebar', {
                params: {assetId: box.getAttribute('data-asset-id'), siteId: box.getAttribute('data-site-id'), static: box.hasAttribute('data-static') ? 1 : 0},
            }).then(function(response) {
                var holder = document.createElement('div');
                holder.innerHTML = response.data.html || '';
                var fresh = holder.querySelector('[data-altmonitor-sidebar]');

                if (fresh && box.parentNode) {
                    box.replaceWith(fresh);
                    sidebarBox(fresh);
                }
            }).catch(function() {});
        }

        if (use) {
            // Nowhere to put the text and no right to save it directly: nothing to offer.
            if (!altField() && use.getAttribute('data-can-approve') !== '1') {
                use.hidden = true;
            }

            use.addEventListener('click', function() {
                var text = use.getAttribute('data-text');
                var field = altField();

                if (!field) {
                    decide(use, 'approve', {id: use.getAttribute('data-sidebar-use'), alt: text}, refresh);
                    return;
                }

                field.value = text;
                // Craft's editor notices the change (and warns about leaving without saving).
                field.dispatchEvent(new Event('input', {bubbles: true}));
                field.dispatchEvent(new Event('change', {bubbles: true}));
                field.focus();
                field.classList.add('altmonitor-filled');
                setTimeout(function() {
                    field.classList.remove('altmonitor-filled');
                }, 1600);
                use.disabled = true;

                var note = box.querySelector('[data-sidebar-note]');

                if (note) {
                    note.textContent = Craft.t('alt-monitor', 'Added to the Alt Text field. Save the asset to keep it.');
                    note.classList.add('is-done');
                }
            });
        }

        if (dismiss) {
            dismiss.addEventListener('click', function() {
                decide(dismiss, 'reject', {id: dismiss.getAttribute('data-sidebar-dismiss')}, refresh);
            });
        }

        if (generate) {
            generate.addEventListener('click', function() {
                generate.classList.add('loading');
                generate.disabled = true;
                Craft.sendActionRequest('POST', 'alt-monitor/suggestions/regenerate', {data: image}).then(function() {
                    Craft.cp.displayNotice(Craft.t('alt-monitor', 'Making a new suggestion. It shows here when it’s ready.'));
                }).catch(function(error) {
                    var message = error.response && error.response.data && error.response.data.message;
                    Craft.cp.displayError(message || Craft.t('alt-monitor', 'The suggestion could not be generated.'));
                    generate.disabled = false;
                }).finally(function() {
                    generate.classList.remove('loading');
                });
            });
        }

        var translateButton = box.querySelector('[data-translate-suggestion]');

        if (describer) {
            describer.onFinish = refresh;
        }

        if (describer && translateButton) {
            translateButton.altmonitorBound = true;
            translateButton.addEventListener('click', function() {
                var back = box.getAttribute('data-edit-url') || window.location.href;
                translate(describer, [translateItem(translateButton)], translateButton, new URL(back, window.location.href).href, {sidebar: key}).then(function() {
                    translateButton.disabled = false;
                });
            });
        }

        // Back from the image model page with a translation model: translate, once.
        var waiting = recall('sessionStorage', 'pending');

        if (describer && waiting && waiting.sidebar === key && waiting.translate) {
            remember('sessionStorage', 'pending', null);
            translationStatus(describer.config, waiting.translate[0].model).then(function(status) {
                if (status === 'ready' || recall('localStorage', 'notKept.' + waiting.translate[0].model)) {
                    describer.translateSuggestions(waiting.translate);
                }
            });
        }

        if (!describer || !describeButton) {
            return;
        }

        function run() {
            describeButton.disabled = true;
            describer.run([{assetId: image.assetId, siteId: image.siteId}]).then(function() {
                describeButton.disabled = false;
            });
        }

        describeButton.addEventListener('click', function() {
            describeButton.disabled = true;

            modelStatus(describer.config).then(function(status) {
                if (needsConsent(status) && !recall('localStorage', 'notKept') && describer.config.setupUrl) {
                    // The model page asks before downloading, then comes back to this asset's
                    // editor (a full page, also when this is a slide-out), which carries on.
                    var back = box.getAttribute('data-edit-url') || window.location.href;
                    var setup = new URL(describer.config.setupUrl, window.location.href);
                    remember('sessionStorage', 'pending', {sidebar: key, images: [image]});
                    setup.searchParams.set('return', new URL(back, window.location.href).href);
                    window.location.href = setup.href;

                    return;
                }

                run();
            });
        });

        // Back from the image model page: describe this image, once.
        var pending = recall('sessionStorage', 'pending');

        if (pending && pending.sidebar === key) {
            remember('sessionStorage', 'pending', null);
            modelStatus(describer.config).then(function(status) {
                if (status === 'ready' || recall('localStorage', 'notKept')) {
                    run();
                }
            });
        }
    }

    function sidebarBoxes() {
        document.querySelectorAll('[data-altmonitor-sidebar]').forEach(sidebarBox);
    }

    sidebarBoxes();

    // Slide-outs arrive after this script has run (and this script may arrive after them).
    if (window.MutationObserver && document.body) {
        var queued = false;

        // Only when something added holds a box: other changes (progress bars, lists, Craft's
        // own updates) don't search the page.
        var holdsBox = function(node) {
            return node.nodeType === 1 && (node.matches('[data-altmonitor-sidebar]') || node.querySelector('[data-altmonitor-sidebar]') !== null);
        };

        new MutationObserver(function(mutations) {
            if (queued || !mutations.some(function(mutation) {
                return Array.prototype.some.call(mutation.addedNodes, holdsBox);
            })) {
                return;
            }

            queued = true;
            window.requestAnimationFrame(function() {
                queued = false;
                sidebarBoxes();
            });
        }).observe(document.body, {childList: true, subtree: true});
    }

    // The lists' behaviour, set up on load and again on a list loaded in place.
    function initLists(root) {
        root.querySelectorAll('[data-altmonitor-autofilter]').forEach(autoFilter);
        root.querySelectorAll('[data-altmonitor-selection]').forEach(selection);
        root.querySelectorAll('[data-altmonitor-unsaved]').forEach(unsavedChanges);
        root.querySelectorAll('[data-altmonitor-check]').forEach(liveCheck);
        root.querySelectorAll('[data-use-suggestion]').forEach(useSuggestion);
        root.querySelectorAll('[data-approve], [data-reject]').forEach(reviewRow);
        root.querySelectorAll('[data-approve-all]').forEach(approveAll);
        root.querySelectorAll('[data-altmonitor-select-all]').forEach(selectAll);
        describeButtons(root);
    }

    initLists(document);
    document.querySelectorAll('[data-altmonitor-review]').forEach(function(root) {
        reviewKeys(root);

        // Arrived past the last page (say, after approving all of it): the first page instead.
        if (root.querySelector('[data-altmonitor-past-end]')) {
            loadNextSuggestions();
        }
    });

    document.querySelectorAll('[data-altmonitor-scan]').forEach(pollScan);
    document.querySelectorAll('[data-altmonitor-test]').forEach(connectionTest);
    document.querySelectorAll('[data-altmonitor-model]').forEach(function(root) {
        root.altmonitor = new ModelPage(root);
    });
})();
