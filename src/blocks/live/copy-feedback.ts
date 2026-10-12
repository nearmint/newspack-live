/**
 * "Copy link" feedback: the item turns into "✓ Link copied" in place for 2 s, announced
 * politely; on failure the error message shows in the same place, without the check.
 */

const timers = new WeakMap< HTMLElement, number >();

/**
 * Shows the result of a copy on its button, then restores it.
 *
 * @param button             Copy button (`.newspack-live-share__copy`).
 * @param status             Polite live region of the menu.
 * @param done               Whether the copy succeeded.
 * @param strings            Messages.
 * @param strings.copied     Success message.
 * @param strings.copyFailed Failure message.
 * @param duration           Milliseconds before reverting.
 */
export function showCopyFeedback(
	button: HTMLElement,
	status: HTMLElement | null,
	done: boolean,
	strings: { copied: string; copyFailed: string },
	duration = 2000
): void {
	const message = done ? strings.copied : strings.copyFailed;
	const text = button.querySelector< HTMLElement >( '.newspack-live-share__copy-done-text' );
	if ( text ) {
		text.textContent = message;
	}
	button.classList.toggle( 'is-copied', done );
	button.classList.toggle( 'is-failed', ! done );
	if ( status ) {
		if ( status.textContent === message ) {
			// Same text again (second copy within 2 s): clear first so it is announced again.
			status.textContent = '';
			window.setTimeout( () => ( status.textContent = message ), 100 );
		} else {
			status.textContent = message;
		}
	}
	window.clearTimeout( timers.get( button ) );
	timers.set(
		button,
		window.setTimeout( () => {
			button.classList.remove( 'is-copied', 'is-failed' );
			if ( status ) {
				status.textContent = '';
			}
		}, duration )
	);
}
