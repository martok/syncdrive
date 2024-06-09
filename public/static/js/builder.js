
function filterAttributes(tagName, attributes) {
    const booleanPresence = (name) => {
        if (attributes[name] === true)
            attributes[name] = name;
        else
            delete attributes[name];
    };

    switch (tagName) {
        case 'BUTTON':
            booleanPresence('disabled');
            break;
        case 'INPUT':
            booleanPresence('checked');
            break;
        case 'OPTION':
            booleanPresence('selected');
            break;
    }
}

export function EB(tagName, ...attrChildDefs) {
    const el = document.createElement(tagName);

    for (const def of attrChildDefs) {
        // Array of Nodes to add as children
        if (Array.isArray(def)) {
            for (const child of def) {
                if (child instanceof HTMLElement)
                    el.appendChild(child);
                else if (typeof child === 'string') {
                    el.insertAdjacentHTML('beforeend', child);
                }
            }
            // Single HTML Element
        } else if (def instanceof HTMLElement) {
            el.appendChild(def);
            // Plain object containing Attributes
        } else if (def instanceof Object) {
            filterAttributes(el.tagName, def);

            for (const [name, value] of Object.entries(def)) {
                if (name.startsWith('on')) {
                    const event = name.substring(2);
                    el.addEventListener(event, value);
                } else if (name === '$') {
                    el.classList.add(...value.split(' '));
                } else {
                    el.setAttribute(name, value);
                }
            }
            // apply Text content, if not falsy/empty
        } else if (!!def) {
            el.appendChild(document.createTextNode(def));
        }
    }

    if (el.tagName === 'TIME' && el.hasAttribute('datetime')) {
        window.queueMicrotask(() => $(el).timeago());
    }
    return el;
}

export function UKIcon(icon, attributes = {}) {
    const cls = 'onclick' in attributes ? 'uk-icon-link' : 'uk-icon';
    return EB('i', {$: cls, 'uk-icon': icon}, attributes);
}

export function UKButton(content, attributes = {}) {
    return EB('button', {$: 'uk-button uk-button-default'}, attributes, content);
}
