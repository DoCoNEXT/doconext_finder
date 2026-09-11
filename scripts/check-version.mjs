// Runs as npm's `prebuild`, so every build checks it: `npm run build` in CI,
// and `make appstore` (and with it deploy.sh) through build-prod. Node
// built-ins only, like check-node.mjs.
//
// appinfo/info.xml holds the app's version. package.json and its lock file
// repeat it, and @nextcloud/vite-config writes the package.json copy into the
// js/*.license files a build ships, so they have to agree. Nothing keeps them
// in step on its own, so fail the build when they drift.

import { existsSync, readFileSync } from 'node:fs'

const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'))

const infoXml = readFileSync('appinfo/info.xml', 'utf8')
const appId = infoXml.match(/<id>([^<]+)<\/id>/)?.[1] ?? 'this app'
const version = infoXml.match(/<version>([^<]+)<\/version>/)?.[1]

if (!version) {
  console.error(`\n${appId}: appinfo/info.xml has no <version>\n`)
  process.exit(1)
}

const copies = [['package.json', readJson('package.json').version]]
if (existsSync('package-lock.json')) {
  const lock = readJson('package-lock.json')
  copies.push(['package-lock.json', lock.version])
  copies.push(['package-lock.json (root package)', lock.packages?.['']?.version])
}
// A Thunderbird/Firefox WebExtension manifest carries the version too. An
// Office add-in manifest has its own version line and is not checked.
if (existsSync('public/manifest.json')) {
  const manifest = readJson('public/manifest.json')
  if ('manifest_version' in manifest) {
    copies.push(['public/manifest.json', manifest.version])
  }
}

const wrong = copies.filter(([, found]) => found !== version)

if (wrong.length > 0) {
  const manifestWrong = wrong.some(([file]) => file === 'public/manifest.json')
  console.error(`
${appId}: version out of sync
  appinfo/info.xml says ${version}, but:
${wrong.map(([file, found]) => `  - ${file} says ${found ?? '(nothing)'}`).join('\n')}

  info.xml is the one to change; bring package.json and its lock file along:

      npm version ${version} --no-git-tag-version --allow-same-version
${manifestWrong ? `\n  and set "version" in public/manifest.json to ${version} by hand.\n` : ''}`)
  process.exit(1)
}
