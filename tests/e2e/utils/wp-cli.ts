/**
 * Runs WP-CLI against the sandbox through bin/wp.local.sh.
 */

/**
 * External dependencies
 */
import { execFileSync } from 'child_process';
import path from 'path';

/**
 * Internal dependencies
 */
import { PLUGIN_DIR } from './env';

const WRAPPER = path.join( PLUGIN_DIR, 'bin/wp.local.sh' );

/**
 * Runs a WP-CLI command and returns its trimmed standard output.
 *
 * @param args Command arguments (no shell parsing).
 * @return Standard output.
 */
export function wp( ...args: string[] ): string {
	return execFileSync( WRAPPER, args, {
		cwd: PLUGIN_DIR,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		timeout: 120_000,
	} ).trim();
}

/**
 * Runs a WP-CLI command, returning null instead of throwing on failure.
 *
 * @param args Command arguments.
 * @return Standard output, or null.
 */
export function wpTry( ...args: string[] ): string | null {
	try {
		return wp( ...args );
	} catch {
		return null;
	}
}

/**
 * Whether a WP-CLI command is registered.
 *
 * @param command Command name, e.g. "newspack-live clean-e2e".
 * @return True when available.
 */
export function hasCommand( command: string ): boolean {
	return wpTry( 'cli', 'has-command', command ) !== null;
}
