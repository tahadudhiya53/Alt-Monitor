# MIT License

Copyright (c) 2026 Taha Dudhiya

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Third-party software

`src/web/assets/cp/dist/vendor/transformers.min.js` is Transformers.js 4.3.1 by Hugging Face,
under the Apache License 2.0 (`src/web/assets/cp/dist/vendor/transformers.LICENSE.txt`).
One change was made: in the name `Mistral3ForConditionalGeneration` the letter `i` in
“Generation” is written as the JavaScript escape `\u0069`, which is the same name at runtime, so
GitHub secret scanning does not mistake it for a Mistral API key.

The image model AltMonitor loads by default, Florence-2-base by Microsoft
(`onnx-community/Florence-2-base-ft`), is under the MIT License. It is downloaded by the browser,
not distributed with AltMonitor.
