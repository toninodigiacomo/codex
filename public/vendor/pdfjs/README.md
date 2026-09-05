# PDF.js (vendored)

Version 3.11.174, "legacy" build (targets older browsers, exposes a
plain `pdfjsLib` global — no ES module support needed), fetched from
the `pdfjs-dist` npm package rather than a CDN, matching how
`vendor/qrcode.js` is already handled in this project: a third-party
script the reader depends on shouldn't be an unpinned, always-fetched
external dependency for a self-hosted app.

- `pdf.min.js` — the core library.
- `pdf.worker.min.js` — runs PDF parsing/rendering off the main thread;
  `public/js/reader.js` points `pdfjsLib.GlobalWorkerOptions.workerSrc`
  at this file directly.
- `LICENSE` — Apache License 2.0, as published by the Mozilla PDF.js
  project.

To upgrade: `npm pack pdfjs-dist@<version>`, extract, and replace both
`.js` files from `legacy/build/` in the extracted package.
