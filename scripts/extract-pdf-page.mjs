import { readFileSync, writeFileSync } from 'node:fs';
import { PDFDocument } from 'pdf-lib';

async function main() {
  const [, , inputPath, outputPath, pageArg = '2'] = process.argv;
  if (!inputPath || !outputPath) {
    console.error('Usage: node extract-pdf-page.mjs <input.pdf> <output.pdf> [pageNumber]');
    process.exit(1);
  }

  const pageNumber = Math.max(1, Number.parseInt(String(pageArg), 10) || 2);
  const bytes = readFileSync(inputPath);
  const source = await PDFDocument.load(bytes, { ignoreEncryption: true });
  const total = source.getPageCount();
  if (total < 1) {
    console.error('Input PDF has no pages.');
    process.exit(2);
  }

  const index = Math.min(pageNumber, total) - 1;
  const out = await PDFDocument.create();
  const [copied] = await out.copyPages(source, [index]);
  out.addPage(copied);
  writeFileSync(outputPath, await out.save());
  process.stdout.write(String(out.getPageCount()));
}

main().catch((error) => {
  console.error(error?.message || String(error));
  process.exit(3);
});
