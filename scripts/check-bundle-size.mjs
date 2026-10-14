// E-123: bundle size budget. Run after a build (npm run build:check). Fails the build
// when the entry chunk or any other chunk is larger than its gzip budget.
import { existsSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const ENTRY_BUDGET_KB = Number(process.env.BUNDLE_ENTRY_KB ?? 200);
const CHUNK_BUDGET_KB = Number(process.env.BUNDLE_CHUNK_KB ?? 150);
const manifestPath = 'public/build/manifest.json';

if (!existsSync(manifestPath)) {
    console.error(`No ${manifestPath}. Run the build first.`);
    process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const rows = [];
for (const [source, chunk] of Object.entries(manifest)) {
    if (!chunk.file.endsWith('.js')) {
        continue;
    }
    const gzipKb =
        gzipSync(readFileSync(`public/build/${chunk.file}`)).length / 1024;
    const budget = chunk.isEntry ? ENTRY_BUDGET_KB : CHUNK_BUDGET_KB;
    rows.push({ source, gzipKb, budget, over: gzipKb > budget });
}
rows.sort((a, b) => b.gzipKb - a.gzipKb);

for (const r of rows.slice(0, 10)) {
    console.log(
        `${r.over ? 'OVER' : 'ok  '} ${r.gzipKb.toFixed(1).padStart(7)} KB gzip (budget ${r.budget} KB)  ${r.source}`,
    );
}
const over = rows.filter((r) => r.over);
if (over.length > 0) {
    console.error(
        `\n${over.length} chunk(s) over budget. Lazy-load the heavy parts (defineAsyncComponent) or split them.`,
    );
    process.exit(1);
}
console.log(`\nAll ${rows.length} JS chunks within budget.`);
