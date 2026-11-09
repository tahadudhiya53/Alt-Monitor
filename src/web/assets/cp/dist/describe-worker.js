/*
 * Describes images with Florence-2, in the browser, in a worker so the page stays responsive.
 *
 * Messages in:  {type: 'load', config} · {type: 'describe', id, image: Blob, avoid: string[]}
 * Messages out: {type: 'progress', loaded, total} · {type: 'ready'} ·
 *               {type: 'trying', id, attempt} ·
 *               {type: 'description', id, text, fresh} · {type: 'error', id, message}
 *
 * `avoid` lists what the image already has (its alt text, earlier suggestions). The worker works
 * through PLAN until it finds a description that says something new, so describing an image again
 * gives another, usually fuller, description instead of the same sentence. `fresh` is false when
 * every attempt repeated a known text.
 *
 * The model is downloaded once and kept in the browser's cache; images never leave the browser.
 */
// The short caption reads best as alt text, so it comes first; the detailed ones add more of
// the scene; sampled ones word it differently.
const PLAN = [
    {task: '<CAPTION>'},
    {task: '<DETAILED_CAPTION>'},
    {task: '<CAPTION>', sample: true},
    {task: '<DETAILED_CAPTION>', sample: true},
    {task: '<CAPTION>', sample: true},
    {task: '<MORE_DETAILED_CAPTION>'},
];
const MAX_LENGTH = 160;
let loading = null;

async function load(config) {
    const lib = await import(config.library);
    lib.env.allowLocalModels = false;

    if (config.remoteHost) {
        lib.env.remoteHost = config.remoteHost;
    }

    if (config.wasmPaths) {
        lib.env.backends.onnx.wasm.wasmPaths = config.wasmPaths;
    }

    // Progress across every file the model needs.
    const files = {};
    const progress = (event) => {
        if (event.status === 'progress' && event.total) {
            files[event.file] = [event.loaded, event.total];
            const sums = Object.values(files).reduce((sum, [loaded, total]) => [sum[0] + loaded, sum[1] + total], [0, 0]);
            postMessage({type: 'progress', loaded: sums[0], total: sums[1]});
        }
    };
    const options = {dtype: 'q8', device: 'wasm', progress_callback: progress};
    const [model, processor] = await Promise.all([
        lib.Florence2ForConditionalGeneration.from_pretrained(config.model, options),
        lib.AutoProcessor.from_pretrained(config.model, {progress_callback: progress}),
    ]);

    return {lib, model, processor};
}

// Alt text, not a paragraph. The model narrates (“In this image we can see…”, “in the background
// we can see…”, “there is…”); that is reworded to plain description, places are kept (“A flag in
// the background”), sentences about the background colour or how the photo was taken are dropped,
// and whole sentences are kept up to MAX_LENGTH characters.
const SPOT = '(?:background|foreground|front|back|left|right|top|bottom|middle|cent(?:er|re))';
const PLACE = '(?:(?:in|on|at|to) the ' + SPOT + '(?: side)?(?: of the (?:image|picture|photo))?|in this (?:image|picture|photo))';
const SEE = '(?:(?:we|i) can (?:also )?see|there (?:is|are)|(?:this|the) (?:image|picture|photo(?:graph)?) (?:shows|depicts|features|contains|is of)|(?:an? |the )?(?:close-?up )?(?:image|picture|photo(?:graph)?) (?:of|showing))';
function shorten(text) {
    // “In the background we can see a flag” → “A flag in the background”.
    const opener = new RegExp('^(' + PLACE + ')?[\\s,]*' + SEE + '[\\s,:]+', 'i');
    const seeing = new RegExp(',?\\s*(?:and\\s+)?(?:also\\s+)?(?:' + PLACE + '\\s*,?\\s*)?(?:we|i) can(?:\\s+also)?(?:\\s+see)?\\b[^.,]*', 'gi');
    const leftover = new RegExp(',\\s*(?:and\\s+)?' + PLACE + '\\s*$', 'i');
    const filler = /^(?:(?:in the )?background|the background)\b.*\b(?:colou?r|blurr?(?:y|ed)|dark|white|black)\b|^(?:this|the) (?:image|picture|photo) (?:is|was|might be|may be) (?:taken|clicked|captured)\b/i;
    const sentences = (String(text).replace(/\s+/g, ' ').trim().match(/[^.!?]+[.!?]*/g) || [])
        .map((sentence) => {
            const end = (sentence.trim().match(/[.!?]+$/) || ['.'])[0];
            let body = sentence.trim().replace(/[.!?]+$/, '');
            let place = '';
            const open = body.match(opener);

            if (open) {
                body = body.slice(open[0].length);
                place = open[1] && !/^in this/i.test(open[1]) ? open[1].replace(/ side| of the (?:image|picture|photo)/gi, '').toLowerCase() : '';
            }

            body = body.replace(opener, '').replace(seeing, '').replace(/,\s*there (?:is|are)\s+/gi, ', ')
                .replace(leftover, '').replace(/[\s,;:]+$/, '').trim();

            // Nothing left but a place (“In the background”) or a single word: not a description.
            return body.split(/\s+/).length < 2 && !place ? '' : body + (place ? ' ' + place : '') + end;
        })
        .filter((sentence) => sentence.replace(/[.!?]+$/, '').trim() && !filler.test(sentence))
        .map((sentence) => sentence.charAt(0).toUpperCase() + sentence.slice(1));
    let result = '';

    for (const sentence of sentences) {
        const next = (result + ' ' + sentence).trim();

        if (result && next.length > MAX_LENGTH) {
            break;
        }

        result = next;
    }

    if (result.length > MAX_LENGTH) {
        result = result.slice(0, MAX_LENGTH).replace(/\s+\S*$/, '').replace(/[,;:\-–\s]+$/, '');
    }

    return result;
}

function normalize(text) {
    return String(text).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}

async function caption(raw, step) {
    const {model, processor} = await loading;
    const inputs = await processor(raw, step.task);
    const ids = await model.generate({
        ...inputs,
        max_new_tokens: step.task === '<CAPTION>' ? 40 : 90,
        ...(step.sample ? {do_sample: true, temperature: 0.8, top_k: 40} : {}),
    });
    const decoded = processor.batch_decode(ids, {skip_special_tokens: false})[0];

    return shorten(processor.post_process_generation(decoded, step.task, raw.size)[step.task] ?? '');
}

async function describe(id, image, avoid) {
    const {lib} = await loading;
    const raw = await lib.RawImage.fromBlob(image);
    const known = new Set((avoid || []).map(normalize).filter(Boolean));
    let first = '';

    for (let attempt = 0; attempt < PLAN.length; attempt++) {
        if (attempt > 0) {
            postMessage({type: 'trying', id, attempt});
        }

        const text = await caption(raw, PLAN[attempt]);
        first ||= text;

        if (text && !known.has(normalize(text))) {
            return {text, fresh: true};
        }

        // Without anything to avoid, the first description is the answer.
        if (text && known.size === 0) {
            return {text, fresh: true};
        }
    }

    return {text: first, fresh: false};
}

self.onmessage = async (event) => {
    const message = event.data;

    try {
        if (message.type === 'load') {
            loading ??= load(message.config);
            await loading;
            postMessage({type: 'ready'});
        } else if (message.type === 'describe') {
            const result = await describe(message.id, message.image, message.avoid);
            postMessage({type: 'description', id: message.id, text: result.text, fresh: result.fresh});
        }
    } catch (error) {
        if (message.type === 'load') {
            loading = null;
        }

        postMessage({type: 'error', id: message.id ?? null, message: String(error && error.message ? error.message : error)});
    }
};
