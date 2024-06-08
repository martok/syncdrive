import AttachableComponent from "../AttachableComponent.js";

export default class EventSub extends AttachableComponent {
    /**
     * @param {string} event
     * @param {(eventObject: Object, ...args: any) => any} handler
     */
    on(event, handler) {
        return $(this.element).on(event, handler);
    }

    /**
     * @param {string} event
     * @param {any} args
     */
    trigger(event, ...args) {
        $(this.element).trigger(event, args);
    }
}
