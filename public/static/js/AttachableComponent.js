const MAP_ATTR = "$$AComps";
const ATTR_PREFIX = "ac-";
const CSS_PREFIX = "ac-";

export default class AttachableComponent {
    static debugMode = false;

    static define(name, componentClass) {
        componentRegistry.defineComponent(name, componentClass);
    }

    /**
     * Neither JSDoc nor TypeScript really support static polymorphic this, so we can't just
     * annotate an inheritable function with @return {this}. The only way for now to get correct typing
     * for code completion is by abusing new, which is always typed correctly... even if
     * this means often the returned object won't really be "new".
     *
     * @param {HTMLElement | AttachableComponent} elOrComp
     * @return {AttachableComponent}
     */
    constructor (elOrComp) {
        if (!elOrComp)
            throw new TypeError("Target element for AttachableComponent is null or undefined");
        // prototype of this is the argument of `new`
        const componentClass = classFromInstance(this);
        if (AttachableComponent.debugMode && (elOrComp instanceof HTMLElement)) {
            const expectedAttr = componentRegistry.getAttribute(componentClass);
            if (expectedAttr && !elOrComp.hasAttribute(expectedAttr)) {
                console.warn("Attaching component", componentClass, "to element without annotation attribute: ", elOrComp);
            }
        }
        return findComponent(elOrComp, componentClass, true);
    }

    /**
     * @return {HTMLElement}
     */
    get element() {
        return this.$data["el"];
    }

    get cssClasses() {
        return this.$data["classes"] ??= (componentRegistry.getCSSClassChain(classFromInstance(this)));
    }

    is(componentClass) {
        return findComponent(this, componentClass, false) !== null;
    }

    /**
     * @template T
     * @param {{prototype: T }} componentClass
     * @return {T}
     */
    as(componentClass) {
        return findComponent(this, componentClass, true);
    }

    create(element) {
        this.$data = Object.create(null);
        this.$data['el'] = element;
        for (const cls of this.cssClasses)
            this.element.classList.toggle(cls, true);
    }

    destroy() {
        for (const cls of this.cssClasses)
            this.element.classList.toggle(cls, false);
        detachComponent(this.element, this);
    }

    setup(options) {
    }

    /**
     * @param {string} selectors
     * @return {Element | null}
     */
    querySelector(selectors) {
        return this.element.querySelector(selectors);
    }

    /**
     * @param {string} selectors
     * @return {NodeListOf<Element>}
     */
    querySelectorAll(selectors) {
        return this.element.querySelectorAll(selectors);
    }
}

/**
 *
 * @param {HTMLElement | AttachableComponent} elOrComp
 * @return {Map<Object, AttachableComponent>}
 */

function getComponentMap(elOrComp) {
    if (elOrComp instanceof AttachableComponent) {
        return getComponentMap(elOrComp.element);
    }
    if (elOrComp.hasOwnProperty(MAP_ATTR))
        return elOrComp[MAP_ATTR];
    return (elOrComp[MAP_ATTR] = new Map());
}

function classFromInstance(inst) {
    return Object.getPrototypeOf(inst).constructor;
}

function validateComponentClass(componentClass) {
    if (!(componentClass.prototype instanceof AttachableComponent))
        throw new TypeError("componentClass is not subclass of AttachableComponent");
}


function attachNewComponent(element, map, componentClass)
{
    if (AttachableComponent.debugMode)
        console.log("Attaching", componentClass, "on", element);
    // Create new component. Can't use `new` because we override the constructor!
    const instance = Object.create(componentClass.prototype);
    instance.create(element);
    map.set(componentClass, instance);
    return instance;
}

function detachComponent(element, componentInstance) {
    const map = getComponentMap(element);
    const componentClass = classFromInstance(componentInstance);
    if (AttachableComponent.debugMode)
        console.log("Destroy", element, "using", componentClass);
    map.delete(componentClass);
}

/**
 * @param {HTMLElement | AttachableComponent} elOrComp
 * @param {Object} componentClass
 * @param {boolean} canCreate
 * @return {AttachableComponent | null}
 */
function findComponent(elOrComp, componentClass, canCreate) {
    validateComponentClass(componentClass);

    // normalize argument
    const element = elOrComp instanceof AttachableComponent ? elOrComp.element : elOrComp;

    // If the component already exists, return that
    const map = getComponentMap(elOrComp);
    let instance = map.get(componentClass);
    if (instance)
        return instance;

    if (canCreate) {
        instance = attachNewComponent(element, map, componentClass);
        // If there are any settings given, apply them. This is required for correct
        // handling of out-of-order child initialization
        const attr = componentRegistry.getAttribute(componentClass);
        let settings;
        if (attr && (settings = element.getAttribute(attr)) && (settings = parseSettings(settings)))
            instance.setup(settings);
        return instance;
    }
    return null;
}

class ComponentRegistry {
    constructor() {
        this.attr2Constr = new Map();
        this.constr2Prop = new Map();
    }

    defineComponent(name, componentClass) {
        validateComponentClass(componentClass);

        const attr = ATTR_PREFIX + name;
        this.attr2Constr.set(attr, componentClass);
        this.constr2Prop.set(componentClass, {
            'cssClass': CSS_PREFIX + name,
            'attr': attr,
        })
        if (isInitialized) {
            attachExistingElements(document.body);
        }
    }

    has(attributeName) {
        return this.attr2Constr.has(attributeName);
    }

    getAttributeIter() {
        return this.attr2Constr.entries();
    }

    getCSSSelector() {
        return [...this.attr2Constr.keys()].map(e => `*[${e}]`).join(",");
    }

    getCSSClass(componentClass) {
        return this.constr2Prop.get(componentClass)?.cssClass ?? "";
    }

    getCSSClassChain(componentClass) {
        const chain = [];
        while (componentClass && (componentClass.prototype instanceof AttachableComponent)) {
            const cls = this.getCSSClass(componentClass);
            if (cls)
                chain.push(cls);
            if (!componentClass.prototype)
                break;
            componentClass = classFromInstance(componentClass.prototype);
        }
        chain.reverse();
        return chain;
    }

    getAttribute(componentClass) {
        return this.constr2Prop.get(componentClass)?.attr ?? "";
    }
}

const componentRegistry = new ComponentRegistry();
let isInitialized = false;

function parseSettings(attrValue) {
    try {
        if (!attrValue)
            return {};
        if (attrValue.startsWith("{"))
            return JSON.parse(attrValue);

        return attrValue.split(';').reduce((options, option) => {
            const [key, value] = option.split(/:(.*)/);
            if (key && typeof value !== "undefined") {
                options[key.trim()] = value.trim();
            }
            return options;
        }, {});
    } catch (e) {
        return {};
    }
}

function realizeComponentsFromAttribs(node) {
    const map = getComponentMap(node);
    for (const [attr, constr] of componentRegistry.getAttributeIter()) {
        const wantPresent = node.hasAttribute(attr);
        const isPresent = map.has(constr);
        if (wantPresent && !isPresent) {
            const settings = parseSettings(node.getAttribute(attr));
            const inst = attachNewComponent(node, map, constr);
            inst.setup(settings);
        } else
        if (!wantPresent && isPresent) {
            const inst = map.get(constr);
            inst.destroy();
        }
    }
}

function attachExistingElements(parent) {
    const selectors = componentRegistry.getCSSSelector();
    if (!selectors)
        return;
    const nodes = parent.querySelectorAll(selectors);
    // TODO: might want to re-order so that inner elements get attached first, but if an element
    //       needs its children, it will initialize them on-demand (which also applies attribute config)
    for (const node of nodes) {
        realizeComponentsFromAttribs(node);
    }
}

function applyChildListMutation({ addedNodes, removedNodes }) {
    for (const node of addedNodes) {
        if (node.nodeType !== Node.ELEMENT_NODE)
            continue;
        attachExistingElements(node);
    }
    // don't do anything on removedNodes to allow re-attaching.
}

function applyAttributeMutation({ target, attributeName }) {
    if (!attributeName.startsWith(ATTR_PREFIX))
        return;
    if (!componentRegistry.has(attributeName))
        return;
    realizeComponentsFromAttribs(target);
}

function boot() {
    if (!window.MutationObserver)
        throw new TypeError("Required MutationObserver not supported");

    // Ideally, we'd want a callback immediately after all script modules have executed.
    // queueMicrotask, requestAnimationFrame and setTimeout are all not reliably after
    // any components may be defined...
    window.addEventListener("DOMContentLoaded", () => init());
}

function init() {
    attachExistingElements(document.body);
    isInitialized = true;

    new MutationObserver((records) => records.forEach(applyChildListMutation)).observe(document, {
        subtree: true,
        childList: true,
    });

    new MutationObserver((records) => records.forEach(applyAttributeMutation)).observe(document, {
        subtree: true,
        attributes: true,
    });
}

boot();
