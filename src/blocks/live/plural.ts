/**
 * Plural forms of the view module's count strings (server-built table, see Renderer::plurals()).
 */

export interface Plural {
	forms: string[];
	table: number[];
}

/**
 * Form index of a count: counts above the table reuse the last hundred (plural rules repeat
 * every 100).
 *
 * @param table Form index of counts 0–199.
 * @param count Count.
 * @return Index.
 */
export function pluralIndex( table: number[], count: number ): number {
	const n = Math.max( 0, Math.floor( count ) );
	const position = n < table.length ? n : 100 + ( n % 100 );
	return table[ position ] ?? table[ table.length - 1 ] ?? 0;
}

/**
 * The string for a count.
 *
 * @param forms Plural forms (`%d` is the count).
 * @param table Form index of counts 0–199.
 * @param count Count.
 * @return Text.
 */
export function pluralize( forms: string[], table: number[], count: number ): string {
	const form = forms[ Math.min( pluralIndex( table, count ), forms.length - 1 ) ] ?? '%d';
	return form.replace( '%d', String( count ) );
}
