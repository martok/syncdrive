import {UKButton, UKIcon} from "../builder.js";
import {apiFetch} from "../apiClient.js";
import {UploadDropper, Uploader} from "../components/upload.js";
import {FileDetailBar} from "../components/FileDetailBar.js";
import {FileTable} from "../components/FileTable.js";


(function () {
	const LS_COPIED_FILES = 'browse:copied';

	function hasPerms(perms) {
		return Array.prototype.every.call(perms, p => CURRENT_PERMISSIONS.includes(p));
	}

	class FileViewController {
		constructor() {
			this.fileTable = new FileTable(document.getElementById('file-list-table'));
			this.fileTable.multiSelect = true;
			this.fileTable.on('sortable:selectionchanged', this.onSelectionChanged.bind(this));
			this.fileTable.on('sortable:dblclick', this.onSelectionDoubleClicked.bind(this));
			document.addEventListener('keydown' , (evt) => {
				if (evt.key === 'a' && (evt.ctrlKey || evt.metaKey)) {
					this.fileTable.selectAll();
					evt.preventDefault();
				}
			});
			const sidebar = document.querySelector('div.files-right');
			if (sidebar) {
				this.fileDetails = new FileDetailBar(sidebar);
				const bc = document.getElementById('browse-breadcrumbs');
				if (bc.childElementCount > 1) {
					bc.lastElementChild.append(UKIcon('icon: info; ratio: 0.8',
						{$: 'uk-margin-small-left', title: 'Show details', onclick: this.onEditCurrentClick.bind(this)}));
				}
			} else {
				this.fileDetails = null;
			}

			let btn;
			if (hasPerms('C') || hasPerms('W')) {
				this.storageKey = LS_COPIED_FILES;
				if (typeof SHARE_TOKEN !== "undefined")
					this.storageKey += ':' + SHARE_TOKEN;
				window.addEventListener('storage', this.onStorageChanged.bind(this));
				this.onStorageChanged();

				this.uploadDropper = new UploadDropper(document.getElementById('upload-drop'), '.files-main,.files-share');
				this.uploadDropper.on('dropper:filesdropped', this.onUploadFilesDropped.bind(this));
				this.uploader = new Uploader(document.getElementById('upload-status'));
				this.uploader.concurrent = 2;
				document.getElementById('fileUpload').addEventListener('change', this.onUploadFilesSelected.bind(this));

				btn = document.getElementById('action-upload');
				btn.addEventListener('click', this.onToolbarUploadClick.bind(this));
				btn.disabled = false;
				document.getElementById('action-paste').addEventListener('click', this.onToolbarPasteClick.bind(this));
			}

			if (hasPerms('K') && (btn = document.getElementById('action-new-folder'))) {
				btn.addEventListener('click', this.onToolbarNewFolderClick.bind(this));
				btn.disabled = false;
			}
			document.getElementById('action-rename').addEventListener('click', this.onToolbarRenameClick.bind(this));

			this.onSelectionChanged();
		}

		updateToolbarForSelection(selected) {
			const newButtons = [];
			if (selected.length) {
				newButtons.push(UKButton([UKIcon('close'), `(${selected.length} selected)`],
					{title: 'Clear selection', onclick: this.onToolbarClearSelectionClick.bind(this)}));
				newButtons.push(UKButton([UKIcon('copy')],
					{title: 'Copy files', onclick: this.onToolbarCopyMoveClick.bind(this, 'copy')}));
				newButtons.push(UKButton([UKIcon('move')],
					{title: 'Move files', onclick: this.onToolbarCopyMoveClick.bind(this, 'move')}));
				if (hasPerms('D')) {
					if (!Array.prototype.every.call(selected, (row) => row.sortableData.deleted))
						newButtons.push(UKButton(UKIcon('trash'),
							{title: 'Delete', onclick: this.onToolbarDeleteClick.bind(this)}));
					if (Array.prototype.some.call(selected, (row) => row.sortableData.deleted)) {
						newButtons.push(UKButton(UKIcon('history'),
							{title: 'Restore', onclick: this.onToolbarRestoreClick.bind(this)}));
						newButtons.push(UKButton(UKIcon('ban'),
							{title: 'Permanently delete', onclick: this.onToolbarRemoveClick.bind(this)}));
					}
				}
			}
			const toolbar= document.getElementById('selected-file-actions');
			toolbar.replaceChildren(...newButtons);
		}

		onSelectionChanged() {
			const selected = this.fileTable.getSelectedRows();
			const focused = this.fileTable.getFocusedRow();
			const isSelected = Array.prototype.includes.call(selected, focused);
			const file = isSelected ? focused.sortableData : null;
			const canRename = isSelected && !file.deleted && file.perms.includes('N');
			document.getElementById('action-rename').disabled = !canRename;
			this.updateToolbarForSelection(selected);
			if (this.fileDetails)
				this.fileDetails.setFile(file);
		}

		onSelectionDoubleClicked() {
			const focused = this.fileTable.getFocusedRow();
			if (focused) {
				const a = focused.querySelector(':scope td:first-child a');
				location.assign(a.href);
			}
		}

		onStorageChanged() {
			const copied = JSON.parse(localStorage.getItem(this.storageKey) ?? '{}');
			const pasteBtn = document.getElementById('action-paste');
			if (copied && copied.operation && copied.paths && copied.paths.length) {
				pasteBtn.disabled = false;
				pasteBtn.lastElementChild.innerText = `(${copied.paths.length})`;

				pasteBtn.title = copied.paths.join('\n');
			} else {
				pasteBtn.disabled = true;
				pasteBtn.lastElementChild.innerText = '';
				pasteBtn.title = '';
			}
		}

		onEditCurrentClick() {
			this.fileDetails.setFile(CURRENT_FILE);
		}

		async onToolbarUploadClick() {
			document.getElementById('fileUpload').click();
		}

		_fullUploadPath() {
			const url = new URL(window.location.href);
			url.pathname = BROWSE_UPLOAD_PATH;
			// for public shares, just encode the token and take auth from the interactive session
			url.username = (typeof SHARE_TOKEN !== "undefined") ? SHARE_TOKEN : '';
			url.password = '-';
			url.search = '';
			return url.href;
		}

		async onUploadFilesSelected(e) {
			e.preventDefault();
			if (e.target.files) {
				this.uploader.putFiles(this._fullUploadPath(), e.target.files);
			}
			e.target.value = "";
		}

		async onUploadFilesDropped(e, files) {
			this.uploader.putFiles(this._fullUploadPath(), files);
		}

		async onToolbarNewFolderClick() {
			const name = prompt('New folder name:', '');
			if (!name)
				return;

			const result = await apiFetch(`/ajax/file/new/folder`, {
				parent: BROWSE_PATH,
				name: name
			});
			if (result)
				window.location.assign(URI_BASE + '/' + result.path);
		}

		async onToolbarRenameClick() {
			const file = this.fileTable.getFocusedRow().sortableData;
			const newName = prompt('Rename', file.name);
			if (newName === null || newName === file.name)
				return;
			const result = await apiFetch(`/ajax/file/rename`, {
				path: file.path,
				name: newName,
			});
			if (result && result.result)
				window.location.reload();
		}

		onToolbarClearSelectionClick() {
			this.fileTable.deselectAll();
		}

		onToolbarCopyMoveClick(operation) {
			const selected = this.fileTable.getSelectedRows();
			const files = Array.prototype.map.call(selected, (row) => row.sortableData);
			const paths = files.map((file) => file.path);
			localStorage.setItem(this.storageKey, JSON.stringify({operation, paths}));
			this.onStorageChanged();
		}

		async onToolbarPasteClick(operation) {
			const copied = JSON.parse(localStorage.getItem(this.storageKey) ?? '{}');
			if (copied && copied.operation && copied.paths && copied.paths.length) {
				const result = await apiFetch(`/ajax/file/paste`, {
					operation: copied.operation,
					parent: BROWSE_PATH,
					files: copied.paths,
				});
				if (result && result.result) {
					localStorage.removeItem(this.storageKey);
					window.location.reload();
				}
			}
			this.onStorageChanged();
		}

		async onToolbarDeleteClick() {
			const selected = this.fileTable.getSelectedRows();
			const files = Array.prototype.map.call(selected, (row) => row.sortableData).filter((file) => !file.deleted);
			if (!confirm(`Move ${files.length} to trash?`))
				return;
			const result = await apiFetch(`/ajax/file/delete`, {
				files: files.map((file) => file.path)
			});
			if (result && result.result)
				window.location.reload();
		}

		async onToolbarRestoreClick() {
			const selected = this.fileTable.getSelectedRows();
			const files = Array.prototype.map.call(selected, (row) => row.sortableData).filter((file) => file.deleted);
			if (!confirm(`Restore ${files.length} items?`))
				return;
			const result = await apiFetch(`/ajax/file/restore`, {
				files: files.map((file) => file.path)
			});
			if (result && result.result)
				window.location.reload();
		}

		async onToolbarRemoveClick() {
			const selected = this.fileTable.getSelectedRows();
			const files = Array.prototype.map.call(selected, (row) => row.sortableData).filter((file) => file.deleted);
			if (!confirm(`Permanently delete ${files.length} items?`))
				return;
			const result = await apiFetch(`/ajax/file/remove`, {
				files: files.map((file) => file.path)
			});
			if (result && result.result)
				window.location.reload();
		}
	}

	const controller = new FileViewController();
})();
