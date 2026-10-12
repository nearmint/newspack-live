/**
 * Entry labels in the composer: options, custom label checks and creation.
 * Pure helpers, unit-tested; the server checks everything again.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { LabelConfig } from './config';
import type { CustomLabel } from './types';

/** Select value of the "+ Create custom label…" option. */
export const CREATE = '__create__';

/** The automatic label of answers to reader questions: never selectable. */
export const YOUR_QUESTIONS = 'your-questions';

export interface LabelOption {
	value: string;
	label: string;
}

/**
 * Whether an entry answers reader questions (its label is then locked to "Your questions").
 *
 * @param questionIds Questions quoted by the entry.
 * @return Whether it is an answer.
 */
export const isAnswer = ( questionIds: number[] ): boolean => questionIds.length > 0;

/**
 * Options of the label select: none, the presets, the live's custom labels, then "+ Create custom
 * label…" for users who can create one. "Your questions" is never offered. A custom value the
 * live doesn't list (deleted, or created by a teammate since the last refresh) shows as "Unknown
 * label", so the select never hides what the entry carries; the server keeps it unchanged.
 *
 * @param config    Presets.
 * @param custom    The live's custom labels.
 * @param canCreate Whether the user can create custom labels.
 * @param current   The entry's current value.
 * @return Options.
 */
export function labelOptions( config: LabelConfig, custom: CustomLabel[], canCreate: boolean, current = '' ): LabelOption[] {
	const options: LabelOption[] = [
		{ value: '', label: __( 'None', 'newspack-live' ) },
		...config.presets.filter( preset => preset.value !== YOUR_QUESTIONS ).map( preset => ( { value: preset.value, label: preset.name } ) ),
		...custom.map( label => ( { value: label.value, label: label.text } ) ),
	];
	if ( current.startsWith( 'custom:' ) && ! custom.some( label => label.value === current ) ) {
		options.push( { value: current, label: __( 'Unknown label', 'newspack-live' ) } );
	}
	if ( canCreate ) {
		options.push( { value: CREATE, label: __( '+ Create custom label…', 'newspack-live' ) } );
	}
	return options;
}

/**
 * The value the select shows: unknown values (a deleted custom label) show as none.
 *
 * @param value   Stored value.
 * @param options Options.
 * @return Value.
 */
export const selectValue = ( value: string, options: LabelOption[] ): string =>
	options.some( option => option.value === value && option.value !== CREATE ) ? value : '';

/**
 * Cleans a custom label text like the server: trimmed, single spaces.
 *
 * @param text Raw text.
 * @return Text.
 */
export const cleanLabelText = ( text: string ): string => text.replace( /\s+/g, ' ' ).trim();

/**
 * Checks a new custom label text.
 *
 * @param text   Raw text.
 * @param config Presets (names taken, maximum length).
 * @param custom The live's custom labels.
 * @return Error message, '' when valid.
 */
export function validateCustomLabel( text: string, config: LabelConfig, custom: CustomLabel[] ): string {
	const clean = cleanLabelText( text );
	if ( ! clean ) {
		return __( 'Enter a label.', 'newspack-live' );
	}
	// Characters, not UTF-16 units, like the server's mb_strlen().
	if ( Array.from( clean ).length > config.max_length ) {
		/* translators: %d: maximum number of characters. */
		return sprintf( __( 'Use %d characters or fewer.', 'newspack-live' ), config.max_length );
	}
	const lower = clean.toLocaleLowerCase();
	if ( config.taken.includes( lower ) || custom.some( label => label.text.toLocaleLowerCase() === lower ) ) {
		return __( 'This live already has this label.', 'newspack-live' );
	}
	return '';
}

/**
 * Whether the selected label can be deleted: a custom label no entry of the live uses.
 *
 * @param value  Selected value.
 * @param custom The live's custom labels.
 * @return Whether it can be deleted.
 */
export const canDeleteLabel = ( value: string, custom: CustomLabel[] ): boolean => custom.some( label => label.value === value && ! label.used );

export type CreateResult = { ok: true; value: string; labels: CustomLabel[] } | { ok: false; error: string };

/**
 * Creates a custom label: checks it locally, then on the server.
 *
 * @param text   Raw text.
 * @param config Presets.
 * @param custom The live's custom labels.
 * @param create Server call.
 * @return The new label's value and the live's labels, or an error message.
 */
export async function createCustomLabel(
	text: string,
	config: LabelConfig,
	custom: CustomLabel[],
	create: ( clean: string ) => Promise< { label: CustomLabel; labels: CustomLabel[] } >
): Promise< CreateResult > {
	const error = validateCustomLabel( text, config, custom );
	if ( error ) {
		return { ok: false, error };
	}
	try {
		const response = await create( cleanLabelText( text ) );
		return { ok: true, value: response.label.value, labels: response.labels };
	} catch ( failure ) {
		return { ok: false, error: ( failure as { message?: string } )?.message ?? __( 'Something went wrong. Please try again.', 'newspack-live' ) };
	}
}
