// Runs the image model the control panel uses in headless Chrome, on real photos: AltMonitor's
// own worker and bundled library, the real model download, and the real WebAssembly runtime.
//
// Needs Chrome (or CHROME_BIN) and network access to Hugging Face and jsDelivr. Exits 2, never 0,
// when it can't run; 1 when it ran and failed.
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, mkdtemp, rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, extname, resolve, sep} from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const chrome = process.env.CHROME_BIN ?? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find(existsSync);
const photos = ['cats.jpg', 'football-match.jpg', 'tiger.jpg'].map((name) => `https://huggingface.co/datasets/Xenova/transformers.js-docs/resolve/main/${name}`);
const types = {'.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript'};

if (!chrome) {
    console.error('Chrome was not found. Set CHROME_BIN.');
    process.exit(2);
}

const server = createServer(async (request, response) => {
    const path = resolve(root, '.' + decodeURIComponent(new URL(request.url, 'http://x').pathname));

    if (!path.startsWith(root + sep)) {
        response.writeHead(403).end();
        return;
    }

    let body;

    try {
        body = await readFile(path);
    } catch {
        response.writeHead(404).end();
        return;
    }

    response.writeHead(200, {'Content-Type': types[extname(path)] ?? 'application/octet-stream'}).end(body);
}).listen(0, '127.0.0.1');
await new Promise((ready) => server.on('listening', ready));

const profile = await mkdtemp(join(tmpdir(), 'altmonitor-chrome-'));
const port = 9300 + Math.floor(Math.random() * 500);
const query = photos.map((url) => 'image=' + encodeURIComponent(url)).join('&');
const url = `http://127.0.0.1:${server.address().port}/tests/browser/describe.html?${query}`;
const browser = spawn(chrome, ['--headless=new', '--no-first-run', '--no-default-browser-check', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, url], {stdio: 'ignore'});
let exitCode = 1;

try {
    const deadline = Date.now() + 15 * 60 * 1000;
    let page = null;

    while (!page && Date.now() < deadline) {
        await new Promise((wait) => setTimeout(wait, 500));
        page = await fetch(`http://127.0.0.1:${port}/json`).then((r) => r.json()).then((targets) => targets.find((t) => t.type === 'page')).catch(() => null);
    }

    const socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((open) => socket.addEventListener('open', open));
    const title = () => new Promise((answer) => {
        socket.addEventListener('message', function listener(event) {
            const message = JSON.parse(event.data);

            if (message.id === 1) {
                socket.removeEventListener('message', listener);
                answer(message.result?.result?.value ?? '');
            }
        });
        socket.send(JSON.stringify({id: 1, method: 'Runtime.evaluate', params: {expression: 'document.title'}}));
    });

    let state = 'running';

    while (state.startsWith('running') && Date.now() < deadline) {
        await new Promise((wait) => setTimeout(wait, 2000));
        state = await title();
    }

    const [outcome, ...json] = state.split(':');
    const results = json.length ? JSON.parse(json.join(':')) : [];
    console.log(JSON.stringify(results, null, 2));
    const described = results.filter((result) => typeof result.text === 'string' && /\p{L}{3,}/u.test(result.text));
    // Downloaded once: a second load (as on a later visit) comes from the browser's cache.
    const first = results.find((result) => 'loadedMs' in result)?.loadedMs ?? 0;
    const again = results.find((result) => 'reloadedMs' in result)?.reloadedMs ?? Infinity;
    const cached = results.find((result) => 'cached' in result)?.cached ?? [];
    const kept = cached.filter((url) => url.includes('/Florence-2') && url.endsWith('_quantized.onnx')).length === 4 && again < first / 3;
    // A German site's description is translated, and the translation model is kept too, as the
    // files cp.js's TRANSLATION_FILES expects (so the image model page counts it as downloaded).
    const german = results.find((result) => 'german' in result);
    const translationFiles = ['config.json', 'generation_config.json', 'tokenizer.json', 'tokenizer_config.json', 'onnx/encoder_model_quantized.onnx', 'onnx/decoder_model_merged_quantized.onnx'];
    const translated = Boolean(german && german.germanTranslated && /\p{L}{3,}/u.test(german.german) && german.german.toLowerCase() !== german.english.toLowerCase()
        && translationFiles.every((file) => cached.some((url) => url.endsWith('/Xenova/opus-mt-en-de/resolve/main/' + file))));
    const translateOnly = results.find((result) => 'translateOnly' in result);
    const translatedAlone = Boolean(translateOnly && /\p{L}{3,}/u.test(translateOnly.translateOnly) && !/cats/i.test(translateOnly.translateOnly));
    console.log(translateOnly ? `Translated alone, without the image model: “${translateOnly.translateOnly}” (${translateOnly.translateOnlyMs} ms).` : 'Not translated alone.');
    console.log(german ? `Translated: “${german.english}” → “${german.german}” (${german.germanMs} ms with the translation model's first load).` : 'Not translated.');
    console.log(`Model load: ${first} ms the first time, ${again} ms from the browser's cache.`);
    // Describing again gives a new description, not the same sentence.
    const varied = described.filter((result) => result.againFresh && typeof result.again === 'string' && result.again.toLowerCase() !== result.text.toLowerCase()).length === photos.length;
    exitCode = outcome === 'done' && described.length === photos.length && kept && varied && translated && translatedAlone ? 0 : 1;
    console.log(exitCode === 0 ? `OK: ${described.length} photos described in the browser.` : `FAILED (${outcome || 'timed out'}).`);
    socket.close();
} finally {
    browser.kill();
    server.close();
    await rm(profile, {recursive: true, force: true}).catch(() => {});
}

process.exit(exitCode);
