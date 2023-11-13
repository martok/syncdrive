import { SorTable } from "./components/SorTable.js";
import {EB, UKButton, UKIcon} from "./builder.js";
import { apiFetch } from "./apiClient.js";
import {UploadDropper, Uploader} from "./components/upload.js";
import {FileDetailBar} from "./components/FileDetailBar.js";
import {FileTable} from "./components/FileTable.js";


function hasPerms(perms) {
	return Array.prototype.every.call(perms, p => CURRENT_PERMISSIONS.includes(p));
}

(function () {
	const LS_COPIED_FILES = 'browse:copied';

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
			})
			this.renameBtn = null;
			this.fileDetails = new FileDetailBar();
			if (document.getElementById('upload-drop')) {
				this.uploadDropper = new UploadDropper(document.getElementById('upload-drop'), '.files-main');
				this.uploadDropper.on('dropper:filesdropped', this.onUploadFilesDropped.bind(this));
				this.uploader = new Uploader(document.getElementById('upload-status'));
				this.uploader.concurrent = 2;
				document.getElementById('fileUpload').addEventListener('change', this.onUploadFilesSelected.bind(this));
			}
			document.getElementById('upload')?.addEventListener('click', this.onToolbarUploadClick.bind(this));
			document.getElementById('newfolder')?.addEventListener('click', this.onToolbarNewFolderClick.bind(this));
			document.getElementById('file-paste')?.addEventListener('click', this.onToolbarPasteClick.bind(this));
			document.getElementById('file-rename')?.addEventListener('click', this.onToolbarRenameClick.bind(this));
			window.addEventListener('storage', this.onStorageChanged.bind(this));
			this.onStorageChanged();
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
			this.fileDetails.setFile(isSelected ? focused.sortableData : null);
			this.updateToolbarForSelection(selected);
			const canRename = isSelected && !focused.sortableData.deleted
										 && focused.sortableData.perms.includes('N');
			document.getElementById('file-rename').disabled = !canRename;
		}

		onSelectionDoubleClicked() {
			const focused = this.fileTable.getFocusedRow();
			if (focused) {
				const a = focused.querySelector(':scope td:first-child a');
				location.assign(a.href);
			}
		}

		onStorageChanged() {
			const copied = JSON.parse(localStorage.getItem(LS_COPIED_FILES) ?? '{}');
			const pasteBtn = document.getElementById('file-paste');
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

		async onToolbarUploadClick() {
			document.getElementById('fileUpload').click();
		}

		async onUploadFilesSelected(e) {
			e.preventDefault();
			if (e.target.files) {
				this.uploader.putFiles(BROWSE_UPLOAD_PATH, e.target.files);
			}
			e.target.value = "";
		}

		async onUploadFilesDropped(e, files) {
			this.uploader.putFiles(BROWSE_UPLOAD_PATH, files);
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
				window.location.assign('/browse/' + result.path);
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
			localStorage.setItem(LS_COPIED_FILES, JSON.stringify({operation, paths}));
			this.onStorageChanged();
		}

		async onToolbarPasteClick(operation) {
			const copied = JSON.parse(localStorage.getItem(LS_COPIED_FILES) ?? '{}');
			if (copied && copied.operation && copied.paths && copied.paths.length) {
				const result = await apiFetch(`/ajax/file/paste`, {
					operation: copied.operation,
					parent: BROWSE_PATH,
					files: copied.paths,
				});
				if (result && result.result) {
					localStorage.removeItem(LS_COPIED_FILES);
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
