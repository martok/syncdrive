import {UKButton, UKIcon} from "../builder.js";
import {apiFetch} from "../apiClient.js";
import {every, includes, map, some} from "../containers.js";
import AttachableComponent from "../AttachableComponent.js";
import EventSub from "./EventSub.js";
import FileDetailBar from "./FileDetailBar.js";
import FileTable from "./FileTable.js";
import Uploader from "./Uploader.js";
import UploadDropper from "./UploadDropper.js";


const LS_COPIED_FILES = 'browse:copied';

function hasPerms(perms) {
    return every(perms, p => CURRENT_PERMISSIONS.includes(p));
}

export default class SectionFileBrowser extends AttachableComponent {
    static {
        this.define('section-file-browser', this);
    }

    create(element) {
        super.create(element);

        this.fileTable = new FileTable(this.querySelector('#file-list-table'));
        this.fileTable.multiSelect = true;
        this.fileTable.as(EventSub).on('sortable:selectionchanged', this.onSelectionChanged.bind(this));
        this.fileTable.as(EventSub).on('sortable:dblclick', this.onSelectionDoubleClicked.bind(this));
        document.addEventListener('keydown' , (evt) => {
            if (evt.key === 'a' && (evt.ctrlKey || evt.metaKey)) {
                this.fileTable.selectAll();
                evt.preventDefault();
            }
        });
        const sidebar = this.querySelector('#file-details');
        if (sidebar) {
            this.fileDetails = new FileDetailBar(sidebar);
            const bc = this.querySelector('#browse-breadcrumbs');
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

            this.uploadDropper = new UploadDropper(this.querySelector('#upload-drop'));
            this.uploadDropper.as(EventSub).on('dropper:filesdropped', this.onUploadFilesDropped.bind(this));
            this.uploader = new Uploader(this.querySelector('#upload-status'));
            this.uploader.concurrent = 2;
            this.querySelector('#upload-form').addEventListener('change', this.onUploadFilesSelected.bind(this));

            btn = this.querySelector('#action-upload');
            btn.addEventListener('click', this.onToolbarUploadClick.bind(this));
            btn.disabled = false;
            this.querySelector('#action-paste').addEventListener('click', this.onToolbarPasteClick.bind(this));
        }

        if (hasPerms('K') && (btn = this.querySelector('#action-new-folder'))) {
            btn.addEventListener('click', this.onToolbarNewFolderClick.bind(this));
            btn.disabled = false;
        }

        this.onSelectionChanged();
    }

    updateToolbarForSelection(selected, focusedFile) {
        const newButtons = [];
        if (selected.length) {
            newButtons.push(UKButton([UKIcon('close'), `(${selected.length} selected)`],
                {title: 'Clear selection', onclick: this.onToolbarClearSelectionClick.bind(this)}));
            const canRename = focusedFile && !focusedFile.deleted && focusedFile.perms.includes('N');
            newButtons.push(UKButton([UKIcon('pencil')],
                {title: 'Rename file', onclick: this.onToolbarRenameClick.bind(this),
                    disabled: !canRename}));
            newButtons.push(UKButton([UKIcon('copy')],
                {title: 'Copy files', onclick: this.onToolbarCopyMoveClick.bind(this, 'copy')}));
            newButtons.push(UKButton([UKIcon('move')],
                {title: 'Move files', onclick: this.onToolbarCopyMoveClick.bind(this, 'move')}));
            if (hasPerms('D')) {
                if (!every(selected, (row) => row.sortableData.deleted))
                    newButtons.push(UKButton(UKIcon('trash'),
                        {title: 'Delete', onclick: this.onToolbarDeleteClick.bind(this)}));
                if (some(selected, (row) => row.sortableData.deleted)) {
                    newButtons.push(UKButton(UKIcon('history'),
                        {title: 'Restore', onclick: this.onToolbarRestoreClick.bind(this)}));
                    newButtons.push(UKButton(UKIcon('ban'),
                        {title: 'Permanently delete', onclick: this.onToolbarRemoveClick.bind(this)}));
                }
            }
        }
        const toolbar= this.querySelector('#selected-file-actions');
        toolbar.replaceChildren(...newButtons);
    }

    onSelectionChanged() {
        const selected = this.fileTable.getSelectedRows();
        const focused = this.fileTable.getFocusedRow();
        const isSelected = includes(selected, focused);
        const file = isSelected ? focused.sortableData : null;
        this.updateToolbarForSelection(selected, file);
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
        const pasteBtn = this.querySelector('#action-paste');
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
        this.querySelector('#upload-form').click();
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
        if (result) {
            const newBrowseUrl = URI_BASE + '/' + result.path;
            window.location.assign(newBrowseUrl.replace(/\/{2,}/, '/'));
        }
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
        const files = map(selected, (row) => row.sortableData);
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
        const files = map(selected, (row) => row.sortableData).filter((file) => !file.deleted);
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
        const files = map(selected, (row) => row.sortableData).filter((file) => file.deleted);
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
        const files = map(selected, (row) => row.sortableData).filter((file) => file.deleted);
        if (!confirm(`Permanently delete ${files.length} items?`))
            return;
        const result = await apiFetch(`/ajax/file/remove`, {
            files: files.map((file) => file.path)
        });
        if (result && result.result)
            window.location.reload();
    }
}