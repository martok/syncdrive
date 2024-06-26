import AttachableComponent from "../AttachableComponent.js";
import {html} from "../uhtml.js";
import {formatFileSize} from "../formatting.js";

export default class Uploader extends AttachableComponent {
    static {
        this.define('upload-status', this);
    }

    create(element) {
        super.create(element);
        this._concurrent = 1;
        this._removeStatusTimeout = 10000;
        this._progressUpdateInterval = 500;
        this._processTimer = 0;
        this._index = 0;
        this.queue = [];
        this.runningUploads = new Map();
        addEventListener('beforeunload', this.onBeforeUnload.bind(this), {capture: true});
    }

    get concurrent() { return this._concurrent; }
    set concurrent(val) { return this._concurrent = 0+val; }

    get removeStatusTimeout() { return this._removeStatusTimeout; }
    set removeStatusTimeout(val) { return this._removeStatusTimeout = 0+val; }

    _startProcessing() {
        if (this._processTimer) {
            return;
        }
        this._processTimer = setInterval(this._processQueue.bind(this), 100);
    }

    _queueFinished() {
        clearInterval(this._processTimer);
        this._processTimer = 0;
    }

    _processQueue() {
        // already running enough tasks?
        if (this.runningUploads.size >= this.concurrent)
            return;
        // any new task to start?
        if (this.queue.length === 0) {
            this._queueFinished();
            return;
        }
        // start new uploads
        while (this.runningUploads.size < this.concurrent) {
            const next = this.queue.shift();
            if (!next)
                break;
            this._beginUpload(next);
        }
    }

    _beginUpload(task) {
        const uploadState = {
            index: this._index++,
            xhr: new XMLHttpRequest(),
            totalBytes: 0,
            transferredBytes: 0,
            transferTimestamp: (new Date()).getTime(),
            statusTimestamp: 0,
            avgSpeed: 0.0,
            ctrl: {}
        };
        // setup UI
        uploadState.ctrl.wrapper = html`
            <div class="upload-wrapper upload-running data-row">
                <div>
                    ${uploadState.ctrl.name = html`<div class="upload-name">${task.file.name}</div>`}
                    ${uploadState.ctrl.progress = html`<progress class="uk-progress"/>`}
                    ${uploadState.ctrl.progresstext = html`<div class="uk-text-small">Starting...</div>`}
                </div>
                ${uploadState.ctrl.btnStop = html`<button class="data-column-shrink uk-button uk-button-small" uk-icon="close"
                        title="Cancel upload" @click=${() => uploadState.xhr.abort()} />`}
            </div>
        `;
        uploadState.xhr.withCredentials = true;
        uploadState.xhr.open(task.method, task.url);
        uploadState.xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                uploadState.totalBytes = e.total;
                const time = (new Date()).getTime();
                const incrTime = time - uploadState.transferTimestamp;
                if (incrTime < this._progressUpdateInterval)
                    return;

                const incrBytes = e.loaded - uploadState.transferredBytes;
                const currSpeed = incrBytes / incrTime * 1000;
                // On the initial update or on large changes jump, otherwise use smoothing
                if ((uploadState.avgSpeed < 0.01) ||
                    (Math.abs(uploadState.avgSpeed - currSpeed) / Math.max(uploadState.avgSpeed, currSpeed) > 0.2)) {
                    uploadState.avgSpeed = currSpeed;
                } else {
                    uploadState.avgSpeed = uploadState.avgSpeed * 0.8 + 0.2 * currSpeed;
                }
                uploadState.transferredBytes = e.loaded;
                uploadState.transferTimestamp = time;
                uploadState.ctrl.progress.max = e.total;
                uploadState.ctrl.progress.value = e.loaded;
                uploadState.ctrl.progresstext.innerText = formatFileSize(e.loaded) + '/' + formatFileSize(e.total) +
                    ' @ ' + formatFileSize(uploadState.avgSpeed) + '/s';
                uploadState.statusTimestamp = time;
            }
        });
        uploadState.xhr.upload.addEventListener('load', this._uploadFinished.bind(this, uploadState));
        uploadState.xhr.upload.addEventListener('error', this._uploadFinished.bind(this, uploadState));
        uploadState.xhr.upload.addEventListener('abort', this._uploadFinished.bind(this, uploadState));

        switch(task.method) {
            case 'PUT':
                if (task.file.lastModified)
                    uploadState.xhr.setRequestHeader('X-OC-MTime', (task.file.lastModified / 1000).toFixed(0));
                uploadState.xhr.send(task.file);
                break;
            default:
                return;
        }

        // run and store
        this.runningUploads.set(uploadState.index, uploadState);
        this.element.prepend(uploadState.ctrl.wrapper);
    }

    _uploadFinished(uploadState, event) {
        this.runningUploads.delete(uploadState.index);
        uploadState.ctrl.btnStop.style.visibility = 'hidden';
        uploadState.ctrl.progress.max = 1;
        uploadState.ctrl.progress.value = 1;
        switch (event.type) {
            case 'load':
                if (uploadState.xhr.status < 300) {
                    this._uploadSuccess(uploadState, true);
                } else {
                    this._uploadFailure(uploadState, `HTTP ${uploadState.xhr.status}`);
                }
                break;
            case 'abort':
                this._uploadSuccess(uploadState, false);
                break;
            default:
                this._uploadFailure(uploadState, event.type);
        }
    }

    _uploadSuccess(uploadState, completed) {
        uploadState.ctrl.wrapper.classList.replace('upload-running', 'upload-finished');
        uploadState.ctrl.progresstext.innerText = completed ? 'Done' : 'Cancelled';
        setTimeout(() => {
            $(uploadState.ctrl.wrapper).fadeOut('slow', () => {
                this.element.removeChild(uploadState.ctrl.wrapper);
            })
        }, this._removeStatusTimeout);
    }

    _uploadFailure(uploadState, info) {
        uploadState.ctrl.wrapper.classList.replace('upload-running', 'upload-failed');
        uploadState.ctrl.progresstext.innerText = 'Failed: ' + info;
    }

    onBeforeUnload(e) {
        if (this.runningUploads.size) {
            e.preventDefault();
            return (e.returnValue = "Currently running uploads will be aborted.");
        }
    }

    putFiles(folderEndpoint, fileList) {
        for (const file of fileList) {
            const target = folderEndpoint + file.name;
            this.queue.push({
                method: 'PUT',
                url: target,
                file: file,
            });
        }
        this._startProcessing();
    }
}