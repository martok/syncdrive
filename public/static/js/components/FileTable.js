import {SorTable} from "./SorTable.js";


export class FileTable extends SorTable {
	constructor(tableElement) {
		super(tableElement);
		this.autoSelect = true;
		this.setColumn(0, { compare: this.sortFilenameColumn.bind(this) });
		this.setColumn(1, { compare: this.sortDateColumn.bind(this) });
		this.setColumn(2, { compare: this.sortFilesizeColumn.bind(this) });
		this.sortBy(0);
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

	sortFilenameColumn(ci, left, right, asc) {
		const dl = left.sortableData;
		const dr = right.sortableData;
		const res = this._foldersOnTop(dl, dr, asc);
		if (res)
			return res;
		if (dl.name < dr.name)
			return -1;
		if (dr.name < dl.name)
			return 1;
		return 0;
	}

	sortDateColumn(ci, left, right, asc) {
		const dl = left.sortableData;
		const dr = right.sortableData;
		const res = this._foldersOnTop(dl, dr, asc);
		if (res)
			return res;
		return dl.modified - dr.modified;
	}

	sortFilesizeColumn(ci, left, right, asc) {
		const dl = left.sortableData;
		const dr = right.sortableData;
		const res = this._foldersOnTop(dl, dr, asc);
		if (res)
			return res;
		return dl.size - dr.size;
	}
}