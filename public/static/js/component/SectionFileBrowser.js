import {html} from "../uhtml.js";
import {apiFetch} from "../apiClient.js";
import {every, includes, map, some} from "../containers.js";
import AttachableComponent from "../AttachableComponent.js";
import EventSub from "./EventSub.js";
import FileDetailBar from "./FileDetailBar.js";
import FileTable from "./FileTable.js";
import Uploader from "./Uploader.js";
import UploadDropper from "./UploadDropper.js";


const LS_COPIED_FILES = 'browse:copied';
const MEDIA_FILE_EXT = /\.(?:png|gif|jpe?g|bmp|tiff?|webp|ico)$/i;

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
                bc.lastElementChild.append(html`<i uk-icon="icon: info; ratio: 0.8" class="uk-icon-link uk-margin-small-left" title="Show details" @click=${this.onEditCurrentClick.bind(this)}/>`);
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

        for (const link of this.querySelectorAll('a.file-link')) {
            link.addEventListener('click', this.onFileLinkClicked.bind(this));
        }

        this.onSelectionChanged();
    }

    updateToolbarForSelection(selected, focusedFile) {
        const newButtons = [];
        if (selected.length) {
            const canRename = focusedFile && !focusedFile.deleted && focusedFile.perms.includes('N');
            newButtons.push(html`
                <button class="uk-button uk-button-default" title="Clear selection" @click=${this.onToolbarClearSelectionClick.bind(this)}><i uk-icon="close"/>(${selected.length} selected)</button>
                <button class="uk-button uk-button-default" title="Rename file" @click=${this.onToolbarRenameClick.bind(this)} ?disabled=${!canRename}><i uk-icon="pencil"/></button>
                <button class="uk-button uk-button-default" title="Copy files" @click=${this.onToolbarCopyMoveClick.bind(this, 'copy')}><i uk-icon="copy"/></button>
                <button class="uk-button uk-button-default" title="Move files" @click=${this.onToolbarCopyMoveClick.bind(this, 'move')}><i uk-icon="move"/></button>
            `);
            if (hasPerms('D')) {
                if (!every(selected, (row) => row.sortableData.deleted))
                    newButtons.push(html`<button class="uk-button uk-button-default" title="Delete" @click=${this.onToolbarDeleteClick.bind(this)}><i uk-icon="trash"/></button>`);
                if (some(selected, (row) => row.sortableData.deleted)) {
                    newButtons.push(html`
                        <button class="uk-button uk-button-default" title="Restore" @click=${this.onToolbarRestoreClick.bind(this)}><i uk-icon="history"/></button>
                        <button class="uk-button uk-button-default" title="Permanently delete" @click=${this.onToolbarRemoveClick.bind(this)}><i uk-icon="ban"/></button>
                    `);
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

    _buildGallery() {
        if (document.querySelector('.gallery-container')) {
            return;
        }
        const container = html`<div class="gallery-container">
            <div class="gallery-controls">
                <button @click=${this.onGalleryFullscreenClick.bind(this)} title="Fullscreen"><i uk-icon="expand"/></button>
                <button @click=${this.onGalleryCloseClick.bind(this)} title="Close"><i uk-icon="close"/></button>
            </div>
            <div class="swiper swiper-main">
                <div class="swiper-wrapper"/>
				<div class="swiper-button-next"></div>
				<div class="swiper-button-prev"></div>
            </div>
            <div class="bevel-divider"><button class="bevel-button" @click=${this.onGalleryThumbBevelClick.bind(this)} title="Show/Hide Thumbnails">&bullet; &bullet; &bullet;</button></div>
            <div class="swiper swiper-thumbs" thumbsSlider=""><div class="swiper-wrapper"/></div>
        </div>`;
        document.body.appendChild(container);
        const main = container.querySelector('.swiper-main');
        const thumbs = container.querySelector('.swiper-thumbs');
        const mw = main.querySelector('.swiper-wrapper');
        const tw = thumbs.querySelector('.swiper-wrapper');
        for (const row of this.fileTable.getRows()) {
            const file = row.sortableData;
            if (!this._isMediaType(file))
                continue;
            const url = row.querySelector('.file-link')?.href;
            const tnUrl = row.querySelector('.thumbnail-container img')?.dataset.src;
            if (!tnUrl || !url)
                continue;
            mw.appendChild(html`<div class="swiper-slide" .file=${file}>
                <div class="gallery-header"><span class="gallery-filename">${file.name}</span></div>
                <div class="swiper-zoom-container"><img data-src=${url}></div>
            </div>`);
            tw.appendChild(html`<div class="swiper-slide">
				<div class="gallery-header"><span class="gallery-filename">${file.name}</span></div>
                <img data-src=${tnUrl}>
            </div>`);
        }
        const thSwiper = new Swiper(thumbs, {
            spaceBetween: 10,
            slidesPerView: 5,
            freeMode: true,
            watchSlidesProgress: true,
        });
        const mainSwiper = new Swiper(main, {
            spaceBetween: 10,
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            keyboard: {
                enabled: true,
            },
            zoom: true,
            thumbs: {
                swiper: thSwiper,
            },
        });
        for (const thumbnail of container.querySelectorAll('.swiper-slide img')) {
            UIkit.scrollspy(thumbnail);
            UIkit.util.on(thumbnail, 'inview', this.fileTable.onThumbnailViewed);
        }
    }

    _navigateGalleryTo(file) {
        const main = document.querySelector('.gallery-container .swiper-main');
        if (!main)
            return;
        const mainSwiper = main.swiper;
        for (let i=0; i<mainSwiper.slides.length; i++) {
            if (mainSwiper.slides[i].file === file) {
                mainSwiper.slideTo(i, 0, false);
                return;
            }
        }
    }

    _isMediaType(file) {
        return MEDIA_FILE_EXT.test(file.name);
    }

    onFileLinkClicked(e) {
        const link = e.target;
        const file = link.closest('tr').sortableData;
        if (this._isMediaType(file)) {
            e.preventDefault();
            this._buildGallery();
            this._navigateGalleryTo(file);
        }
    }

    onGalleryCloseClick() {
        const gallery = document.querySelector('.gallery-container');
        const mainSwiper = gallery.querySelector('.swiper-main').swiper;
        const active = mainSwiper.slides[mainSwiper.activeIndex];
        for (const row of this.fileTable.getRows()) {
            if (row.sortableData === active.file) {
                this.fileTable.setFocusedRow(row);
                break;
            }
        }
        gallery.remove();
    }

    onGalleryThumbBevelClick() {
        const gallery = document.querySelector('.gallery-container');
        $('.swiper-thumbs', gallery).toggle();
    }

    onGalleryFullscreenClick() {
        const gallery = document.querySelector('.gallery-container');
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            gallery.requestFullscreen();
        }
    }

}