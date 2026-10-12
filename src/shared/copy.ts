/**
 * Copies text to the clipboard: `navigator.clipboard` on secure contexts, else a hidden textarea
 * and `document.execCommand( 'copy' )` (the sandbox runs over http).
 */

/**
 * Copies text.
 *
 * @param text    Text.
 * @param control Control that triggered the copy, focused again after the fallback (Safari doesn't focus buttons on click).
 * @return Whether the copy succeeded.
 */
export async function copyText( text: string, control?: HTMLElement ): Promise< boolean > {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		try {
			await window.navigator.clipboard.writeText( text );
			return true;
		} catch {
			// Fall through to the legacy path.
		}
	}
	const area = document.createElement( 'textarea' );
	// Selecting the textarea moves focus: give it back to the control the reader used.
	const focused = control ?? ( area.ownerDocument.activeElement as HTMLElement | null );
	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.position = 'fixed';
	area.style.insetInlineStart = '-9999px';
	document.body.appendChild( area );
	area.select();
	let copied = false;
	try {
		copied = document.execCommand( 'copy' );
	} catch {
		copied = false;
	}
	area.remove();
	focused?.focus( { preventScroll: true } );
	return copied;
}
