import nextCoreWebVitals from 'eslint-config-next/core-web-vitals';
import nextTypeScript from 'eslint-config-next/typescript';

/**
 * ESLint 9 flat config.
 *
 * Previously this bridged the legacy shareable configs through `FlatCompat`.
 * That path crashed on load under ESLint 9 — the eslintrc compatibility layer
 * hit a validation error and then threw "Converting circular structure to JSON"
 * while trying to *format* that error, so the real cause was never printed and
 * linting could not run at all.
 *
 * `eslint-config-next` v16 exports native flat configs from its `/core-web-vitals`
 * and `/typescript` subpaths, so the compat shim is no longer needed. Importing
 * them directly removes the failure rather than working around it.
 */
const eslintConfig = [
  {
    // Globbed with `**/` because the Next app root is `src/`, so its build
    // output lands at `src/.next/`, not the repo-root `.next/`. A root-anchored
    // pattern misses it entirely and ESLint then lints every emitted bundle —
    // which reported 25,163 problems, essentially all of them from minified
    // vendor chunks rather than from any source file.
    ignores: [
      '**/.next/**',
      '**/node_modules/**',
      '**/test-results/**',
      '**/playwright-report/**',
      // Generated from the OpenAPI spec; not ours to style.
      'src/api/generated.ts',
    ],
  },
  ...nextCoreWebVitals,
  ...nextTypeScript,
  {
    rules: {
      // Honour the leading-underscore convention the codebase already uses for
      // deliberately-unused bindings. Without this, a parameter that exists only
      // to satisfy a required signature — `scrubEvent(event, _hint)` matching
      // Sentry's `beforeSend` — reads as an error, and the only ways to silence
      // it are deleting a parameter the caller still passes, or scattering
      // disable comments. `args: 'after-used'` keeps genuinely dead trailing
      // parameters reported.
      '@typescript-eslint/no-unused-vars': [
        'warn',
        {
          args: 'after-used',
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
          destructuredArrayIgnorePattern: '^_',
        },
      ],
    },
  },
];

export default eslintConfig;
