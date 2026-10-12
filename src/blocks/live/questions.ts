/**
 * Ask-a-question form: counter, submission, inline errors, and the
 * registered-only sign-in flow that keeps the typed question across sign-in.
 */

export interface AskStrings {
	counter: string;
	sending: string;
	send: string;
	success: string;
	network: string;
	invalidLength: string;
}

export interface AskOptions {
	liveId: number;
	restUrl: string;
	loggedIn: boolean;
	nonce: string;
	/** `admin-ajax.php?action=rest-nonce`, to get a nonce after signing in without a reload. */
	nonceUrl?: string;
	strings: AskStrings;
	onSubmitted?: () => void;
}

export const MAX_QUESTION = 500;
export const MAX_NAME = 50;

/**
 * sessionStorage key holding a question typed before signing in.
 *
 * @param liveId Live ID.
 * @return Key.
 */
export const draftKey = ( liveId: number ) => `newspack-live-ask:${ liveId }`;

/**
 * Character count as the server counts it (code points, like `mb_strlen`).
 *
 * @param text Text.
 * @return Length.
 */
export const length = ( text: string ): number => Array.from( text ).length;

/**
 * Saves the typed question and name before signing in (best effort: storage may be unavailable).
 *
 * @param liveId   Live ID.
 * @param question Question.
 * @param name     Display name.
 */
export function saveDraft( liveId: number, question: string, name: string ): void {
	try {
		window.sessionStorage.setItem( draftKey( liveId ), JSON.stringify( { question, name } ) );
	} catch {
		// Private mode: the reader types again after signing in.
	}
}

/**
 * Reads and clears the saved question.
 *
 * @param liveId Live ID.
 * @return Draft or null.
 */
export function takeDraft( liveId: number ): { question: string; name: string } | null {
	try {
		const raw = window.sessionStorage.getItem( draftKey( liveId ) );
		window.sessionStorage.removeItem( draftKey( liveId ) );
		const draft = raw ? ( JSON.parse( raw ) as { question?: unknown; name?: unknown } ) : null;
		// Only strings are restored (the value may have been tampered with).
		return draft
			? { question: typeof draft.question === 'string' ? draft.question : '', name: typeof draft.name === 'string' ? draft.name : '' }
			: null;
	} catch {
		return null;
	}
}

interface Reader {
	authenticated?: boolean;
}

interface ReaderActivation {
	// Listeners get a `CustomEvent` whose `detail` is the reader.
	on?: ( event: string, callback: ( event?: { detail?: Reader } ) => void ) => void;
	getReader?: () => Reader | undefined;
}

/**
 * Calls back when Newspack Reader Activation reports a signed-in reader (e.g. after its sign-in
 * modal, without a page reload), until the callback reports success (later events retry). Uses Reader Activation's `newspackRAS` queue, which
 * runs the callback once Reader Activation is ready (or never, when it isn't on the page).
 *
 * @param callback Callback; resolves true once handled.
 */
export function onReaderSignedIn( callback: () => Promise< boolean > ): void {
	const host = window as unknown as { newspackRAS?: unknown[] };
	const queue = ( host.newspackRAS = host.newspackRAS || [] );
	let busy = false;
	let done = false;
	const check = async ( reader?: Reader ) => {
		if ( done || busy || ! reader?.authenticated ) {
			return;
		}
		busy = true;
		try {
			done = await callback();
		} finally {
			busy = false;
		}
	};
	queue.push( ( readerActivation: ReaderActivation ) => {
		readerActivation.on?.( 'reader', event => check( readerActivation.getReader?.() ?? event?.detail ) );
		// Already signed in when Reader Activation got to the queue (its first event came earlier).
		check( readerActivation.getReader?.() );
	} );
}

/**
 * Fetches a REST nonce for the current (cookie) session.
 *
 * @param url `admin-ajax.php?action=rest-nonce`.
 * @return Nonce, or '' when the session isn't signed in.
 */
export async function fetchNonce( url: string ): Promise< string > {
	try {
		const response = await window.fetch( url, { credentials: 'same-origin' } );
		const nonce = ( await response.text() ).trim();
		return response.ok && /^[a-f0-9]{10}$/.test( nonce ) ? nonce : '';
	} catch {
		return '';
	}
}

/**
 * Wires the form of one live page.
 *
 * @param form    `form.newspack-live-ask`.
 * @param options Options.
 */
export function setupAskForm( form: HTMLFormElement, options: AskOptions ): void {
	const question = form.querySelector< HTMLTextAreaElement >( 'textarea[name="question"]' );
	const name = form.querySelector< HTMLInputElement >( 'input[name="display_name"]' );
	if ( ! question || ! name ) {
		return;
	}
	const counter = form.querySelector< HTMLElement >( '.newspack-live-ask__counter' );
	const error = form.querySelector< HTMLElement >( '.newspack-live-ask__error' );
	const success = form.querySelector< HTMLElement >( '.newspack-live-ask__success' );
	let submit = form.querySelector< HTMLButtonElement >( '.newspack-live-ask__submit' );
	const signin = form.querySelector< HTMLAnchorElement >( '[data-action="signin"]' );

	const updateCounter = () => {
		if ( counter ) {
			counter.textContent = options.strings.counter
				.replace( '%1$d', String( length( question.value ) ) )
				.replace( '%2$d', String( MAX_QUESTION ) );
		}
	};

	/**
	 * Shows an error; field errors mark and focus the field that failed.
	 *
	 * @param message Message ('' clears).
	 * @param field   Invalid field, if the error is about one.
	 */
	const showError = ( message: string, field?: HTMLInputElement | HTMLTextAreaElement ) => {
		if ( error ) {
			error.textContent = message;
		}
		question.setAttribute( 'aria-invalid', field === question ? 'true' : 'false' );
		name.setAttribute( 'aria-invalid', field === name ? 'true' : 'false' );
		field?.focus();
	};

	// Restore a question typed before signing in.
	const draft = takeDraft( options.liveId );
	if ( draft ) {
		question.value = draft.question;
		if ( ! name.value ) {
			name.value = draft.name;
		}
		const panel = form.closest< HTMLElement >( '.newspack-live__panel' );
		const toggle = panel?.id ? document.querySelector< HTMLElement >( `[aria-controls="${ panel.id }"]` ) : null;
		if ( panel && toggle ) {
			panel.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );
		}
	}
	updateCounter();
	question.addEventListener( 'input', () => {
		updateCounter();
		if ( success ) {
			success.textContent = '';
		}
	} );

	// Signed in through the Reader Activation modal, without a reload: swap "Sign in" for the
	// submit button, with a nonce for the new session, and keep what the reader typed.
	if ( signin && options.nonceUrl ) {
		const nonceUrl = options.nonceUrl;
		onReaderSignedIn( async () => {
			if ( ! signin.isConnected ) {
				return true;
			}
			const nonce = await fetchNonce( nonceUrl );
			if ( ! nonce ) {
				// The session cookie may not work yet: a later reader event retries.
				return false;
			}
			options.loggedIn = true;
			options.nonce = nonce;
			const button = document.createElement( 'button' );
			button.type = 'submit';
			button.className = 'newspack-live-ask__submit';
			button.textContent = options.strings.send;
			const hadFocus = signin.ownerDocument.activeElement === signin;
			signin.replaceWith( button );
			submit = button;
			const saved = takeDraft( options.liveId );
			if ( saved ) {
				question.value = question.value || saved.question;
				name.value = name.value || saved.name;
				updateCounter();
			}
			if ( hadFocus ) {
				button.focus();
			}
			return true;
		} );
	}

	signin?.addEventListener( 'click', event => {
		saveDraft( options.liveId, question.value, name.value );
		const readerActivation = ( window as unknown as { newspackReaderActivation?: { openAuthModal?: ( args: object ) => void } } )
			.newspackReaderActivation;
		if ( signin.dataset.readerActivation === '1' ) {
			event.preventDefault();
			if ( typeof readerActivation?.openAuthModal === 'function' ) {
				readerActivation.openAuthModal( { closeOnSuccess: true } );
			} else {
				window.location.hash = 'signin_modal';
			}
		}
		// Otherwise the link goes to wp-login.php and back to #newspack-live-ask.
	} );

	form.addEventListener( 'submit', async event => {
		event.preventDefault();
		if ( ! submit || submit.disabled ) {
			return;
		}
		if ( success ) {
			success.textContent = '';
		}
		const text = question.value.trim();
		if ( ! text || length( text ) > MAX_QUESTION ) {
			showError( options.strings.invalidLength, question );
			return;
		}
		const display = name.value.trim();
		if ( ! display || length( display ) > MAX_NAME ) {
			showError( options.strings.invalidLength, name );
			return;
		}
		showError( '' );
		submit.disabled = true;
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- captured before the text changes; restored in finally.
		const label = submit.textContent ?? options.strings.send;
		submit.textContent = options.strings.sending;

		const body = new URLSearchParams( new FormData( form ) as unknown as Record< string, string > );
		const init: RequestInit = { method: 'POST', body, credentials: 'omit' };
		if ( options.loggedIn ) {
			// Signed-in readers only: registered mode needs the cookie and nonce.
			init.credentials = 'same-origin';
			init.headers = { 'X-WP-Nonce': options.nonce };
		}
		try {
			const response = await window.fetch( `${ options.restUrl }/lives/${ options.liveId }/questions`, init );
			const data = ( await response.json().catch( () => ( {} ) ) ) as { code?: string; message?: string };
			if ( ! response.ok ) {
				if ( data.code === 'rest_cookie_invalid_nonce' ) {
					// A signed-in page left open too long: a reload gets a fresh nonce.
					showError( options.strings.network );
				} else if ( data.code === 'newspack_live_invalid_length' ) {
					showError( data.message || options.strings.invalidLength, question );
				} else {
					showError( data.message || options.strings.network );
				}
				return;
			}
			question.value = '';
			updateCounter();
			if ( success ) {
				success.textContent = options.strings.success;
			}
			options.onSubmitted?.();
		} catch {
			showError( options.strings.network );
		} finally {
			submit.disabled = false;
			submit.textContent = label;
		}
	} );
}

/**
 * Replaces the form's signed timestamp when a fresher one is available (cached pages can be old).
 *
 * @param form   Form.
 * @param signed Fresh signed timestamp from /status.
 * @param maxAge Maximum age of the page's timestamp, in seconds.
 */
export function refreshTimestamp( form: HTMLFormElement | null, signed: string | undefined, maxAge = 12 * 3600 ): void {
	const input = form?.querySelector< HTMLInputElement >( 'input[name="ts"]' );
	if ( ! input || ! signed ) {
		return;
	}
	const current = Number( input.value.split( '.' )[ 0 ] );
	if ( ! current || Date.now() / 1000 - current > maxAge ) {
		input.value = signed;
	}
}
