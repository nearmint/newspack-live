require( '@rushstack/eslint-patch/modern-module-resolution' );

module.exports = {
	root: true,
	extends: [ './node_modules/newspack-scripts/config/eslintrc.js' ],
	ignorePatterns: [ 'build/', 'vendor/', 'node_modules/', 'release/', 'test-results/', 'playwright-report/' ],
	overrides: [
		{
			// Node tooling: Playwright specs and dev scripts.
			files: [ 'tests/e2e/**/*.ts', 'bin/*.mjs', '*.config.ts' ],
			env: { node: true, browser: true },
			rules: {
				'no-console': 'off',
			},
		},
	],
};
