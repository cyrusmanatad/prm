import { execFileSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import * as prettier from 'prettier';

const appRoot = fileURLToPath(new URL('../', import.meta.url));
const repoRoot = path.resolve(appRoot, '..');
const git = (...args) => execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8' }).split('\0').filter(Boolean);
const base = process.env.PRETTIER_BASE_REF || 'HEAD';
// A first push has no previous commit. Check all source files in that case.
const changed = /^0+$/.test(base)
    ? git('ls-files', '-z')
    : git('diff', '--name-only', '-z', '--diff-filter=ACMR', base, '--', 'src/');
const files = [...new Set([...changed, ...git('ls-files', '--others', '--exclude-standard', '-z', '--', 'src/')])]
    .filter((file) => file.startsWith('src/') && /\.(vue|[cm]?[jt]sx?|css|scss|json)$/.test(file))
    .sort();
const write = process.argv.includes('--write');
let checked = 0;
let failed = false;
for (const file of files) {
    const absolute = path.join(repoRoot, file);
    const info = await prettier.getFileInfo(absolute, { ignorePath: path.join(appRoot, '.prettierignore') });
    if (info.ignored || !info.inferredParser) continue;
    const options = { ...(await prettier.resolveConfig(absolute, { editorconfig: true })), filepath: absolute };
    const source = await readFile(absolute, 'utf8');
    checked++;
    if (await prettier.check(source, options)) continue;
    if (write) {
        await writeFile(absolute, await prettier.format(source, options));
        console.log(`Formatted ${file}`);
    } else {
        console.error(`Needs Prettier: ${file}`);
        failed = true;
    }
}
console.log(`${checked} changed frontend files ${write ? 'formatted' : 'checked'}.`);
if (failed) {
    console.error('Run npm run format:changed from src/ and commit the formatted files.');
    process.exitCode = 1;
}
