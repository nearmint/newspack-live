/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { Placeholder } from '@wordpress/components';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import './style.scss';

/**
 * Editor preview of the live block: it renders on the front end only.
 *
 * @return {Element} Placeholder.
 */
function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ __( 'Live coverage', 'newspack-live' ) }
				instructions={ __( 'The live header, context, questions and updates of the current post display here.', 'newspack-live' ) }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
