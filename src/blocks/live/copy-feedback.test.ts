/**
 * Internal dependencies
 */
import { showCopyFeedback } from './copy-feedback';

const STRINGS = { copied: 'Link copied', copyFailed: 'The link could not be copied.' };

/**
 * Renders a share menu.
 *
 * @return Button and status.
 */
function menu(): { button: HTMLElement; status: HTMLElement } {
	document.body.innerHTML = `<div class="newspack-live-share__popover"><button class="newspack-live-share__copy" data-action="copy-link">
		<span class="newspack-live-share__copy-label">Copy link</span>
		<span class="newspack-live-share__copy-done" aria-hidden="true"><span class="newspack-live-share__check"></span><span class="newspack-live-share__copy-done-text">Link copied</span></span>
	</button><span class="newspack-live-share__status" role="status"></span></div>`;
	return { button: document.querySelector( 'button' ) as HTMLElement, status: document.querySelector( '[role="status"]' ) as HTMLElement };
}

describe( 'copy feedback', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'shows "Link copied" in place for 2 s, announces it, keeps focus, then reverts', () => {
		const { button, status } = menu();
		button.focus();
		showCopyFeedback( button, status, true, STRINGS );
		expect( button.classList.contains( 'is-copied' ) ).toBe( true );
		expect( status.textContent ).toBe( 'Link copied' );
		expect( button.ownerDocument.activeElement ).toBe( button );
		jest.advanceTimersByTime( 1999 );
		expect( button.classList.contains( 'is-copied' ) ).toBe( true );
		jest.advanceTimersByTime( 1 );
		expect( button.classList.contains( 'is-copied' ) ).toBe( false );
		expect( status.textContent ).toBe( '' );
	} );

	it( 'shows the error in the same place, without the check', () => {
		const { button, status } = menu();
		showCopyFeedback( button, status, false, STRINGS );
		expect( button.classList.contains( 'is-failed' ) ).toBe( true );
		expect( button.classList.contains( 'is-copied' ) ).toBe( false );
		expect( button.querySelector( '.newspack-live-share__copy-done-text' )?.textContent ).toBe( STRINGS.copyFailed );
		expect( status.textContent ).toBe( STRINGS.copyFailed );
	} );

	it( 'restarts the 2 s on a second copy', () => {
		const { button, status } = menu();
		showCopyFeedback( button, status, true, STRINGS );
		jest.advanceTimersByTime( 1500 );
		showCopyFeedback( button, status, true, STRINGS );
		jest.advanceTimersByTime( 1500 );
		expect( button.classList.contains( 'is-copied' ) ).toBe( true );
	} );
} );
