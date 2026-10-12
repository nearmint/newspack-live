/**
 * Playwright global teardown: removes the e2e constant and the e2e content.
 */

/**
 * Internal dependencies
 */
import { wp } from './utils/wp-cli';

export default async function globalTeardown(): Promise< void > {
	try {
		wp( 'config', 'delete', 'NEWSPACK_LIVE_E2E' );
	} finally {
		// The theme smoke tests restore it themselves; this covers interrupted runs.
		wp( 'theme', 'activate', 'newspack-theme' );
		wp( 'newspack-live', 'clean-e2e' );
	}
}
