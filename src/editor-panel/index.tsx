/**
 * Post editor integration: the "Live coverage" document panel and the "Context" box.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ContextField from './context-field';
import LivePanel from './panel';
import './style.scss';

registerPlugin( 'newspack-live-panel', { render: LivePanel } );
registerPlugin( 'newspack-live-context', { render: ContextField } );
