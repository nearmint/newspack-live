/**
 * "Load more" for the console lists: loads the next 50 items, announces them and moves the
 * focus to the first new item (keyboard and screen-reader users continue where the list grew).
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Item IDs rendered in a list (`data-item-id` on each item).
 *
 * @param list List element.
 * @return IDs.
 */
export const renderedIds = ( list: HTMLElement | null ): number[] =>
	Array.from( list?.querySelectorAll< HTMLElement >( ':scope > [data-item-id]' ) ?? [] ).map( item => Number( item.dataset.itemId ) );

/**
 * Focuses the first item of a list that wasn't there before, once React has rendered it (checked
 * on each animation frame, for up to about a second).
 *
 * @param list   List element getter.
 * @param before IDs rendered before loading.
 * @param tries  Frames left.
 */
export function focusFirstNew( list: () => HTMLElement | null, before: number[], tries = 60 ): void {
	const known = new Set( before );
	window.requestAnimationFrame( () => {
		const item = Array.from( list()?.querySelectorAll< HTMLElement >( ':scope > [data-item-id]' ) ?? [] ).find(
			element => ! known.has( Number( element.dataset.itemId ) )
		);
		if ( item ) {
			item.focus();
		} else if ( tries > 1 ) {
			focusFirstNew( list, before, tries - 1 );
		}
	} );
}

/**
 * Load more button.
 *
 * @param props        Props.
 * @param props.onLoad Loads the next page; resolves to the number of items loaded, or null when the
 *                     list changed meanwhile (nothing announced, focus left alone).
 * @param props.list   The list element (to find and focus the first new item).
 * @return Element.
 */
export default function LoadMore( { onLoad, list }: { onLoad: () => Promise< number | null >; list: () => HTMLElement | null } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const load = async () => {
		const before = renderedIds( list() );
		setBusy( true );
		setError( '' );
		try {
			const count = await onLoad();
			if ( count !== null ) {
				speak(
					sprintf(
						/* translators: %d: number of items loaded. */
						_n( '%d more item loaded.', '%d more items loaded.', count, 'newspack-live' ),
						count
					)
				);
				focusFirstNew( list, before );
			}
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? __( 'Something went wrong. Please try again.', 'newspack-live' );
			setError( message );
			speak( message, 'assertive' );
		} finally {
			setBusy( false );
		}
	};
	return (
		<div className="newspack-live-console__load-more">
			<Button variant="secondary" onClick={ load } isBusy={ busy } disabled={ busy } accessibleWhenDisabled>
				{ __( 'Load more', 'newspack-live' ) }
			</Button>
			{ error && <p className="newspack-live-console-entry__error">{ error }</p> }
		</div>
	);
}
