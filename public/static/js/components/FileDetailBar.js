import {apiFetch} from "../apiClient.js";
import {formatFileSize} from "../formatting.js";
import {EB, UKButton, UKFormControl, UKIcon} from "../builder.js";


class FileDetailBar {
	constructor () {
		this.sidebar = document.querySelector('div.files-right');
		this.accordion = this.sidebar.querySelector('#file-details');
		this.headerText = this.sidebar.querySelector('#selected-file-name');
		this.infoTable = this.sidebar.querySelector('#selected-file-properties');
		this.versionsList = this.sidebar.querySelector('#selected-file-versions');
		this.sharesActions = this.sidebar.querySelector('#selected-file-shares-actions');
		this.sharesList = this.sidebar.querySelector('#selected-file-shares');
		this.sharesActions.querySelector('#selected-file-shares-new').addEventListener('click', this.onShareNewClick.bind(this));
		this.currentFile = null;
		this.shareEditAbortFunc = null;
		this.setFile(null);
	}

	setVisible(show) {
		this.sidebar.classList.toggle('uk-open', show);
	}

	setTabVisible(tabName, show) {
		const tab = document.getElementById('file-details-tab-' + tabName);
		tab.style.display = show ? '' : 'none';
	}

	navigate(tabName) {
		this.setVisible(true);
		const tab = document.getElementById('file-details-tab-' + tabName);
		UIkit.accordion(this.accordion).toggle(tab);
	}

	_updateTimeago(element, timestamp) {
		$(element).timeago('update', new Date(timestamp * 1000));
	}

	_updateFileProperties() {
		const file = this.currentFile;

		const cell = (row) => this.infoTable.rows[row].cells[1];

		this.infoTable.classList.toggle('file-deleted', !!file.deleted);
		cell(0).innerText = file.isFolder ? '' : formatFileSize(file.size);
		this._updateTimeago(cell(1).firstElementChild, file.modified);
		cell(2).innerText = file.ownerName;
		if (file.deleted)
			this._updateTimeago(cell(3).firstElementChild, file.deleted);
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
			const row = this.versionsList.appendChild(EB('li'));
			const modDate = new Date(version.created * 1000);
			const directLink = `${URI_BASE}/${file.path}?version=${version.id}&ts=${version.created}`;
			row.classList.toggle('row-selected', version.id === result.current);
			row.append(
				EB('div', {$: 'data-row'},
					EB('div', {$: 'data-column-shrink'},
						EB('a', {target: '_blank', title: 'Download version', download: file.name, href: directLink},
							UKIcon('download')
						),
					),
					EB('div',
						EB('time', { datetime: modDate.toISOString() }, modDate.toLocaleString()),
						EB('br'), EB('span', {$: 'uk-text-meta'}, formatFileSize(version.size)),
						EB('br'), EB('span', {$: 'uk-text-meta'}, version.creator),
					),
					EB('div', {$: 'data-column-shrink'},
						version.id === result.current ? null :
							UKIcon('history', {title: 'Restore version',
								'onclick': this.onVersionRestoreClick.bind(this, version)}),
					),
				),
			);
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
			const row = this.sharesList.appendChild(EB('li'));

			let typeIcon;
			const descr = [];

			if (share.editable) {
				typeIcon = share.token ? 'link' : 'users';
				if (share.token) {
					descr.push(EB('a', {href: `/s/${share.token}`}, share.token));
				}
				if (share.sharedWith.length) {
					if (descr.length)
						descr.push(EB('br'));
					descr.push(EB('span', share.sharedWith.join(', ')));
				}
			} else {
				typeIcon = 'user';
				descr.push(EB('span', share.sharedBy));
			}

			row.append(
				EB('div', {$: 'data-row'},
					EB('div', {$: 'data-column-shrink'}, UKIcon(typeIcon)),
					EB('div', descr),
					EB('div', {$: 'data-column-shrink'},
						!share.editable ? null :
							UKIcon('pencil', {onclick: this.onShareEditClick.bind(this, share, row)}),
					),
				),
			);
		}
	}

	setFile(fileInfo, autoCollapse=true) {
		if (!fileInfo) {
			this.headerText.innerText = '';
			this.currentFile = null;
		} else {
			this.headerText.innerText = fileInfo.name;
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
		form.append(UKFormControl('Share with:', [
			EB('textarea', {$: 'uk-textarea uk-form-small', name: 'sharedList'},
				initialList.join('\n'))
		]));
		form.append(EB('fieldset', {$: 'uk-fieldset'},
			EB('legend', {$: 'uk-legend'},
				'Public Link: ',
				EB('input', {$: 'uk-checkbox', type: 'checkbox', name: 'publicLink',
					checked: !!existingShare?.token})
			),
			(!existingShare) ? null :
				EB('input', {$: 'uk-input uk-form-small', type: 'text', name: 'customLink',
					value: existingShare?.token || '', placeholder: '(Auto-generated)',
					oninput: (e) => e.target.form.publicLink.checked = true}),
			UKFormControl('Password protection:', [
				EB('label', {$: 'uk-form-label'},
					EB('input', {$: 'uk-checkbox', type: 'checkbox', name: 'passwordEnabled',
						checked: existingShare?.hasPassword}),
					' Require password'
				),
				EB('input', {$: 'uk-input uk-form-small', type: 'password', name: 'passwordNew',
					placeholder: 'New Password',
					oninput: (e) => e.target.form.passwordEnabled.checked = !!e.target.value}),
			]),
			UKFormControl('Presentation:', [
				EB('select', {$: 'uk-select uk-form-small', name: 'presentation'},
					SHARE_PUBLIC_PRESENTATIONS.map((pres) =>
						EB('option', {value: pres, selected: pres === existingShare?.presentation}, pres))
				)
			]),
		));
		form.append(EB('fieldset', {$: 'uk-fieldset'},
			EB('legend', {$: 'uk-legend'}, 'Permissions'),
			EB('label', {$: 'uk-form-label'},
				EB('input', {$: 'uk-checkbox', type: 'checkbox', name: 'permsModify',
					checked: Array.prototype.some.call('WNVCK', p => initalPerms.includes(p))}),
				' Modify files'
			),
			EB('label', {$: 'uk-form-label'},
				EB('input', {$: 'uk-checkbox', type: 'checkbox', name: 'permsDelete',
					checked: initalPerms.includes('D')}),
				' Delete files'
			),
			EB('label', {$: 'uk-form-label'},
				EB('input', {$: 'uk-checkbox', type: 'checkbox', name: 'permsReshare',
					checked: initalPerms.includes('R')}),
				' Re-share'
			),
		));
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

			const toolbar = EB('div', {$: 'uk-clearfix'},
				!existingShare ? null :
					UKButton([UKIcon('ban'), 'Unshare'],
						{$: 'uk-button-small uk-float-left uk-text-danger', 'onclick': onUnshareClick.bind(this)}),
				UKButton(UKIcon('close'),
					{$: 'uk-button-small uk-float-right', 'onclick': onCancelClick.bind(this)}),
				UKButton(UKIcon('check'),
					{$: 'uk-button-small uk-float-right', 'onclick': onConfirmClick.bind(this)})
			);

			const form = EB('form', {$: 'uk-form-stacked'});
			collectPayloadFunc = this._buildShareEditor(form, existingShare);

			container.classList = 'share-editor';
			container.append(toolbar, form);
		});
	}

	async onShareNewClick() {
		this.editShareAbort();
		const container = this.sharesActions.insertAdjacentElement('afterend', EB('div'));
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
		const container = contentRow.appendChild(EB('div'));
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


export {
	FileDetailBar
}