#!/usr/bin/env node
// Production npm-audit gate with an allowlist for reviewed advisories.
//
// `npm audit --omit=dev` flags advisories in the *shipped* dependency graph,
// but a handful of build-tool CVEs (esbuild/vite) land there only because
// vue-router declares `vite` as a peerDependency. esbuild/vite are bundlers
// that run at build time and never ship, and the specific advisories are
// Windows-dev-server / Deno-install issues — not exploitable in a deployed
// Nextcloud app. The IDs in $AUDIT_ALLOWLIST are muted so the gate still
// blocks on any *new, genuinely shipped* vulnerability.
//
// Usage: node audit-gate.mjs <path-to-npm-audit-json>
import { readFileSync } from 'node:fs'

const file = process.argv[2]
if (!file) {
	console.error('usage: audit-gate.mjs <npm-audit-json>')
	process.exit(2)
}

const allow = new Set(
	(process.env.AUDIT_ALLOWLIST || '').split(/\s+/).filter(Boolean),
)
const BLOCK_SEVERITIES = new Set(['moderate', 'high', 'critical'])

const report = JSON.parse(readFileSync(file, 'utf8'))
const vulns = report.vulnerabilities || {}

// Collect every advisory ID in a vulnerability's transitive `via` closure.
// A `via` entry is either an advisory object (carrying a GHSA url) or a string
// naming another vulnerable package, which we resolve recursively — that is how
// `vite` (via: ["esbuild"]) inherits esbuild's advisories.
function advisoryIds(name, seen = new Set()) {
	if (seen.has(name)) return []
	seen.add(name)
	const node = vulns[name]
	if (!node) return []
	const ids = []
	for (const via of node.via || []) {
		if (typeof via === 'string') {
			ids.push(...advisoryIds(via, seen))
		} else if (via && via.url) {
			ids.push(via.url.split('/').pop())
		} else if (via && via.source != null) {
			ids.push(String(via.source))
		}
	}
	return ids
}

const blocking = []
for (const [name, node] of Object.entries(vulns)) {
	if (!BLOCK_SEVERITIES.has(node.severity)) continue
	const ids = [...new Set(advisoryIds(name))]
	// Block unless every advisory behind this package is explicitly allowlisted.
	if (ids.length > 0 && ids.every((id) => allow.has(id))) continue
	blocking.push(`  ${name} (${node.severity}): ${ids.join(', ') || 'unknown advisory'}`)
}

if (blocking.length > 0) {
	console.error('Blocking production advisories (not allowlisted):')
	console.error(blocking.join('\n'))
	console.error(
		'\nIf one of these is a build-tool / false-positive, add its GHSA id to '
			+ 'AUDIT_ALLOWLIST in .github/workflows/ci.yml with a justification.',
	)
	process.exit(1)
}

console.log(
	'npm production audit clean — only allowlisted build-tool advisories present.',
)
