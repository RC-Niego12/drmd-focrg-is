import { writeFileSync } from 'node:fs';
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';

async function main() {
  const [, , outputPath, pagesArg = '2'] = process.argv;
  if (!outputPath) {
    console.error('Usage: node create-blank-pdf.mjs <output.pdf> [pageCount]');
    process.exit(1);
  }
  const pageCount = Math.max(1, Number.parseInt(String(pagesArg), 10) || 2);
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.Helvetica);
  for (let page = 1; page <= pageCount; page++) {
    const sheet = doc.addPage([595.28, 841.89]);
    sheet.drawText(`SIGNED RESPONSE LETTER PAGE ${page}`, {
      x: 72,
      y: 780,
      size: 16,
      font,
      color: rgb(0.1, 0.1, 0.1),
    });
  }
  writeFileSync(outputPath, await doc.save());
}

main().catch((error) => {
  console.error(error?.message || String(error));
  process.exit(3);
});
