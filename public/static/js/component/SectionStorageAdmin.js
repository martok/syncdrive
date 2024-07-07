import AttachableComponent from "../AttachableComponent.js";
import {apiFetch} from "../apiClient.js";
import {formatFileSize} from "../formatting.js";
import {html} from "../uhtml.js";

export default class SectionStorageAdmin extends AttachableComponent {
    static {
        this.define('section-storage-admin', this);
    }

    create(element) {
        super.create(element);

        this.table = this.querySelector('table');

        for (const row of this.table.querySelectorAll('tbody tr')) {
            const data = JSON.parse(row.dataset.backend);
            row.querySelector('td:nth-child(4) button').addEventListener('click', this.onCalculateSpaceUse.bind(this, row, data));
        }
    }

    async onCalculateSpaceUse(row, backend, evt) {
        const cell = row.querySelector('td:nth-child(4)');
        const btn = cell.querySelector('button');
        const result = await apiFetch(`/admin/ajax/storage/usage`, {
            idx: backend.id,
            class: backend.cls,
        });
        if (result) {
            const total = result.avail < 0 ? -1 : result.avail + (result.used > 0 ? result.used : 0);
            cell.replaceChildren(html`${result.used < 0 ? '?' : formatFileSize(result.used)} / ${total < 0 ? '?' : formatFileSize(total)}`);
        }
    }
}
