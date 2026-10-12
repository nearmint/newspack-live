/**
 * Embedded block editor for entry bodies.
 */

/**
 * WordPress dependencies
 */
import { BlockEditorKeyboardShortcuts, BlockEditorProvider, BlockList, BlockTools, ObserveTyping, WritingFlow } from '@wordpress/block-editor';
import { useMemo } from '@wordpress/element';
import { uploadMedia } from '@wordpress/media-utils';

/**
 * Internal dependencies
 */
import type { BlockInstance } from '../blocks';
import { config } from '../config';

interface Props {
	blocks: BlockInstance[];
	onChange: ( blocks: BlockInstance[] ) => void;
	allowedBlocks: string[];
	label: string;
	canUpload: boolean;
}

interface UploadArgs {
	filesList: File[];
	onFileChange: ( files: unknown[] ) => void;
	allowedTypes?: string[];
	onError: ( message: string ) => void;
}

/**
 * Block editor bound to a list of blocks.
 *
 * @param props               Props.
 * @param props.blocks        Blocks.
 * @param props.onChange      Change handler.
 * @param props.allowedBlocks Allowed block names.
 * @param props.label         Accessible label of the editor.
 * @param props.canUpload     Whether media uploads are allowed.
 * @return Editor.
 */
export default function BodyEditor( { blocks, onChange, allowedBlocks, label, canUpload }: Props ) {
	const settings = useMemo(
		() => ( {
			...config.editorSettings,
			allowedBlockTypes: allowedBlocks,
			hasFixedToolbar: true,
			mediaUpload: canUpload
				? ( { filesList, onFileChange, allowedTypes, onError }: UploadArgs ) =>
						uploadMedia( {
							filesList,
							onFileChange,
							allowedTypes,
							onError: ( error: { message: string } ) => onError( error.message ),
							additionalData: { post: config.liveId },
						} )
				: undefined,
		} ),
		[ allowedBlocks.join( ',' ), canUpload ]
	);

	return (
		<div className="newspack-live-body-editor" role="group" aria-label={ label }>
			<BlockEditorProvider value={ blocks } onInput={ onChange } onChange={ onChange } settings={ settings }>
				<BlockEditorKeyboardShortcuts.Register />
				<BlockTools>
					<WritingFlow>
						<ObserveTyping>
							<BlockList />
						</ObserveTyping>
					</WritingFlow>
				</BlockTools>
			</BlockEditorProvider>
		</div>
	);
}
