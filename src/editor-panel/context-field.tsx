/**
 * "Context" box below the block editor: a bulleted list of key facts, bound to the
 * live's `_newspack_live_context` meta (stored as one `<ul>`). It is a small block editor where each
 * paragraph is shown as a bullet, so Enter adds a bullet and Backspace merges two, with a fixed
 * toolbar offering bold, italic and link. RichText alone can't hold several items since WordPress
 * 6.3 removed its `multiline` prop.
 */

/**
 * WordPress dependencies
 */
import {
	BlockEditorProvider,
	BlockList,
	BlockToolbar,
	BlockTools,
	ObserveTyping,
	WritingFlow,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { useEntityProp } from '@wordpress/core-data';
import { createRegistry, RegistryProvider, useRegistry, useSelect, type StoreDescriptor } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { createPortal, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as richTextStore } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import { blockItems, contextItems, listHtml, type BlockInstance } from './context-html';

export const CONTEXT_META = '_newspack_live_context';

// Each paragraph is a bullet; the stored value is a list.
const ALLOWED_BLOCKS = [ 'core/paragraph' ];
const ALLOWED_FORMATS = [ 'core/bold', 'core/italic', 'core/link' ];

type Meta = Record< string, unknown >;

interface FormatType {
	name: string;
}

// The rich-text package types its store as a plain object.
const RICH_TEXT = richTextStore as unknown as StoreDescriptor;

/**
 * Blocks of a Context value (HTML): a paragraph per bullet, one empty paragraph when empty.
 *
 * @param html Context HTML.
 * @return Blocks.
 */
function toBlocks( html: string ): BlockInstance[] {
	const items = contextItems( html );
	if ( ! items.length ) {
		return [ createBlock( 'core/paragraph', { placeholder: __( 'Add a key fact…', 'newspack-live' ) } ) as BlockInstance ];
	}
	return items.map( content => createBlock( 'core/paragraph', { content } ) as BlockInstance );
}

/**
 * A registry of its own for the Context editor: its own block editor store, and a rich-text store
 * that only knows bold, italic and link (the toolbar offers nothing else). Other stores come from
 * the post editor.
 *
 * @return Registry.
 */
function useContextRegistry() {
	const parent = useRegistry();
	return useMemo( () => {
		const registry = createRegistry( {}, parent );
		registry.register( blockEditorStore );
		registry.register( RICH_TEXT );
		const formats = ( parent.select( RICH_TEXT ) as unknown as { getFormatTypes: () => FormatType[] } )
			.getFormatTypes()
			.filter( format => ALLOWED_FORMATS.includes( format.name ) );
		( registry.dispatch( RICH_TEXT ) as unknown as { addFormatTypes: ( types: FormatType[] ) => void } ).addFormatTypes( formats );
		return registry;
	}, [ parent ] );
}

/**
 * The editor.
 *
 * @param props          Props.
 * @param props.postType Post type.
 * @return Editor.
 */
function ContextEditor( { postType }: { postType: string } ) {
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' ) as unknown as [ Meta, ( next: Meta ) => void ];
	const value = String( meta?.[ CONTEXT_META ] ?? '' );
	const [ blocks, setBlocks ] = useState< BlockInstance[] >( () => toBlocks( value ) );
	// The last value written from here: an outside change (undo, another tab's save) rebuilds the blocks.
	const written = useRef( value );
	const registry = useContextRegistry();

	useEffect( () => {
		if ( value !== written.current ) {
			written.current = value;
			setBlocks( toBlocks( value ) );
		}
	}, [ value ] );

	const onChange = ( next: BlockInstance[] ) => {
		setBlocks( next );
		const html = listHtml( blockItems( next ) );
		if ( html !== written.current ) {
			written.current = html;
			setMeta( { ...meta, [ CONTEXT_META ]: html } );
		}
	};

	const settings = useMemo(
		() => ( {
			allowedBlockTypes: ALLOWED_BLOCKS,
			// The toolbar sits at the top of the field (BlockToolbar below), not over the text.
			hasFixedToolbar: true,
			// Bullets only: no text alignment, drop cap or other paragraph settings.
			__experimentalFeatures: {
				typography: { dropCap: false, textAlign: false },
				color: { text: false, background: false, link: false },
			},
			// No images, files or other media in the Context.
			mediaUpload: undefined,
		} ),
		[]
	);

	return (
		<div className="newspack-live-context-editor">
			<div
				className="newspack-live-context-editor__field"
				role="group"
				aria-label={ __( 'Context: key facts', 'newspack-live' ) }
				aria-describedby="newspack-live-context-help"
			>
				<RegistryProvider value={ registry }>
					<BlockEditorProvider value={ blocks } onInput={ onChange } onChange={ onChange } settings={ settings } useSubRegistry={ false }>
						<div className="newspack-live-context-editor__toolbar">
							<BlockToolbar hideDragHandle />
							<span className="newspack-live-context-editor__toolbar-hint">
								{ __( 'Click a bullet to format it: bold, italic, link.', 'newspack-live' ) }
							</span>
						</div>
						<BlockTools>
							<WritingFlow>
								<ObserveTyping>
									<BlockList />
								</ObserveTyping>
							</WritingFlow>
						</BlockTools>
					</BlockEditorProvider>
				</RegistryProvider>
			</div>
			<p className="newspack-live-context-editor__help" id="newspack-live-context-help">
				{ __(
					'Key facts readers need to follow the live (what, when, where, who), one per bullet: press Enter to add a bullet. Shown in the Context card above the question form.',
					'newspack-live'
				) }
			</p>
		</div>
	);
}

/**
 * Mounts the editor in the "Context" box (a meta box below the block editor) and shows the box only
 * while live coverage is on.
 *
 * @return Portal, or null until the box exists.
 */
export default function ContextField() {
	const hasBox = !! window.newspackLiveEditor?.contextBox;
	const postType = useSelect( select => select( editorStore ).getCurrentPostType() as string, [] );
	const [ meta ] = useEntityProp( 'postType', postType, 'meta' ) as unknown as [ Meta ];
	const enabled = !! meta?._newspack_live_enabled;
	const [ root, setRoot ] = useState< HTMLElement | null >( () => document.getElementById( 'newspack-live-context-root' ) );

	// Meta boxes are moved into the editor after it renders: wait for the box.
	useEffect( () => {
		if ( root || ! hasBox ) {
			return;
		}
		const observer = new window.MutationObserver( () => {
			const found = document.getElementById( 'newspack-live-context-root' );
			if ( found ) {
				observer.disconnect();
				setRoot( found );
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		return () => observer.disconnect();
	}, [ root, hasBox ] );

	useEffect( () => {
		root?.closest( '.postbox' )?.classList.toggle( 'newspack-live-context--off', ! enabled );
	}, [ root, enabled ] );

	return root ? createPortal( <ContextEditor postType={ postType } />, root ) : null;
}
