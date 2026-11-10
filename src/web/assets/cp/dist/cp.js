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
                text.textContent = Craft.t('alt-monitor', 'Progress could not be loaded. Reload the page to try again.');
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
        self.stopped = false;
        self.running = false;
        self.finished = false;
        self.worker = null;
        self.loading = null;
        self.downloading = false;
        self.pending = {};
        self.nextId = 0;
        self.previewUrl = null;

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
    }

    Describer.prototype.open = function() {
        if (this.panel.tagName === 'DIALOG' && !this.panel.open) {
            this.panel.showModal();
        }

        this.panel.hidden = false;
    };

    Describer.prototype.say = function(text, percent) {
        this.open();
        this.status.textContent = text;

        if (typeof percent === 'number') {
            this.bar.style.width = percent + '%';
            this.bar.parentNode.setAttribute('aria-valuenow', Math.round(percent));
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

    // Closing after a run reloads the page, so lists show the new suggestions.
    Describer.prototype.close = function() {
        if (this.panel.tagName === 'DIALOG') {
            this.panel.close();
        }

        if (this.finished) {
            window.location.reload();
        }
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

    Describer.prototype.load = function() {
        var self = this;

        if (self.loading) {
            return self.loading;
        }

        if (typeof WebAssembly !== 'object' || typeof Worker !== 'function') {
            return Promise.reject(new Error(Craft.t('alt-monitor', 'This browser can’t run the image model. Use a current version of Chrome, Edge, Firefox or Safari.')));
        }

        self.say(self.downloading ? Craft.t('alt-monitor', 'Loading the image model…') : Craft.t('alt-monitor', 'Starting the image model…'), 0);
        self.loading = new Promise(function(resolve, reject) {
            // Kept so a reset (Stop) during start-up ends the wait instead of leaving it hanging.
            self.rejectLoad = reject;

            try {
                self.worker = createWorker(self.config.worker);
            } catch (error) {
                self.loading = null;
                reject(error);
                return;
            }

            self.worker.onmessage = function(event) {
                var message = event.data;

                if (message.type === 'progress') {
                    // Reported too while the model is read from the browser's cache, which is no
                    // download: only the image model page, which downloads it, shows megabytes.
                    var sizes = {loaded: Math.round(message.loaded / 1e6), total: Math.round(message.total / 1e6)};
                    self.say(self.downloading
                        ? Craft.t('alt-monitor', 'Downloading the image model (once): {loaded} of {total} MB', sizes)
                        : Craft.t('alt-monitor', 'Starting the image model…'), message.loaded / message.total * 100);
                } else if (message.type === 'ready') {
                    self.rejectLoad = null;
                    resolve();
                } else if (message.type === 'trying') {
                    self.status.textContent = Craft.t('alt-monitor', 'That one is already used. Looking for a different description (try {attempt, number})…', {attempt: message.attempt + 1});
                } else if (message.type === 'description' || (message.type === 'error' && message.id !== null)) {
                    var pending = self.pending[message.id];
                    delete self.pending[message.id];

                    if (pending) {
                        message.type === 'description' ? pending.resolve({text: message.text, fresh: message.fresh}) : pending.reject(new Error(message.message));
                    }
                } else if (message.type === 'error') {
                    self.reset();
                    reject(new Error(message.message));
                }
            };
            // The worker itself died (e.g. out of memory): nothing it was doing will answer.
            self.worker.onerror = function(event) {
                var error = new Error(event.message || 'The image model stopped working.');
                self.reset(error);
                reject(error);
            };
            self.worker.postMessage({type: 'load', config: self.config});
        });

        return self.loading;
    };

    // Ends the worker, failing anything still waiting on it, so the next run starts afresh.
    Describer.prototype.reset = function(error) {
        var pending = this.pending;
        var rejectLoad = this.rejectLoad;
        this.pending = {};
        this.loading = null;
        this.rejectLoad = null;

        if (rejectLoad) {
            rejectLoad(error || new Error('The image model stopped working.'));
        }

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

    // Describes one image and stores the description. Resolves to 'described', 'same' (nothing
    // new was found), 'stopped' or 'failed'.
    Describer.prototype.describe = function(assetId, siteId, label) {
        var self = this;
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

            return new Promise(function(resolve, reject) {
                var id = ++self.nextId;
                self.pending[id] = {resolve: resolve, reject: reject};
                self.worker.postMessage({type: 'describe', id: id, image: image, avoid: known.concat(history(assetId, siteId))});
            });
        }).then(function(answer) {
            if (!answer.fresh) {
                self.note(name, Craft.t('alt-monitor', 'No new description found; it would repeat the current one.'), 'same');

                return 'same';
            }

            self.show(null, undefined, answer.text);

            return Craft.sendActionRequest('POST', 'alt-monitor/suggestions/store', {
                data: {assetId: assetId, siteId: siteId, text: answer.text},
            }).then(function(response) {
                addToHistory(assetId, siteId, answer.text);

                if (!response.data.suggestion) {
                    self.note(name, Craft.t('alt-monitor', 'No new description found; it would repeat the current one.'), 'same');

                    return 'same';
                }

                self.note(name, '“' + response.data.suggestion.text + '”', 'described');

                return 'described';
            });
        }).catch(function(error) {
            if (self.stopped) {
                return 'stopped';
            }

            console.warn('AltMonitor could not describe asset ' + assetId + ':', error);
            self.note(name, Craft.t('alt-monitor', 'Could not be described.'), 'failed');

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

        // One run at a time: a second click must not undo a Stop or start a parallel walk.
        if (self.running) {
            return Promise.resolve();
        }

        self.running = true;
        self.stopped = false;
        self.finished = false;

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
            self.say(total
                ? Craft.t('alt-monitor', 'Describing image {done, number} of {total, number}…', {done: done, total: total})
                : Craft.t('alt-monitor', 'Describing image {done, number}…', {done: done}), total ? Math.max(4, (done - 1) / total * 100) : null);

            return self.describe(image.assetId, image.siteId, Craft.t('alt-monitor', 'Image {number, number}', {number: done})).then(function(outcome) {
                if (outcome !== 'stopped') {
                    counts[outcome]++;
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
                ? Craft.t('alt-monitor', 'Stopped: {described, number} new, {same, number} unchanged, {failed, number} could not be described.', counts)
                : Craft.t('alt-monitor', 'Done: {described, number} new, {same, number} unchanged, {failed, number} could not be described.', counts));
        }

        return self.load().then(function() {
            return loop().then(finished, function(error) {
                enableDescribeButtons();
                end(Craft.t('alt-monitor', 'Describing stopped because of an error: {message}', {message: error.message}));
            });
        }, function(error) {
            enableDescribeButtons();

            if (self.stopped) {
                end(Craft.t('alt-monitor', 'Stopped: {described, number} new, {same, number} unchanged, {failed, number} could not be described.', counts));

                return;
            }

            end(Craft.t('alt-monitor', 'The image model could not be loaded: {message}', {message: error.message}));
        });
    };

    function enableDescribeButtons() {
        document.querySelectorAll('[data-describe-all], [data-describe-one], [data-describe-selected]').forEach(function(button) {
            button.disabled = false;
        });
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

        var prefix = '/' + config.model + '/resolve/main/';

        return cachedModelFiles(config).then(function(found) {
            var urls = found.requests.map(function(request) {
                return request.url;
            });

            return MODEL_FILES.every(function(file) {
                return urls.some(function(url) {
                    return url.slice(-(prefix + file).length) === prefix + file;
                });
            }) ? 'ready' : 'missing';
        }).catch(function() {
            return 'no-cache';
        });
    }

    // The model's files and the runtime's, which together are what the download fetched.
    function isModelFile(config, url) {
        return url.indexOf('/' + config.model + '/') !== -1
            || url.indexOf('/onnxruntime-web') !== -1
            || (config.wasmPaths && url.indexOf(config.wasmPaths) === 0);
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

    function removeModel(config) {
        return cachedModelFiles(config).then(function(found) {
            return Promise.all(found.requests.filter(function(request) {
                return isModelFile(config, request.url);
            }).map(function(request) {
                return found.cache.delete(request);
            }));
        });
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
            if (status === 'missing' && !recall('localStorage', 'notKept') && describer.config.setupUrl) {
                remember('sessionStorage', 'pending', {url: window.location.href, images: images});
                var setup = new URL(describer.config.setupUrl, window.location.href);
                setup.searchParams.set('return', window.location.href);
                window.location.href = setup.href;

                return;
            }

            return describer.run(images);
        });
    }

    function keyToImage(key) {
        var parts = key.split('-');

        return {assetId: parseInt(parts[0], 10), siteId: parseInt(parts[1], 10)};
    }

    document.querySelectorAll('[data-describe-all]').forEach(function(button) {
        button.addEventListener('click', function() {
            describe(null, button);
        });
    });

    document.querySelectorAll('[data-describe-one]').forEach(function(button) {
        button.addEventListener('click', function() {
            describe([keyToImage(button.getAttribute('data-describe-one'))], button);
        });
    });

    document.querySelectorAll('[data-describe-selected]').forEach(function(button) {
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
    });

    // Back from the image model page: continue what was asked for, once, if the model is now here.
    (function resumePending() {
        var pending = recall('sessionStorage', 'pending');
        var describer = describers();

        if (!pending || !describer) {
            return;
        }

        remember('sessionStorage', 'pending', null);

        if (pending.url !== window.location.href) {
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

        this.agree.addEventListener('change', function() {
            this.download.disabled = !this.agree.checked;
        }.bind(this));
        this.download.addEventListener('click', this.start.bind(this));
        root.querySelector('[data-stop]').addEventListener('click', this.cancel.bind(this));
        root.querySelector('[data-remove]').addEventListener('click', this.remove.bind(this));
        this.warnAboutConnection();
        this.check();
    }

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

                    // Sent here by a Describe button: go back, where resumePending() describes the
                    // images that were asked for. (returnUrl is checked by ModelController.)
                    if (self.returnUrl) {
                        window.location.replace(self.returnUrl);

                        return;
                    }

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
                    self.show(status === 'no-cache' ? 'no-cache' : 'not-kept');
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

        if (!window.confirm(Craft.t('alt-monitor', 'Remove the image model from this browser? It will be downloaded again the next time someone describes images here.'))) {
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

        if (connection && (connection.saveData || connection.type === 'cellular')) {
            root.querySelector('[data-metered]').hidden = false;
        }

        if (navigator.storage && navigator.storage.estimate) {
            navigator.storage.estimate().then(function(estimate) {
                if (estimate.quota && estimate.quota - (estimate.usage || 0) < 400e6) {
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
    function selection(form) {
        var count = form.querySelector('[data-selected-count]');
        var idle = count ? count.textContent : '';

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
        }

        form.addEventListener('change', update);
        update();
    }

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

    // Images list: “Use” approves the suggestion as it is and puts it in the row's field.
    function useSuggestion(button) {
        button.addEventListener('click', function() {
            var row = button.closest('tr');
            var text = button.getAttribute('data-text');

            decide(button, 'approve', {id: button.getAttribute('data-use-suggestion'), alt: text}, function(data) {
                var input = row.querySelector('input[name^="alt["]');
                var badge = row.querySelector('[data-alt-status]');

                if (badge && data.status) {
                    badge.className = 'status-label ' + data.status.color;
                    badge.setAttribute('data-alt-status', data.status.value);
                    badge.querySelector('.status-label-text').textContent = data.status.label;
                    badge.parentNode.querySelectorAll('.smalltext').forEach(function(issue) {
                        issue.remove();
                    });
                }

                if (input) {
                    input.value = text;
                }

                var box = button.closest('[data-suggestion]');
                box.classList.add('is-used');
                box.textContent = Craft.t('alt-monitor', 'Suggestion approved and saved to the image.');
            });
        });
    }

    // Suggestions page: approve (with the edited text) or reject a row, which then leaves the list.
    function reviewRow(button) {
        var approve = button.hasAttribute('data-approve');

        button.addEventListener('click', function() {
            var row = button.closest('tr');
            var id = button.getAttribute(approve ? 'data-approve' : 'data-reject');
            var field = row.querySelector('textarea');
            var data = approve ? {id: id, alt: field ? field.value : ''} : {id: id};

            decide(button, approve ? 'approve' : 'reject', data, function() {
                var counter = document.querySelector('[data-pending-count]');

                if (counter) {
                    counter.textContent = Math.max(0, parseInt(counter.textContent.replace(/\D/g, ''), 10) - 1);
                }

                row.classList.add('is-leaving');
                setTimeout(function() {
                    var body = row.parentNode;
                    row.remove();

                    if (!body.querySelector('tr')) {
                        window.location.reload();
                    }
                }, 300);
            });
        });
    }

    document.querySelectorAll('[data-altmonitor-autofilter]').forEach(autoFilter);
    document.querySelectorAll('[data-altmonitor-selection]').forEach(selection);
    document.querySelectorAll('[data-use-suggestion]').forEach(useSuggestion);
    document.querySelectorAll('[data-approve], [data-reject]').forEach(reviewRow);

    document.querySelectorAll('[data-altmonitor-scan]').forEach(pollScan);
    document.querySelectorAll('[data-altmonitor-test]').forEach(connectionTest);
    document.querySelectorAll('[data-altmonitor-select-all]').forEach(selectAll);
    document.querySelectorAll('[data-altmonitor-model]').forEach(function(root) {
        root.altmonitor = new ModelPage(root);
    });
})();
