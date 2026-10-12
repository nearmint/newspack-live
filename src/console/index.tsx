/**
 * Live console entry point.
 */

/**
 * WordPress dependencies
 */
import { registerCoreBlocks, __experimentalGetCoreBlocks as getCoreBlocks } from '@wordpress/block-library'; // eslint-disable-line @wordpress/no-unsafe-wp-apis -- only way to register a subset of core blocks.
import { getBlockType } from '@wordpress/blocks';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { MediaUpload } from '@wordpress/media-utils';
import '@wordpress/format-library';

/**
 * Internal dependencies
 */
import App from './components/app';
import { config } from './config';
import './store';
import './style.scss';

/**
 * Restricts the heading block to levels 3 and 4.
 *
 * @param settings            Block settings.
 * @param settings.attributes Block attributes.
 * @param name                Block name.
 * @return Settings.
 */
function limitHeadingLevels( settings: { attributes?: Record< string, { default?: unknown } > }, name: string ) {
	if ( name !== 'core/heading' || ! settings.attributes ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...settings.attributes,
			level: { ...settings.attributes.level, default: 3 },
			levelOptions: { ...settings.attributes.levelOptions, default: [ 3, 4 ] },
		},
	};
}

domReady( () => {
	const root = document.getElementById( 'newspack-live-console' );
	if ( ! root ) {
		return;
	}
	// Headings in entries are levels 3–4 only; the entry title is the h3 of the frame.
	addFilter( 'blocks.registerBlockType', 'newspack-live/heading-levels', limitHeadingLevels );
	// Only the allowlisted core blocks, and only those not registered yet.
	const blocks = ( getCoreBlocks() as Array< { name: string } > ).filter(
		block => config.allowedBlocks.includes( block.name ) && ! getBlockType( block.name )
	);
	registerCoreBlocks( blocks as Parameters< typeof registerCoreBlocks >[ 0 ] );
	addFilter( 'editor.MediaUpload', 'newspack-live/media-upload', () => MediaUpload );
	createRoot( root ).render( <App /> );
} );
