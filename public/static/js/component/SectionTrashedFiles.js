import AttachableComponent from "../AttachableComponent.js";
import FileTable from "./FileTable.js";
import SorTable from "./SorTable.js";

export default class SectionTrashedFiles extends AttachableComponent {
    static {
        this.define('section-trashed-files', this);
    }

    create(element) {
        super.create(element);

        this.fileTable = new SorTable(this.querySelector('#trash-list'));
        this.fileTable.setColumn(0, { compare: FileTable.prototype.sortFilenameColumn.bind(this, 'name') });
        this.fileTable.setColumn(1, { compare: FileTable.prototype.sortFilenameColumn.bind(this, 'originDisplay') });
        this.fileTable.setColumn(2, { compare: FileTable.prototype.sortDateColumn.bind(this, 'deleted') });
        this.fileTable.setColumn(3, { compare: FileTable.prototype.sortFilesizeColumn.bind(this, 'size') });
        this.fileTable.sortBy(2, false);
    }

    // used by binding the sort functions to `this`
    _foldersOnTop(left, right, asc) {
        return 0;
    }
}
