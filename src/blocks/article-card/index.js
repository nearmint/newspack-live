/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, BlockControls } from '@wordpress/block-editor';
import { Placeholder, SearchControl, Button, Spinner, ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import './style.scss';

/**
 * Post search: published posts matching the query.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onSelect Called with the chosen post ID.
 * @return {Element} Search UI.
 */
function PostSearch( { onSelect } ) {
	const [ search, setSearch ] = useState( '' );
	const { posts, isLoading } = useSelect(
		select => {
			const query = { search, per_page: 10, status: 'publish', _fields: 'id,title,date' };
			return {
				posts: select( coreStore ).getEntityRecords( 'postType', 'post', query ),
				isLoading: ! select( coreStore ).hasFinishedResolution( 'getEntityRecords', [ 'postType', 'post', query ] ),
			};
		},
		[ search ]
	);
	return (
		<div className="newspack-live-card-search">
			<SearchControl label={ __( 'Search stories', 'newspack-live' ) } value={ search } onChange={ setSearch } />
			{ isLoading && <Spinner /> }
			{ ! isLoading && posts?.length === 0 && <p>{ __( 'No stories found.', 'newspack-live' ) }</p> }
			<ul className="newspack-live-card-search__results">
				{ ( posts || [] ).map( post => (
					<li key={ post.id }>
						<Button variant="link" onClick={ () => onSelect( post.id ) }>
							{ decodeEntities( post.title?.rendered || __( '(no title)', 'newspack-live' ) ) }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Editor of the article card.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 * @return {Element} Editor UI.
 */
function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const { postId } = attributes;

	if ( ! postId ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					label={ __( 'Article card', 'newspack-live' ) }
					instructions={ __( 'Choose a published story to link to.', 'newspack-live' ) }
				>
					<PostSearch onSelect={ id => setAttributes( { postId: id } ) } />
				</Placeholder>
			</div>
		);
	}
	return (
		<div { ...blockProps }>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton onClick={ () => setAttributes( { postId: 0 } ) }>{ __( 'Replace story', 'newspack-live' ) }</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>
			<ServerSideRender block={ metadata.name } attributes={ attributes } />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
