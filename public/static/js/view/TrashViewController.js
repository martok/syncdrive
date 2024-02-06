import {FileTable} from "../components/FileTable.js";
import {SorTable} from "../components/SorTable.js";


(function () {

    class TrashViewController {
        constructor() {
            this.fileTable = new SorTable(document.getElementById('trash-list'));
            this.fileTable.setColumn(0, { compare: FileTable.prototype.sortFilenameColumn.bind(this, 'name') });
            this.fileTable.setColumn(1, { compare: FileTable.prototype.sortFilenameColumn.bind(this, 'originDisplay') });
            this.fileTable.setColumn(2, { compare: FileTable.prototype.sortDateColumn.bind(this, 'deleted') });
            this.fileTable.setColumn(3, { compare: FileTable.prototype.sortFilesizeColumn.bind(this, 'size') });
            this.fileTable.sortBy(2, false);
        }

        _foldersOnTop(left, right, asc) {
            return 0;
        }
    }

    const controller = new TrashViewController();
})();
