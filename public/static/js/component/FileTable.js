import SorTable from "./SorTable.js";

export default class FileTable extends SorTable {
    static {
        this.define('file-table', this);
    }

    create(element) {
        super.create(element);

        this.autoSelect = true;
        this.setColumn(0, { compare: this.sortFilenameColumn.bind(this, 'name') });
        this.setColumn(1, { compare: this.sortDateColumn.bind(this, 'modified') });
        this.setColumn(2, { compare: this.sortFilesizeColumn.bind(this, 'size') });
        this.sortBy(0);
        for (const thumbnail of this.querySelectorAll('.thumbnail-container img')) {
            UIkit.scrollspy(thumbnail);
            UIkit.util.on(thumbnail, 'inview', this.onThumbnailViewed.bind(this));
        }
    }

    _foldersOnTop(left, right, asc) {
        if (left.isFolder !== right.isFolder) {
            if (asc) {
                if (left.isFolder)
                    return -1;
                return 1;
            } else {
                if (left.isFolder)
                    return 1;
                return -1;
            }
        }
        return 0;
    }

    sortFilenameColumn(field, ci, left, right, asc) {
        const dl = left.sortableData;
        const dr = right.sortableData;
        const res = this._foldersOnTop(dl, dr, asc);
        if (res)
            return res;
        return dl[field].localeCompare(dr[field]);
    }

    sortDateColumn(field, ci, left, right, asc) {
        const dl = left.sortableData;
        const dr = right.sortableData;
        const res = this._foldersOnTop(dl, dr, asc);
        if (res)
            return res;
        return dl[field] - dr[field];
    }

    sortFilesizeColumn(field, ci, left, right, asc) {
        const dl = left.sortableData;
        const dr = right.sortableData;
        const res = this._foldersOnTop(dl, dr, asc);
        if (res)
            return res;
        return dl[field] - dr[field];
    }

    onThumbnailViewed(e) {
        const img = e.target;
        if (img instanceof HTMLImageElement && !img.src && img.dataset.src) {
            img.src = img.dataset.src;
        }
    }
}