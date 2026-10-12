/**
 * Playwright configuration.
 */

/**
 * External dependencies
 */
import { defineConfig, devices } from '@playwright/test';

/**
 * Internal dependencies
 */
import { BASE_URL } from './tests/e2e/utils/env';

export default defineConfig( {
	testDir: './tests/e2e/specs',
	// `@yoast` specs need Yoast SEO, which the sandbox doesn't keep: `npm run test:e2e:yoast`.
	grepInvert: process.env.NEWSPACK_LIVE_YOAST ? undefined : /@yoast/,
	globalSetup: './tests/e2e/global-setup.ts',
	globalTeardown: './tests/e2e/global-teardown.ts',
	// The sandbox is shared state.
	workers: 1,
	fullyParallel: false,
	retries: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
