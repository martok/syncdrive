const defaultBase = class { };


function compose(...traits) {
	return extend(defaultBase, ...traits);
}

function extend(extendsClass, ...traits) {
	return traits.reduce((parent, trait) => trait(parent), extendsClass);
}

const EventSubTrait = (Base = defaultBase) =>
	class EventSubTrait extends Base {
		get eventSubTarget() {
			return this._eventSubTarget ??= $({});
		}

		on(event, handler) {
			return this.eventSubTarget.on(event, handler);
		}

		trigger(event, ...args) {
			this.eventSubTarget.trigger(event, args);
		}
	};


export {
	compose,
	extend,
	EventSubTrait,
};