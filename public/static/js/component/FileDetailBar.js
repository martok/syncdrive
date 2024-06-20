import {apiFetch} from "../apiClient.js";
import {some} from "../containers.js";
import {formatFileSize} from "../formatting.js";
import {html} from "../uhtml.js";
import AttachableComponent from "../AttachableComponent.js";

export default class FileDetailBar extends AttachableComponent {
    static {
        this.define('file-detail-bar', this);
    }

    create(element) {
        super.create(element);

        this.sidebar = this.element.parentElement;
        this.sidebarHeader = this.sidebar.querySelector('#files-right-title');
        this.infoTable = this.querySelector('#selected-file-properties');
        this.versionsList = this.querySelector('#selected-file-versions');
        this.sharesActions = this.querySelector('#selected-file-shares-actions');
        this.sharesList = this.querySelector('#selected-file-shares');
        this.sharesActions.querySelector('#selected-file-shares-new').addEventListener('click', this.onShareNewClick.bind(this));
        this.currentFile = null;
        this.shareEditAbortFunc = null;
        this.setFile(null);
    }

    setVisible(show) {
        this.sidebar.classList.toggle('uk-open', show);
    }

    setTabVisible(tabName, show) {
        const tab = this.querySelector('#file-details-tab-' + tabName);
        tab.style.display = show ? '' : 'none';
    }

    navigate(tabName) {
        this.setVisible(true);
        const tab = this.querySelector('#file-details-tab-' + tabName);
        UIkit.accordion(this.element).toggle(tab);
    }

    _updateTimeago(element, timestamp) {
        $(element).timeago('update', new Date(timestamp * 1000));
    }

    _updateFileProperties() {
        const file = this.currentFile;

        const cell = (row) => this.querySelector('#file-details-field-' + row);

        this.infoTable.classList.toggle('file-deleted', !!file.deleted);
        cell('size').innerText = formatFileSize(file.size) + (file.isFolder ? ' total' : '');
        this._updateTimeago(cell('modified').firstElementChild, file.modified);
        cell('owner').innerText = file.ownerName;
        cell('perm').innerText = file.perms;
        if (file.deleted)
            this._updateTimeago(cell('deleted').firstElementChild, file.deleted);
    }

    async _updateFileVersions() {
        const file = this.currentFile

        this.setTabVisible('versions', !file.isFolder);
        this.versionsList.innerHTML = '';
        if (file.isFolder)
            return;

        const result = await apiFetch(`/ajax/version/list`, {
            file: file.path
        });
        if (!result)
            return;
        for (const version of result.versions) {
            const modDate = new Date(version.created * 1000);
            const directLink = `${URI_BASE}/${file.path}?version=${version.id}&ts=${version.created}`;
            const isCurrent = version.id === result.current;

            const row = html`
                <li class=${isCurrent ? "row-selected":""}>
                    <div class="data-row">
                        <div class="data-column-shrink">
                            <a target="_blank" title="Download version" download=${file.name} href=${directLink}><i uk-icon="download"/></a>
                        </div>
                        <div>
                            <time is="time-ago" datetime=${modDate.toISOString()}>${modDate.toLocaleString()}</time>
                            <br>
                            <span class="uk-text-meta">${formatFileSize(version.size)}</span>
                            <br>
                            <span class="uk-text-meta">${version.creator}</span>
                        </div>
                        <div class="data-column-shrink">${isCurrent ? null :
                            html`<i uk-icon="history" class="uk-icon-link" @click=${this.onVersionRestoreClick.bind(this, version)}/>`}
                        </div>
                    </div>
                </li>
            `;
            this.versionsList.append(row);
        }
    }

    async _updateFileShares() {
        const file = this.currentFile

        this.sharesList.innerHTML = '';

        const result = await apiFetch(`/ajax/share/list`, {
            file: file.path
        });
        if (!result)
            return;

        this.sharesActions.style.display = result.canShare ? '' : 'none';

        for (const share of result.shares) {
            let typeIcon;
            const descr = [];
            if (share.editable) {
                typeIcon = share.token ? 'link' : 'users';
                if (share.token) {
                    descr.push(html`<a href=${`/s/${share.token}`}>${share.token}</a>`);
                }
                if (share.sharedWith.length) {
                    if (descr.length)
                        descr.push(html`<br>`);
                    descr.push(html`<span>${share.sharedWith.join(', ')}</span>`);
                }
            } else {
                typeIcon = 'user';
                descr.push(html`<span>${share.sharedBy}</span>`);
            }

            const row = html`<li/>`;
            row.appendChild(html`
                <div class="data-row">
                    <div class="data-column-shrink"><i uk-icon=${typeIcon}/></div>
                    <div>${descr}</div>
                    <div class="data-column-shrink">${!share.editable ? null : 
                        html`<i uk-icon="pencil" class="uk-icon-link" @click=${this.onShareEditClick.bind(this, share, row)}/>`}
                    </div>
                </div>
            `);
            this.sharesList.appendChild(row);
        }
    }

    setFile(fileInfo, autoCollapse=true) {
        if (!fileInfo) {
            this.sidebar.classList.add('forced-closed');
            this.sidebarHeader.innerText = '';
            this.currentFile = null;
        } else {
            this.sidebar.classList.remove('forced-closed');
            this.sidebarHeader.innerText = fileInfo.name;
            this.currentFile = fileInfo;
            this._updateFileProperties();
            this._updateFileVersions();
            this._updateFileShares();
        }

        if (autoCollapse) {
            this.setVisible(!!fileInfo);
        }
    }

    async onVersionRestoreClick(version) {
        const result = await apiFetch(`/ajax/version/restore`, {
            file: this.currentFile.path,
            version: version.id,
            ts: version.created
        });
        if (result) {
            window.location.reload();
        }
    }

    editShareAbort() {
        if (this.shareEditAbortFunc) {
            const fn = this.shareEditAbortFunc;
            this.shareEditAbortFunc = null;
            fn();
        }
    }

    _buildShareEditor(form, existingShare) {
        const initialList = existingShare ? [...existingShare.sharedWith] : [];
        const initalPerms = existingShare?.perms ?? '';
        form.append(html`
            <div>
                <label class="uk-form-label">Share with:</label>
                <div class="uk-form-controls">
                    <textarea class="uk-textarea uk-form-small" name="sharedList">${initialList.join('\n')}</textarea>
                </div>
            </div>
            <fieldset class="uk-fieldset">
                <legend class="uk-legend">Public Link: <input class="uk-checkbox" type="checkbox" name="publicLink" ?checked="${!!existingShare?.token}"></legend>
                ${!existingShare ? null : html`
                    <input class="uk-input uk-form-small" type="text" name="customLink" value=${existingShare?.token || ''} placeholder="(Auto-generated)"
                           @input=${(e) => e.target.form.publicLink.checked = true}>
                `}
				<div>
					<label class="uk-form-label">Password protection:</label>
					<div class="uk-form-controls">
                        <label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="passwordEnabled" ?checked=${existingShare?.hasPassword}> Require password</label>
                        <input class="uk-input uk-form-small" type="password" name="passwordNew" placeholder="New Password"
                               @input=${(e) => e.target.form.passwordEnabled.checked = !!e.target.value}>
					</div>
				</div>
				<div>
					<label class="uk-form-label">Presentation:</label>
					<div class="uk-form-controls">
                        <select class="uk-select uk-form-small" name="presentation">
                            ${SHARE_PUBLIC_PRESENTATIONS.map(
                                (pres) => html`<option value=${pres} ?selected=${pres === existingShare?.presentation}>${pres}</option>`)}
                        </select>
					</div>
				</div>
            </fieldset>
			<fieldset class="uk-fieldset">
				<legend class="uk-legend">Permissions</legend>
                <label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="permsModify"
                                                    ?checked=${some('WNVCK', p => initalPerms.includes(p))}> Modify files</label>
				<label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="permsDelete"
													?checked=${initalPerms.includes('D')}> Delete files</label>
				<label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="permsReshare"
													?checked=${initalPerms.includes('R')}> Re-share</label>
            </fieldset>
        `);
        return (payload) => {
            const newList = form.sharedList.value.split('\n').filter((v) => !!v);
            const added = newList.filter((e) => !initialList.includes(e));
            const removed = initialList.filter((e) => !newList.includes(e));
            if (added.length)
                payload['addShare'] = added;
            if (removed.length)
                payload['removeShare'] = removed;
            if (form.publicLink.checked !== !!existingShare?.token)
                payload['publicLink'] = form.publicLink.checked;
            const oldToken = existingShare?.token || '';
            if (form.publicLink.checked && form.customLink && (form.customLink.value !== oldToken))
                payload['customLink'] = form.customLink.value;
            if ((form.passwordEnabled.checked !== existingShare?.hasPassword) ||
                form.passwordNew.value) {
                if (form.passwordEnabled.checked) {
                    if (form.passwordNew.value) {
                        payload['setPassword'] = form.passwordNew.value;
                    }
                } else
                    payload['clearPassword'] = true;
            }
            payload['presentation'] = form.presentation.value;
            payload['perms'] = [form.permsModify.checked ? 'WNVCK' : '',
                form.permsDelete.checked ? 'D' : '',
                form.permsReshare.checked ? 'R' : '',
            ].join('')
        };
    }

    editShare(container, existingShare) {
        return new Promise((resolve, reject) => {
            const onUnshareClick = async () => {
                if (!existingShare)
                    return reject();
                const result = await apiFetch(`/ajax/share/remove`, {
                    path: this.currentFile.path,
                    share: existingShare.id,
                });
                if (result) {
                    return resolve();
                }
                return reject();
            };
            let collectPayloadFunc = null;
            const onConfirmClick = async () => {
                const payload = {
                    path: this.currentFile.path,
                };
                collectPayloadFunc(payload);
                let result = null;
                if (existingShare) {
                    result = await apiFetch(`/ajax/share/edit`, Object.assign(payload, {share: existingShare.id}));
                } else {
                    result = await apiFetch(`/ajax/share/new`, payload);
                }
                if (result) {
                    return resolve();
                }
                return reject();
            };
            const onCancelClick = async () => {
                reject();
            };
            this.shareEditAbortFunc = onCancelClick;

            const toolbar = html`
                <div class="uk-clearfix">
					${!existingShare ? null: 
                        html`<button class="uk-button uk-button-default uk-button-small uk-float-left uk-text-danger" @click="${onUnshareClick.bind(this)}"><i uk-icon="ban"/>Unshare</button>`
                    }
					<button class="uk-button uk-button-default uk-button-small uk-float-right" @click="${onCancelClick.bind(this)}"><i uk-icon="close"/></button>
					<button class="uk-button uk-button-default uk-button-small uk-float-right" @click="${onConfirmClick.bind(this)}"><i uk-icon="check"/></button>
                </div>
            `;

            const form = html`<form class="uk-form-stacked" />`;
            collectPayloadFunc = this._buildShareEditor(form, existingShare);

            container.classList = 'share-editor';
            container.append(toolbar, form);
        });
    }

    async onShareNewClick() {
        this.editShareAbort();
        const container = this.sharesActions.insertAdjacentElement('afterend', html`<div/>`);
        try {
            await this.editShare(container, null);
            await this._updateFileShares();
        } catch (e) {
        } finally {
            container.remove();
        }
    }

    async onShareEditClick(share, contentRow) {
        this.editShareAbort();
        const editButton = contentRow.querySelector(':scope .data-row div:last-of-type i');
        const container = contentRow.appendChild(html`<div/>`);
        editButton.style.visibility = 'hidden';
        try {
            await this.editShare(container, share);
            await this._updateFileShares();
        } catch (e) {
        } finally {
            editButton.style.visibility = '';
            container.remove();
        }
    }
}
