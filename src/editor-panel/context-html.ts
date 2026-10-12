/**
 * Pure helpers of the Context box, unit-tested.
 */

/**
 * WordPress dependencies
 */
import type { parse } from '@wordpress/blocks';

export type BlockInstance = ReturnType< typeof parse >[ number ];

/**
 * Whether Context HTML holds no text.
 *
 * @param html HTML.
 * @return True when empty.
 */
export function isEmptyContext( html: string ): boolean {
	return (
		html
			.replace( /<!--[\s\S]*?-->/g, '' )
			.replace( /<[^>]+>/g, '' )
			.replace( /&nbsp;|\u00a0/g, ' ' )
			.trim() === ''
	);
}

/**
 * The bullets of a Context value: its list items (nested ones flattened) and its paragraphs.
 * Loose text is one bullet.
 *
 * @param html Context HTML.
 * @return Bullets (HTML), without empty ones.
 */
export function contextItems( html: string ): string[] {
	if ( isEmptyContext( html ) ) {
		return [];
	}
	const body = new window.DOMParser().parseFromString( `<body>${ html }</body>`, 'text/html' ).body;
	const items: string[] = [];
	const collect = ( parent: Element ) => {
		let loose = '';
		const flush = () => {
			items.push( loose );
			loose = '';
		};
		parent.childNodes.forEach( node => {
			const name = node.nodeType === 1 ? ( node as Element ).tagName.toLowerCase() : '';
			if ( name === 'p' ) {
				flush();
				items.push( ( node as Element ).innerHTML );
			} else if ( name === 'ul' || name === 'ol' ) {
				flush();
				collect( node as Element );
			} else if ( name === 'li' ) {
				flush();
				// The item's own text, then its nested items.
				const item = node.cloneNode( true ) as Element;
				item.querySelectorAll( 'ul, ol' ).forEach( list => list.remove() );
				items.push( item.innerHTML );
				( node as Element ).querySelectorAll( ':scope > ul, :scope > ol' ).forEach( list => collect( list ) );
			} else {
				loose +=
					node.nodeType === 1
						? ( node as Element ).outerHTML
						: ( node.textContent ?? '' ).replace( /[&<>]/g, char => `&#${ char.charCodeAt( 0 ) };` );
			}
		} );
		flush();
	};
	collect( body );
	return items.map( item => item.trim() ).filter( item => ! isEmptyContext( item ) );
}

/**
 * The value stored in the Context meta: one bulleted list (the server keeps the allowed formatting
 * only), or '' when no bullet has text.
 *
 * @param items Bullets (HTML).
 * @return HTML.
 */
export function listHtml( items: string[] ): string {
	const kept = items.map( item => item.trim() ).filter( item => ! isEmptyContext( item ) );
	return kept.length ? `<ul><li>${ kept.join( '</li><li>' ) }</li></ul>` : '';
}

/**
 * The bullets of editor blocks: each paragraph is a bullet; lists that got in anyway (e.g. pasted)
 * give one bullet per item.
 *
 * @param blocks Blocks.
 * @return Bullets (HTML).
 */
export function blockItems( blocks: BlockInstance[] ): string[] {
	return blocks.flatMap( block => {
		const content = block.attributes?.content;
		const own = content === undefined || content === null ? [] : [ String( content ) ];
		return [ ...own, ...blockItems( block.innerBlocks ?? [] ) ];
	} );
}
