/**
 * @template T
 * @typedef {T[] | Iterable<T> | NodeListOf<T> | string | HTMLCollectionOf<T>} ArrayLike<T>
 */

/**
 * @template T, U
 * @typedef {(value: T, index: number, array: T[]) => U} MapCallable
 */

/**
 * Calls a defined callback function on each element of an array, and returns an array that contains the results.
 * @template T, U
 * @param {ArrayLike<T>} list
 * @param {MapCallable<T, U>} mapper
 * @return {U[]}
 */
export function map(list, mapper) {
    return Array.prototype.map.call(list, mapper);
}

/**
 * Determines whether all the members of an array satisfy the specified test.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {MapCallable<T, any>} pred
 * @return {boolean}
 */
export function every(list, pred) {
    return Array.prototype.every.call(list, pred);
}

/**
 * Returns the elements of an array that meet the condition specified in a callback function.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {MapCallable<T, any>} pred
 * @return {ArrayLike<T>}
 */
export function filter(list, pred) {
    return Array.prototype.filter.call(list, pred);
}

/**
 * Determines whether an array includes a certain element, returning true or false as appropriate.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {T} searchElement
 * @return {boolean}
 */
export function includes(list, searchElement) {
    return Array.prototype.includes.call(list, searchElement);
}

/**
 * Returns the index of the first occurrence of a value in an array, or -1 if it is not present.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {T} searchElement
 * @return {number}
 */
export function indexOf(list, searchElement) {
    return Array.prototype.indexOf.call(list, searchElement);
}

/**
 * Determines whether the specified callback function returns true for any element of an array.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {number?} start
 * @param {number?} end
 * @return {T[]}
 */
export function slice(list, start, end) {
    return Array.prototype.slice.call(list, start, end);
}

/**
 * Determines whether the specified callback function returns true for any element of an array.
 * @template T
 * @param {ArrayLike<T>} list
 * @param {MapCallable<T, any>} pred
 * @return {boolean}
 */
export function some(list, pred) {
    return Array.prototype.some.call(list, pred);
}
