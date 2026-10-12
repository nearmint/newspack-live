/**
 * Loads the styles and scripts that inserted entries need, with their inline code.
 */

export interface Asset {
	handle: string;
	type: 'style' | 'script';
	/** Empty for inline-only handles. */
	src: string;
	/** `wp_localize_script()` data (scripts). */
	data?: string;
	/** `wp_set_script_translations()` code (scripts). */
	translations?: string;
	/** `wp_add_inline_script( …, 'before' )` code (scripts). */
	before?: string;
	/** `wp_add_inline_script()` / `wp_add_inline_style()` code. */
	after?: string;
}

const isHttp = ( src: string ): boolean => /^https?:\/\//.test( src );

/**
 * Whether the page already has exactly this inline code under this id (printed by WordPress, or
 * added for an earlier inserted entry: `{id}`, `{id}-2`, …).
 *
 * @param id   Base id.
 * @param code Code.
 * @return Boolean.
 */
function hasInline( id: string, code: string ): boolean {
	const wanted = normalizeInline( code );
	return Array.from( document.querySelectorAll< HTMLElement >( 'script[id], style[id]' ) ).some(
		element => ( element.id === id || element.id.startsWith( `${ id }-` ) ) && normalizeInline( element.textContent ?? '' ) === wanted
	);
}

/**
 * Inline code without what WordPress adds when printing it: the `sourceURL` comment and the CDATA
 * wrapper of non-HTML5 themes.
 *
 * @param code Code.
 * @return Normalized code.
 */
export function normalizeInline( code: string ): string {
	return code
		.replace( /\/\*\s*<!\[CDATA\[\s*\*\//g, '' )
		.replace( /\/\*\s*\]\]>\s*\*\//g, '' )
		.replace( /\/\/# sourceURL=\S*\s*$/, '' )
		.replace( /\/\*# sourceURL=[^*]*\*\/\s*$/, '' )
		.trim();
}

/**
 * Adds an inline `<script>` or `<style>` unless the page already has the same code. Code that
 * differs from what is there (per-instance initialization of another entry) is added too, under a
 * numbered id. Inline scripts run when appended.
 *
 * @param id   WordPress' id (`{handle}-js-extra`, `-js-translations`, `-js-before`, `-js-after`, `-inline-css`).
 * @param tag  `script` or `style`.
 * @param code Code.
 */
function addInline( id: string, tag: 'script' | 'style', code: string | undefined ): void {
	if ( ! code || hasInline( id, code ) ) {
		return;
	}
	let unique = id;
	for ( let n = 2; document.getElementById( unique ); n++ ) {
		unique = `${ id }-${ n }`;
	}
	const element = document.createElement( tag );
	element.id = unique;
	element.textContent = code;
	document.head.append( element );
}

/**
 * Adds a script file and waits until it has loaded (or failed), so the next one can rely on it.
 *
 * @param handle Handle.
 * @param src    URL.
 * @return Promise.
 */
function addScript( handle: string, src: string ): Promise< void > {
	return new Promise< void >( resolve => {
		const script = document.createElement( 'script' );
		script.id = `${ handle }-js`;
		script.src = src;
		script.async = false;
		script.onload = () => resolve();
		script.onerror = () => resolve();
		document.head.append( script );
	} );
}

/**
 * Adds missing styles and scripts in dependency order, each with its inline code in WordPress'
 * print order: localized data, translations, "before" code, the file, "after" code. Files the page
 * already has are not loaded again; inline code already on the page (same text) isn't run again.
 *
 * @param assets Assets from a REST response.
 */
export async function loadAssets( assets: Asset[] = [] ): Promise< void > {
	for ( const asset of assets ) {
		const src = asset.src && isHttp( asset.src ) ? asset.src : '';
		if ( asset.src && ! src ) {
			// Unexpected scheme: never load it, nor its inline code.
			continue;
		}
		if ( asset.type === 'style' ) {
			if ( src && ! document.getElementById( `${ asset.handle }-css` ) ) {
				const link = document.createElement( 'link' );
				link.id = `${ asset.handle }-css`;
				link.rel = 'stylesheet';
				link.href = src;
				document.head.append( link );
			}
			addInline( `${ asset.handle }-inline-css`, 'style', asset.after );
			continue;
		}
		addInline( `${ asset.handle }-js-extra`, 'script', asset.data );
		addInline( `${ asset.handle }-js-translations`, 'script', asset.translations );
		addInline( `${ asset.handle }-js-before`, 'script', asset.before );
		if ( src && ! document.getElementById( `${ asset.handle }-js` ) ) {
			await addScript( asset.handle, src );
		}
		addInline( `${ asset.handle }-js-after`, 'script', asset.after );
	}
}
