// Runs as npm's `preinstall`, so it may only use Node built-ins — no
// dependency is installed yet.
//
// Nextcloud 33 and 34 both declare `node: ^24.0.0` / `npm: ^11.3.0`, and CI
// builds on 24. Installing on an older Node still mostly works, which is the
// problem: it works quietly until something built against a newer runtime
// behaves differently here than it does in CI. Fail loudly instead.

const REQUIRED_NODE_MAJOR = 24
const REQUIRED_NPM_MAJOR = 11

const nodeMajor = Number(process.versions.node.split('.')[0])

// npm sets this for lifecycle scripts; fall back to skipping the npm check
// rather than guessing when some other client runs us.
const npmVersion = process.env.npm_config_user_agent?.match(/npm\/(\d+)/)?.[1]
const npmMajor = npmVersion ? Number(npmVersion) : null

const problems = []
if (nodeMajor < REQUIRED_NODE_MAJOR) {
  problems.push(`Node ${process.versions.node} — this project needs ${REQUIRED_NODE_MAJOR} or newer.`)
}
if (npmMajor !== null && npmMajor < REQUIRED_NPM_MAJOR) {
  problems.push(`npm ${npmVersion}.x — this project needs ${REQUIRED_NPM_MAJOR} or newer.`)
}

if (problems.length > 0) {
  console.error(`
doconext_finder: wrong toolchain
${problems.map((p) => `  - ${p}`).join('\n')}

  The repo pins the version in .nvmrc, so:

      nvm use

  If that reports the version is not installed:

      nvm install 24

`)
  process.exit(1)
}
