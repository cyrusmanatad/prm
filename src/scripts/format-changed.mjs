import { execFileSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import * as prettier from 'prettier';

const appRoot = fileURLToPath(new URL('../', import.meta.url));
const repoRoot = path.resolve(appRoot, '..');
const git = (...args) => execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8' }).split('\0').filter(Boolean);
const requested = process.env.PRETTIER_BASE_REF || 'HEAD';
const defaultBranch = process.env.PRETTIER_DEFAULT_BRANCH || 'main';
const currentBranch = () =>
    process.env.GITHUB_REF_TYPE === 'tag'
        ? ''
        : process.env.GITHUB_REF_NAME || git('rev-parse', '--abbrev-ref', 'HEAD').join('').trim();
// A first push has no previous commit (`before` is all zeros). On the default branch, check every tracked
// file. On any other branch, compare against the merge base with the fetched default branch.
let base = requested;
let checkAll = false;
try {
    git('rev-parse', '--show-toplevel');
    if (/^0+$/.test(requested)) {
        if (currentBranch() === defaultBranch) {
            checkAll = true;
        } else {
            base = `origin/${defaultBranch}`;
            git('rev-parse', '--verify', `${base}^{commit}`);
            base = git('merge-base', base, 'HEAD').join('').trim();
            console.log(`First push of a branch: comparing against merge base ${base} with origin/${defaultBranch}.`);
        }
    } else {
        git('rev-parse', '--verify', `${base}^{commit}`);
    }
} catch {
    console.error(
        `Cannot compare frontend files in ${repoRoot} against ${base}. Verify Git ownership and fetch the base commit.`,
    );
    process.exit(1);
}
const changed = checkAll
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
