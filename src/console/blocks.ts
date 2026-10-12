/**
 * Block type used by the console editors.
 */

/**
 * WordPress dependencies
 */
import type { parse } from '@wordpress/blocks';

export type BlockInstance = ReturnType< typeof parse >[ number ];
