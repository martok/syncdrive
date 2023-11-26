import {EB} from "../builder.js";
import {formatFileSize} from "../formatting.js";
import {EventSubTrait} from "../mixin.js";


class UploadDropper extends EventSubTrait() {
	constructor(containerElement, dragTargets) {
		super();
		if (typeof dragTargets === "string")
			dragTargets = [... document.querySelectorAll(dragTargets)];
		if (!Array.isArray(dragTargets))
			dragTargets = [dragTargets];
		if (!dragTargets.includes(containerElement))
			dragTargets.unshift(containerElement);
		for (const target of dragTargets) {
			target.addEventListener('dragenter', (e) => {
				containerElement.classList.add('drag-over');
				e.preventDefault();
				e.stopPropagation();
			});
			target.addEventListener('dragover', (e) => {
				containerElement.classList.add('drag-over');
				e.preventDefault();
				e.stopPropagation();
			});
			target.addEventListener('dragleave', (e) => {
				containerElement.classList.remove('drag-over');
				e.preventDefault();
				e.stopPropagation();
			});
			target.addEventListener('drop', this.onFileDrop.bind(this));
		}
		this.containerElement = containerElement;
		this.dragTargets = dragTargets;
	}

	onFileDrop(event) {
		event.preventDefault();
		event.stopPropagation();
		if (!event.dataTransfer ||
		    !event.dataTransfer.files)
			return;
		this.trigger('dropper:filesdropped', event.dataTransfer.files);
		this.containerElement.classList.remove('drag-over');
	}
}

class Uploader {
	constructor(progressContainer) {
		this._concurrent = 1;
		this._removeStatusTimeout = 10000;
		this._processTimer = 0;
		this._index = 0;
		this.queue = [];
		this.runningUploads = new Map();
		this.progressContainer = progressContainer;
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
		setInterval(this._processQueue.bind(this), 100);
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
			ctrl: {}
		};
		// setup UI
		uploadState.ctrl.wrapper = EB('div', {$: 'upload-wrapper upload-running'},
			uploadState.ctrl.name = EB('span', {$: 'upload-name'}, task.file.name),
			uploadState.ctrl.progress = EB('progress', {$: 'uk-progress'}),
			uploadState.ctrl.progresstext = EB('span', {$: 'upload-info'}, 'Starting...'),
		);
		uploadState.xhr.addEventListener('progress', (e) => {
			if (e.lengthComputable) {
				uploadState.totalBytes = e.total;
				uploadState.transferredBytes = e.loaded;
				uploadState.ctrl.progress.max = e.total;
				uploadState.ctrl.progress.value = e.loaded;
				uploadState.ctrl.progresstext.innerText = formatFileSize(e.loaded) + '/' + formatFileSize(e.total);
			}
		});
		uploadState.xhr.addEventListener('load', this._uploadFinished.bind(this, uploadState));
		uploadState.xhr.addEventListener('error', this._uploadFinished.bind(this, uploadState));
		uploadState.xhr.withCredentials = true;
		uploadState.xhr.open(task.method, task.url);

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
		this.progressContainer.prepend(uploadState.ctrl.wrapper);
	}

	_uploadFinished(uploadState, event) {
		this.runningUploads.delete(uploadState.index);
		uploadState.ctrl.progress.max = 1;
		uploadState.ctrl.progress.value = 1;
		if (event.type === 'load') {
			if (uploadState.xhr.status < 300) {
				this._uploadSuccess(uploadState);
			} else {
				this._uploadFailure(uploadState, `HTTP ${uploadState.xhr.status}`);
			}
		} else {
			this._uploadFailure(uploadState, event.type);
		}
	}

	_uploadSuccess(uploadState) {
		uploadState.ctrl.wrapper.classList.replace('upload-running', 'upload-finished');
		uploadState.ctrl.progresstext.innerText = 'Done.';
		setTimeout(() => {
			$(uploadState.ctrl.wrapper).fadeOut('slow', () => {
				this.progressContainer.removeChild(uploadState.ctrl.wrapper);
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

export {
	UploadDropper,
	Uploader
};