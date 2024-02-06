import {EB} from "./builder.js";

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
    const errTexts = (result && result.error && result.message) ?
        ['Error ', result.error, ': ', result.message] :
        ['HTTP Error ', res.status, ': ' + resText];
    UIkit.notification({
        message: EB('div', ...errTexts).innerHTML,
        status: 'danger',
    });
    return false;
}