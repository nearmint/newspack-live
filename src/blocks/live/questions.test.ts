/**
 * Internal dependencies
 */
import { draftKey, fetchNonce, setupAskForm } from './questions';

const STRINGS = { counter: '%1$d/%2$d', sending: 'Sending…', send: 'Send question', success: 'Thanks!', network: 'Error', invalidLength: 'Too long' };

/**
 * Renders a registered-only form for an anonymous reader.
 *
 * @return Form.
 */
function renderForm(): HTMLFormElement {
	document.body.innerHTML = `<form class="newspack-live-ask">
		<textarea name="question"></textarea><input name="display_name" />
		<span class="newspack-live-ask__counter"></span><p class="newspack-live-ask__error"></p>
		<p class="newspack-live-ask__actions"><a class="newspack-live-ask__signin" data-action="signin" data-reader-activation="1" href="/wp-login.php">Sign in to ask a question</a></p>
		<p class="newspack-live-ask__success"></p></form>`;
	return document.querySelector( 'form' ) as HTMLFormElement;
}

type ReaderCallback = ( event?: { detail?: { authenticated?: boolean } } ) => void;

describe( 'Reader Activation sign-in without reload', () => {
	let listeners: ReaderCallback[];

	beforeEach( () => {
		listeners = [];
		// Reader Activation is ready: queued callbacks run immediately with its API.
		const queue: unknown[] = [];
		queue.push = ( ...items: unknown[] ) => {
			items.forEach( item =>
				( item as ( ra: object ) => void )( {
					on: ( event: string, callback: ReaderCallback ) => event === 'reader' && listeners.push( callback ),
				} )
			);
			return queue.length;
		};
		( window as unknown as { newspackRAS: unknown[] } ).newspackRAS = queue;
		window.sessionStorage.clear();
	} );

	it( 'swaps the sign-in link for the submit button, with the saved question and a fresh nonce', async () => {
		window.fetch = jest.fn().mockResolvedValue( { ok: true, text: () => Promise.resolve( 'abcdef1234' ) } ) as unknown as typeof window.fetch;
		const form = renderForm();
		const options = {
			liveId: 7,
			restUrl: '/wp-json/newspack-live/v1',
			loggedIn: false,
			nonce: '',
			nonceUrl: '/wp-admin/admin-ajax.php?action=rest-nonce',
			strings: STRINGS,
		};
		setupAskForm( form, options );
		window.sessionStorage.setItem( draftKey( 7 ), JSON.stringify( { question: 'Saved question?', name: 'Ana' } ) );

		listeners.forEach( listener => listener( { detail: { authenticated: false } } ) );
		expect( form.querySelector( '[data-action="signin"]' ) ).not.toBeNull();

		listeners.forEach( listener => listener( { detail: { authenticated: true } } ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( form.querySelector( '[data-action="signin"]' ) ).toBeNull();
		expect( form.querySelector( 'button[type="submit"]' )?.textContent ).toBe( 'Send question' );
		expect( ( form.querySelector( 'textarea' ) as HTMLTextAreaElement ).value ).toBe( 'Saved question?' );
		expect( ( form.querySelector( 'input' ) as HTMLInputElement ).value ).toBe( 'Ana' );
		expect( options.loggedIn ).toBe( true );
		expect( options.nonce ).toBe( 'abcdef1234' );
	} );

	it( 'keeps the sign-in link when the session has no nonce', async () => {
		window.fetch = jest.fn().mockResolvedValue( { ok: true, text: () => Promise.resolve( '0' ) } ) as unknown as typeof window.fetch;
		expect( await fetchNonce( '/nonce' ) ).toBe( '' );
		const form = renderForm();
		setupAskForm( form, { liveId: 7, restUrl: '/r', loggedIn: false, nonce: '', nonceUrl: '/nonce', strings: STRINGS } );
		listeners.forEach( listener => listener( { detail: { authenticated: true } } ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( form.querySelector( '[data-action="signin"]' ) ).not.toBeNull();
	} );

	it( 'retries on a later reader event when the first nonce request fails', async () => {
		window.fetch = jest
			.fn()
			.mockResolvedValueOnce( { ok: false, text: () => Promise.resolve( '0' ) } )
			.mockResolvedValue( { ok: true, text: () => Promise.resolve( '0123456789' ) } ) as unknown as typeof window.fetch;
		const form = renderForm();
		setupAskForm( form, { liveId: 7, restUrl: '/r', loggedIn: false, nonce: '', nonceUrl: '/nonce', strings: STRINGS } );
		listeners.forEach( listener => listener( { detail: { authenticated: true } } ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( form.querySelector( '[data-action="signin"]' ) ).not.toBeNull();
		listeners.forEach( listener => listener( { detail: { authenticated: true } } ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( form.querySelector( 'button[type="submit"]' ) ).not.toBeNull();
	} );
} );

describe( 'submission', () => {
	it( 'sends the question and restores the button label', async () => {
		document.body.innerHTML = `<form class="newspack-live-ask">
			<textarea name="question"></textarea><input name="display_name" value="Ana" />
			<span class="newspack-live-ask__counter"></span><p class="newspack-live-ask__error"></p>
			<p class="newspack-live-ask__actions"><button type="submit" class="newspack-live-ask__submit">Send question</button></p>
			<p class="newspack-live-ask__success"></p></form>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		const fetch = jest.fn().mockResolvedValue( { ok: true, json: () => Promise.resolve( {} ) } );
		window.fetch = fetch as unknown as typeof window.fetch;
		setupAskForm( form, { liveId: 7, restUrl: '/wp-json/newspack-live/v1', loggedIn: false, nonce: '', strings: STRINGS } );

		( form.querySelector( 'textarea' ) as HTMLTextAreaElement ).value = 'When are results due?';
		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );

		const body = String( fetch.mock.calls[ 0 ][ 1 ].body );
		expect( body ).toContain( 'question=When+are+results+due%3F' );
		expect( body ).not.toContain( 'kind=' );
		expect( form.querySelector( '.newspack-live-ask__success' )?.textContent ).toBe( STRINGS.success );
		const button = form.querySelector( '.newspack-live-ask__submit' ) as HTMLButtonElement;
		expect( button.textContent ).toBe( 'Send question' );
		expect( button.disabled ).toBe( false );
	} );
} );
