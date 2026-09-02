import { FlatCompat } from '@eslint/eslintrc'
import js from '@eslint/js'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const compat = new FlatCompat({
    baseDirectory: __dirname,
    recommendedConfig: js.configs.recommended,
    allConfig: js.configs.all,
})

import vueParser from 'vue-eslint-parser'
import tsParser from '@typescript-eslint/parser'
import tsPlugin from '@typescript-eslint/eslint-plugin'

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

export default [
    // Build output, generated translations, deps, and root tooling/config files
    // are not subject to the app's source style rules.
    {
        ignores: [
            'js/**', 'css/**', 'l10n/**', 'node_modules/**', 'vendor/**', 'build/**',
            'eslint.config.mjs', '.eslintrc.cjs', 'stylelint.config.cjs', 'vite.config.ts',
        ],
    },
    ...compat.extends('@nextcloud'),
    {
        files: ['**/*.vue'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tsParser,
                extraFileExtensions: ['.vue'],
                ecmaVersion: 'latest',
                sourceType: 'module',
            },
        },
    },
    {
        files: ['**/*.ts'],
        languageOptions: {
            parser: tsParser,
            parserOptions: {
                ecmaVersion: 'latest',
                sourceType: 'module',
            },
        },
    },
    // Project-wide rule overrides: this codebase uses 2-space indent, not the
    // @nextcloud-config default of tabs. Keep the bug-catching rules; relax
    // the purely-stylistic ones to avoid churn-only diffs.
    {
        plugins: {
            '@typescript-eslint': tsPlugin,
            core: { rules: { 'require-t-on-labels': requireT } },
        },
        rules: {
            'core/require-t-on-labels': 'warn',
            indent: ['error', 2, { SwitchCase: 1 }],
            'vue/html-indent': ['error', 2],
            'vue/script-indent': ['error', 2, { baseIndent: 0, switchCase: 1 }],
            'vue/max-attributes-per-line': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/multiline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/attributes-order': 'off',
            'vue/component-name-in-template-casing': 'off',
            'vue/match-component-file-name': 'off',
            'vue/no-v-html': 'off',
            'n/no-missing-import': 'off',
            'n/no-extraneous-import': 'off',
            'jsdoc/require-jsdoc': 'off',
            'jsdoc/require-param-description': 'off',
            'jsdoc/require-returns-description': 'off',
            'jsdoc/no-undefined-types': 'off',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            'no-undef': 'off',
            // import/* rules need a TS-aware resolver to handle path aliases and
            // .ts/.vue extensions; without one they produce ~400 false positives.
            // The TS compiler enforces these correctly, so leave it to tsc.
            'import/no-unresolved': 'off',
            'import/extensions': 'off',
            'import/named': 'off',
            'import/default': 'off',
            'import/namespace': 'off',
            'import/no-named-as-default': 'off',
            'import/no-named-as-default-member': 'off',
            'vue/require-default-prop': 'off',
            // Misfires on `v-for="x in arr.filter((cb) => ...)"` — treats the
            // callback param as the iteration var. Real missing-:key issues
            // surface in dev console at runtime anyway.
            'vue/valid-v-for': 'off',
            // Vue-2 only rules that misfire on legitimate Vue-3 code:
            'vue/no-multiple-template-root': 'off',
            'vue/no-v-for-template-key': 'off',
            'vue/no-v-model-argument': 'off',
            // Real findings — flag but don't fail CI; fix opportunistically.
            'vue/custom-event-name-casing': 'warn',
            'vue/no-reserved-props': 'warn',
            'vue/no-dupe-keys': 'warn',
            'no-use-before-define': 'warn',
            // Misfires on TS generic syntax: `defineEmits<{...}>()` reads the
            // `<...>` as whitespace between function name and `(`.
            'func-call-spacing': 'off',
            '@typescript-eslint/func-call-spacing': 'off',
            'no-console': 'warn',
            'jsdoc/require-param-type': 'off',
            'jsdoc/require-returns-type': 'off',
            'no-tabs': 'off',
        },
    },
    // Vue SFC-specific. Goes LAST so it overrides the project-wide block.
    //
    // unused-vars rules don't work reliably inside .vue files with our
    // FlatCompat + <script setup> + TS-parser combination — template token
    // usage isn't seen by the parser, producing ~1000 false positives
    // ("NcButton defined but never used" when it's used in the template).
    // The TS compiler enforces `noUnusedLocals` anyway, so this is covered.
    {
        files: ['**/*.vue'],
        rules: {
            'no-unused-vars': 'off',
            '@typescript-eslint/no-unused-vars': 'off',
        },
    },
]
