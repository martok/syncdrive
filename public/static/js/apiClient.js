import {html} from "./uhtml.js";

export async function apiFetch(endpoint, body = {}, extraOptions = {}) {
    const opts = {};
    if (typeof SHARE_TOKEN !== "undefined") {
        Object.assign(opts, {
            headers: new Headers({
                'X-Share-Token': SHARE_TOKEN,
            }),
        });
    }
    if (body) {
        Object.assign(opts,{
            method: 'POST',
            body: JSON.stringify(body)
        });
    } else {
        Object.assign(opts,{
            method: 'GET'
        });
    }
    // send request
    Object.assign(opts, extraOptions);
    const res = await fetch(endpoint, opts);
    // evaluate result
    const resText = await res.text();
    let resOk = res.ok;
    let result;
    try {
        result = JSON.parse(resText);
        resOk = result && !result.error;
    } catch (e) {
        resOk = false;
    }
    if (resOk)
        return result;
    // handle error states
    const msg = (result && result.error && result.message) ?
        html`<div>Error ${result.error}: ${result.message}</div>` :
        html`<div>HTTP Error ${res.status}: ${resText}</div>`;
    UIkit.notification({
        message: msg.innerHTML,
        status: 'danger',
    });
    return false;
}