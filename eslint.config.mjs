import { recommended } from '@nextcloud/eslint-config'
import { defineConfig } from 'eslint/config'

// Local rule: flag UI-facing object properties (label/title/placeholder/etc.)
// whose value is a bare English string literal instead of `t('...')`. Catches
// the most common "forgot to wrap in t()" mistake. Heuristic: only matches
// values that start with a capital letter and contain a lowercase letter —
// avoids false positives on constants ('POST', 'GET'), single-char codes ('R'),
// and identifiers/keys. Add a property name here if it's a UI label slot.
const i18nLabelProps = new Set(['label', 'title', 'placeholder', 'heading', 'tooltip', 'header', 'description'])
const requireT = {
    meta: { type: 'problem', schema: [], messages: { wrap: "User-facing string '{{value}}' should be wrapped in t('...')." } },
    create(context) {
        function check(node) {
            if (node.type !== 'Property' || node.computed) return
            const key = node.key.name || node.key.value
            if (!i18nLabelProps.has(key)) return
            const v = node.value
            if (v.type !== 'Literal' || typeof v.value !== 'string') return
            // Catch user-facing text. Heuristics by property:
            //   - `label:` is almost always rendered to users — flag any
            //     non-trivial string (skip 1-char codes like 'R', HTTP verbs).
            //   - others (title, placeholder, …) use a stricter heuristic to
            //     avoid false positives: capitalized phrase ("All entries"),
            //     or number-led phrase ("7 days").
            const isAllCapsCode = /^[A-Z0-9_]{1,5}$/.test(v.value)  // 'POST', 'R', 'CSV'
            const isCapsThenLower = /^[A-Z].*[a-z]/.test(v.value)
            const isDigitPhrase = /^\d.*\s[a-z]/.test(v.value)
            const isWordy = /[a-z]/.test(v.value) && v.value.length >= 3
            if (key === 'label') {
                if (isAllCapsCode || !isWordy) return
            } else {
                if (!isCapsThenLower && !isDigitPhrase) return
            }
            context.report({ node: v, messageId: 'wrap', data: { value: v.value } })
        }
        return { Property: check }
    },
}

// An immediate watcher runs its source getter during setup(), so anything the
// getter names must already be initialised. Naming a `const` declared further
// down the file throws a ReferenceError that Vue swallows in a production
// build — and because the effect threw before tracking a dependency, it never
// runs again. The watcher is silently dead for the life of the component.
// (Cost of learning this the hard way: CreateEntityDialog's "access follows
// the parent" switch ignored the template's policy, so records were created
// with an access list nobody chose.)
const immediateWatchAfterDeclaration = {
    meta: {
        type: 'problem',
        schema: [],
        messages: {
            dead: "'{{name}}' is declared after this immediate watcher, so the getter throws during setup and the watcher never runs again. Move the watcher below the declaration.",
        },
    },
    create(context) {
        const moduleDecls = new Map()
        const immediateWatchers = []

        function collectIdentifiers(node, out) {
            if (node === null || typeof node !== 'object') return
            if (Array.isArray(node)) {
                node.forEach(n => collectIdentifiers(n, out))
                return
            }
            if (typeof node.type !== 'string') return
            if (node.type === 'Identifier') out.push(node)
            for (const key of Object.keys(node)) {
                if (key === 'parent' || key === 'range' || key === 'loc') continue
                collectIdentifiers(node[key], out)
            }
            // Property keys and member accesses name fields, not bindings.
            if (node.type === 'MemberExpression' && !node.computed) {
                const i = out.indexOf(node.property)
                if (i !== -1) out.splice(i, 1)
            }
            if (node.type === 'Property' && !node.computed) {
                const i = out.indexOf(node.key)
                if (i !== -1) out.splice(i, 1)
            }
        }

        function isImmediate(node) {
            const callee = node.callee
            const name = callee && callee.type === 'Identifier' ? callee.name : null
            if (name === 'watchEffect') return true
            if (name !== 'watch') return false
            const opts = node.arguments[2]
            if (!opts || opts.type !== 'ObjectExpression') return false
            return opts.properties.some(p =>
                p.type === 'Property'
                && !p.computed
                && (p.key.name === 'immediate' || p.key.value === 'immediate')
                && p.value.type === 'Literal'
                && p.value.value === true,
            )
        }

        return {
            'Program > VariableDeclaration > VariableDeclarator'(node) {
                if (node.id.type === 'Identifier' && !moduleDecls.has(node.id.name)) {
                    moduleDecls.set(node.id.name, node.range[0])
                }
            },
            // Collected during the walk and reported at the end: a watcher
            // that names a binding declared *later* is visited before that
            // declaration exists in the map.
            CallExpression(node) {
                if (isImmediate(node) && node.arguments.length > 0) {
                    immediateWatchers.push(node)
                }
            },
            'Program:exit'() {
                for (const node of immediateWatchers) {
                    const seen = new Set()
                    const ids = []
                    collectIdentifiers(node.arguments[0], ids)
                    for (const id of ids) {
                        if (seen.has(id.name)) continue
                        const declStart = moduleDecls.get(id.name)
                        if (declStart !== undefined && id.range[0] < declStart) {
                            seen.add(id.name)
                            context.report({ node: id, messageId: 'dead', data: { name: id.name } })
                        }
                    }
                }
            },
        }
    },
}

// The app's `t` comes from useI18n and binds the app id itself, so it takes
// the string first: t('Save'), not t('doconext_finder', 'Save'). Nextcloud's
// global `t` takes the app id first, and the two are easy to confuse because
// they share a name — a component written against the global signature
// silently misses the escaping default that useI18n applies.
// Renaming our `t` would be worse: scripts/extract-frontend-strings.py matches
// the identifier literally, so a rename stops string extraction with no error.
const noAppIdInT = {
    meta: {
        type: 'problem',
        schema: [],
        messages: { appId: "Drop the app id: this `t` binds it already. Use t('{{text}}')." },
    },
    create(context) {
        function check(node) {
            const callee = node.callee
            if (!callee || callee.type !== 'Identifier') return
            if (callee.name !== 't' && callee.name !== 'n') return
            const first = node.arguments[0]
            if (!first || first.type !== 'Literal' || typeof first.value !== 'string') return
            if (!/^[a-z][a-z0-9_]*$/.test(first.value)) return
            const second = node.arguments[1]
            if (!second || second.type !== 'Literal' || typeof second.value !== 'string') return
            context.report({ node: first, messageId: 'appId', data: { text: second.value.slice(0, 30) } })
        }
        return { CallExpression: check }
    },
}

export default defineConfig([
    // Build output, generated translations, deps, and root tooling/config files
    // are not subject to the app's source style rules.
    {
        ignores: [
            'js/**', 'css/**', 'l10n/**', 'node_modules/**', 'vendor/**', 'build/**',
            'eslint.config.mjs', 'stylelint.config.cjs', 'vite.config.ts',
        ],
    },

    // Vue 3 + TypeScript. Supplies its own parsers, plugins and Nextcloud's
    // coding style, replacing the FlatCompat bridge and the hand-wired vue/ts
    // parser blocks this file used to carry.
    ...recommended,

    {
        name: 'doconext_finder/local-rules',
        plugins: {
            local: { rules: { 'require-t-on-labels': requireT, 'immediate-watch-after-declaration': immediateWatchAfterDeclaration, 'no-app-id-in-t': noAppIdInT } },
        },
        rules: {
            'local/require-t-on-labels': 'warn',
            'local/immediate-watch-after-declaration': 'error',
            'local/no-app-id-in-t': 'error',
        },
    },

    // Project-wide overrides. Same bargain as before the ESLint 10 upgrade:
    // keep the rules that catch bugs, relax the ones that would only produce
    // churn-only diffs across a codebase written to a different house style.
    {
        name: 'doconext_finder/overrides',
        rules: {
            // This codebase is 2-space indented; @nextcloud-config is tabs.
            // The house style wins over the shipped default, so the rule bends
            // rather than the whole tree.
            '@stylistic/indent': ['error', 2, { SwitchCase: 1 }],
            '@stylistic/no-tabs': 'off',

            // Pure formatting. Prettier-style opinions with no bug behind them.
            '@stylistic/arrow-parens': 'off',
            '@stylistic/comma-dangle': 'off',
            '@stylistic/exp-list-style': 'off',
            '@stylistic/function-paren-newline': 'off',
            '@stylistic/implicit-arrow-linebreak': 'off',
            '@stylistic/indent-binary-ops': 'off',
            '@stylistic/max-statements-per-line': 'off',
            '@stylistic/member-delimiter-style': 'off',
            '@stylistic/operator-linebreak': 'off',
            '@stylistic/padded-blocks': 'off',
            '@stylistic/semi': 'off',

            // Import ordering and extension style: the TS compiler already
            // resolves these correctly, and sorting imports would rewrite most
            // files without changing behaviour.
            'perfectionist/sort-imports': 'off',
            'perfectionist/sort-named-imports': 'off',
            'import-extensions/extensions': 'off',
            'import-extensions/ban-inline-type-imports': 'off',

            // Template formatting opinions, off before the upgrade and still off.

            // Component conventions this codebase deliberately does not follow.
            // Misfires on `v-for="x in arr.filter((cb) => ...)"` — it reads the
            // callback param as the iteration variable.

            // JSDoc: documenting is encouraged, not enforced.
            'jsdoc/require-jsdoc': 'off',
            'jsdoc/require-param': 'off',
            'jsdoc/require-param-description': 'off',
            'jsdoc/tag-lines': 'off',
            'jsdoc/multiline-blocks': 'off',

            // Real findings — surfaced, but not blocking a push. Fix as touched.
            // Warn, never autofix in bulk: the translation key IS the English
            // string, and 29 keys in l10n/nl.json still use "...". Rewriting the
            // source without the catalogs drops those strings back to English.
            '@nextcloud/l10n-enforce-ellipsis': 'warn',
            '@nextcloud/l10n-non-breaking-space': 'warn',
            '@nextcloud/no-deprecated-library-props': 'warn',
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/no-use-before-define': 'warn',
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            // 878 hits: this codebase consistently uses brace-less single-line
            // guards. Enforcing braces would bury every other warning.
            curly: 'off',
            eqeqeq: 'warn',
            'no-console': 'warn',
            'no-useless-assignment': 'warn',
        },
    },

    // Template and component conventions. Scoped to .vue because the vue
    // plugin namespace only exists for those files.
    {
        name: 'doconext_finder/overrides-vue',
        files: ['**/*.vue'],
        rules: {
            'vue/html-indent': ['error', 2],
            'vue/comma-dangle': 'off',
            'vue/comma-spacing': 'off',
            'vue/quote-props': 'off',
            'vue/attributes-order': 'off',
            'vue/attribute-hyphenation': 'off',
            'vue/define-macros-order': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/html-self-closing': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/multiline-html-element-content-newline': 'off',
            'vue/prefer-separate-static-class': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/v-on-event-hyphenation': 'off',
            'vue/multi-word-component-names': 'off',
            'vue/no-boolean-default': 'off',
            'vue/require-default-prop': 'off',
            'vue/valid-v-for': 'off',
            'vue/custom-event-name-casing': 'warn',
            // kebab-case slot names are idiomatic in templates; renaming one means
            // touching every parent that fills it.
            'vue/slot-name-casing': 'warn',
            'vue/no-unused-properties': 'warn',
        },
    },

    // unused-vars cannot see template token usage in .vue files, so every
    // component imported for the template reads as unused. tsc enforces
    // noUnusedLocals on these files anyway.
    {
        name: 'doconext_finder/vue-unused-vars',
        files: ['**/*.vue'],
        rules: {
            '@typescript-eslint/no-unused-vars': 'off',
        },
    },
])
