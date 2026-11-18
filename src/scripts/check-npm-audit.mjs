// Fails on any high or critical npm advisory that is not a reviewed, unexpired exception.
// Replaces a bare `npm audit --audit-level=high` so one unfixable advisory cannot hide new ones.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const appRoot = fileURLToPath(new URL('../', import.meta.url));
const blocking = new Set(['high', 'critical']);

export function evaluate(report, exceptions, today) {
    const problems = [];
    const found = new Map();
    for (const entry of Object.values(report.vulnerabilities ?? {})) {
        for (const via of entry.via ?? []) {
            // String entries name another vulnerable package; every chain ends at an advisory object.
            if (typeof via !== 'object' || !blocking.has(via.severity)) continue;
            const id = /GHSA(-[0-9a-z]{4}){3}/.exec(via.url ?? '')?.[0] ?? String(via.source);
            found.set(`${id} ${via.name}`, { id, name: via.name, severity: via.severity, title: via.title });
        }
    }
    const used = new Set();
    for (const { id, name, severity, title } of found.values()) {
        const exception = exceptions.find((item) => item.advisory === id && item.package === name);
        if (!exception) {
            problems.push(`${severity}: ${name} ${id} ${title ?? ''}`.trim());
        } else if (!/^\d{4}-\d{2}-\d{2}$/.test(exception.reviewBy ?? '') || exception.reviewBy < today) {
            problems.push(`exception for ${name} ${id} passed its review date (${exception.reviewBy}); re-review it`);
        } else if (!exception.reason?.trim()) {
            problems.push(`exception for ${name} ${id} has no recorded reason`);
        } else {
            used.add(exception);
        }
    }
    for (const exception of exceptions) {
        if (!used.has(exception) && !problems.some((line) => line.includes(exception.advisory))) {
            problems.push(
                `exception for ${exception.package} ${exception.advisory} no longer matches a finding; remove it`,
            );
        }
    }
    return problems;
}

function selfTest() {
    const advisory = (id, name, severity) => ({
        source: 1,
        name,
        severity,
        title: 'Synthetic',
        url: `https://github.com/advisories/${id}`,
    });
    const report = (...items) => ({
        vulnerabilities: Object.fromEntries(items.map((item) => [item.name, { via: [item, 'other-package'] }])),
    });
    const allowed = { advisory: 'GHSA-aaaa-bbbb-cccc', package: 'alpha', reviewBy: '2026-12-31', reason: 'Synthetic' };
    const cases = [
        ['clean report passes', report(), [], 0],
        ['moderate finding passes', report(advisory('GHSA-dddd-eeee-ffff', 'beta', 'moderate')), [], 0],
        ['unreviewed high finding fails', report(advisory('GHSA-dddd-eeee-ffff', 'beta', 'high')), [], 1],
        ['reviewed finding passes', report(advisory('GHSA-aaaa-bbbb-cccc', 'alpha', 'high')), [allowed], 0],
        [
            'exception does not cover another package',
            report(advisory('GHSA-aaaa-bbbb-cccc', 'beta', 'critical')),
            [allowed],
            1,
        ],
        [
            'expired exception fails',
            report(advisory('GHSA-aaaa-bbbb-cccc', 'alpha', 'high')),
            [{ ...allowed, reviewBy: '2026-01-01' }],
            1,
        ],
        [
            'exception without reason fails',
            report(advisory('GHSA-aaaa-bbbb-cccc', 'alpha', 'high')),
            [{ ...allowed, reason: ' ' }],
            1,
        ],
        ['stale exception fails', report(), [allowed], 1],
        [
            'new finding beside a reviewed one fails',
            report(advisory('GHSA-aaaa-bbbb-cccc', 'alpha', 'high'), advisory('GHSA-dddd-eeee-ffff', 'beta', 'high')),
            [allowed],
            1,
        ],
    ];
    for (const [label, input, exceptions, expected] of cases) {
        const actual = evaluate(input, exceptions, '2026-06-01').length;
        if (actual !== expected) {
            console.error(`Self-test failed: ${label} (expected ${expected} problems, found ${actual}).`);
            process.exit(1);
        }
    }
    console.log(`npm audit gate self-test passed: ${cases.length} cases.`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    if (process.argv.includes('--self-test')) {
        selfTest();
        process.exit(0);
    }
    let output;
    try {
        output = execFileSync('npm', ['audit', '--json'], {
            cwd: appRoot,
            encoding: 'utf8',
            maxBuffer: 64 * 1024 * 1024,
        });
    } catch (error) {
        // npm exits non-zero whenever it reports any advisory; the report is still on stdout.
        output = error.stdout;
    }
    let report;
    try {
        report = JSON.parse(output);
    } catch {
        report = null;
    }
    if (!report?.vulnerabilities || !report.metadata) {
        console.error('npm audit did not return a usable report; treating the advisory check as failed.');
        process.exit(1);
    }
    const exceptions = JSON.parse(readFileSync(new URL('./npm-audit-exceptions.json', import.meta.url), 'utf8'));
    const problems = evaluate(report, exceptions, new Date().toISOString().slice(0, 10));
    for (const exception of exceptions) {
        console.log(`Reviewed exception: ${exception.package} ${exception.advisory}, review by ${exception.reviewBy}.`);
    }
    if (problems.length) {
        console.error(['Dependency advisory check failed:', ...problems.map((line) => `- ${line}`)].join('\n'));
        process.exit(1);
    }
    console.log('Dependency advisory check passed: no unreviewed high or critical advisories.');
}
