import AttachableComponent from "../AttachableComponent.js";
import EventSub from "./EventSub.js";

export default class UploadDropper extends AttachableComponent {
    static {
        this.define('upload-dropper', this);
    }

    create(element) {
        super.create(element);
        this.dragTargets = [];
    }

    setup(options) {
        let {dragTargets = ""} = options;
        if (typeof dragTargets === "string") {
            if (dragTargets)
                dragTargets = [...document.querySelectorAll(dragTargets)];
            else
                dragTargets = [];
        }
        if (!Array.isArray(dragTargets))
            dragTargets = [dragTargets];
        if (!dragTargets.includes(this.element))
            dragTargets.unshift(this.element);
        for (const target of dragTargets) {
            target.addEventListener('dragenter', (e) => {
                this.element.classList.add('drag-over');
                e.preventDefault();
                e.stopPropagation();
            });
            target.addEventListener('dragover', (e) => {
                this.element.classList.add('drag-over');
                e.preventDefault();
                e.stopPropagation();
            });
            target.addEventListener('dragleave', (e) => {
                this.element.classList.remove('drag-over');
                e.preventDefault();
                e.stopPropagation();
            });
            target.addEventListener('drop', this.onFileDrop.bind(this));
        }
        this.dragTargets = dragTargets;
    }

    onFileDrop(event) {
        event.preventDefault();
        event.stopPropagation();
        if (!event.dataTransfer ||
            !event.dataTransfer.files)
            return;
        this.as(EventSub).trigger('dropper:filesdropped', event.dataTransfer.files);
        this.element.classList.remove('drag-over');
    }
}