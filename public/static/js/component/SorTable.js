import AttachableComponent from "../AttachableComponent.js";
import EventSub from "./EventSub.js";

export default class SorTable extends AttachableComponent {
    static {
        this.define('sortable', this);
    }

    create(element) {
        super.create(element);

        if (element.tagName !== 'TABLE')
            throw new TypeError('SorTable argument is not a table element.');
        this._thead = element.tHead.rows[0];
        this._tbody = element.tBodies[0];
        this._columns = [];
        this.sortedBy = -1;
        this.sortedAsc = false;
        this._autoSelect = false;
        this._multiSelect = false;
        this._decorateHead();
        this._decorateBody();
        this._updateHead();
    }

    get autoSelect() { return this._autoSelect; }
    set autoSelect(value) {
        this._autoSelect = value;
    }

    get multiSelect() { return this._multiSelect; }
    set multiSelect(value) {
        if (!value) {
            let first = true;
            for (const row of this._tbody.rows) {
                if (!first)
                    row.classList.remove('row-selected');
                first = false;
            }
        }
        this._multiSelect = value;
    }

    _decorateHead() {
        for (const cell of this._thead.cells) {
            const colIndex = this._columns.push({
                head: cell,
                sortable: true,
                compare: this._defaultCompare,
                toDisplay: this._defaultDisplay,
            }) - 1;
            cell.innerHTML += '<i class="sortable-arrow-up" uk-icon="chevron-up"></i>' +
                '<i class="sortable-arrow-down" uk-icon="chevron-down"></i>';
            cell.addEventListener('click', () => this.sortBy(colIndex, this.sortedBy == colIndex ? !this.sortedAsc : true));
        }
    }

    _updateHead() {
        this._columns.forEach((colDef, colIndex) => {
            const cell = this._thead.cells[colIndex];
            cell.classList.toggle('column-sortable', colDef.sortable)
            cell.classList.toggle('column-sorted', this.sortedBy === colIndex);
            cell.classList.toggle('column-sorted-desc', !this.sortedAsc);
        });
    }

    _decorateBody() {
        for (const row of this._tbody.rows) {
            this._columns.forEach((colDef, colIndex) => {
                const cell = row.cells[colIndex];
                cell.innerHTML = colDef.toDisplay(colIndex, row, cell.innerHTML);
            });
            if ('sortableRow' in row.dataset) {
                row.sortableData = JSON.parse(row.dataset.sortableRow);
            }
            row.addEventListener('click', this._onRowClicked.bind(this, row));
            row.addEventListener('dblclick', this._onRowDblClicked.bind(this, row));
        }
    }

    _onRowClicked(row, event) {
        if (this.autoSelect) {
            const oldSelected = new Set(this.getSelectedRows());
            if (this.multiSelect && (event.shiftKey || event.ctrlKey)) {
                if (event.shiftKey) {
                    const foc = this.getFocusedRow();
                    if (foc) {
                        const i1 = Array.prototype.indexOf.call(this._tbody.children, foc);
                        const i2 = Array.prototype.indexOf.call(this._tbody.children, row);
                        const range = Array.prototype.slice.call(this._tbody.children, Math.min(i1, i2), Math.max(i1, i2) + 1);
                        for (const row of range)
                            row.classList.add('row-selected');
                    }
                } else if (event.ctrlKey) {
                    row.classList.toggle('row-selected');
                }
            } else {
                if (oldSelected.size > 1 || !oldSelected.has(row)) {
                    this.deselectAll();
                    row.classList.add('row-selected');
                }
            }
            const newSelected = new Set(this.getSelectedRows());
            this._setFocusedRow(newSelected.has(row) ? row : null);
            if (oldSelected.size !== newSelected.size ||
                (new Set([...oldSelected, ...newSelected])).size !== oldSelected.size) {
                this.as(EventSub).trigger('sortable:selectionchanged');
            }
        }
    }

    _onRowDblClicked(row, event) {
        this.as(EventSub).trigger('sortable:dblclick');
    }

    _defaultCompare(columnIndex, rowLeft, rowRight, asc) {
        const cellLeft = rowLeft.cells[columnIndex].innerText;
        const cellRight = rowRight.cells[columnIndex].innerText;
        if (cellLeft < cellRight) {
            return -1;
        } else if (cellRight < cellLeft) {
            return 1;
        }
        return 0;
    }

    _defaultDisplay(columnIndex, row, currentValue) {
        return currentValue;
    }

    setColumn(columnIndex, options = {}) {
        const {sortable, compare, toDisplay} = options;
        if (typeof sortable !== 'undefined') {
            this._columns[columnIndex].sortable = !!sortable;
            this._updateHead();
        }
        if (typeof compare !== 'undefined')
            this._columns[columnIndex].compare = compare;
        if (typeof toDisplay !== 'undefined')
            this._columns[columnIndex].toDisplay = toDisplay;

    }

    sortBy(columnIndex, asc=true) {
        if (typeof this._columns[columnIndex] == 'undefined' || !this._columns[columnIndex].sortable)
            return;
        const rows = [... this._tbody.rows];
        const compare = (left, right) => this._columns[columnIndex].compare(columnIndex, left, right, asc);
        rows.sort(compare);
        if (!asc)
            rows.reverse();
        this._tbody.append(...rows);
        this.sortedBy = columnIndex;
        this.sortedAsc = asc;
        this._updateHead();
    }

    getSelectedRows() {
        return this._tbody.querySelectorAll(':scope > .row-selected');
    }

    _setFocusedRow(row) {
        for (const row of this._tbody.querySelectorAll(':scope > .row-focused'))
            row.classList.remove('row-focused');
        if (row)
            row.classList.add('row-focused');
    }

    getFocusedRow() {
        return this._tbody.querySelector(':scope > .row-focused');
    }

    selectAll() {
        for (const row of this._tbody.rows)
            row.classList.add('row-selected');
        this.as(EventSub).trigger('sortable:selectionchanged');
    }

    deselectAll() {
        for (const row of this._tbody.rows)
            row.classList.remove('row-selected');
        this.as(EventSub).trigger('sortable:selectionchanged');
    }
}