/**
 * Console configuration printed by PHP (`window.newspackLiveConsole`).
 */

/**
 * Entry label presets, printed by `Labels::console_config()`.
 */
export interface LabelConfig {
	/** Selectable presets, in menu order. */
	presets: { value: string; name: string }[];
	/** Lowercased names a custom label can't take (presets in English and in the site language). */
	taken: string[];
	/** Display name of the automatic label of answers. */
	your_questions: string;
	max_length: number;
}

export interface ConsoleConfig {
	liveId: number;
	userId: number;
	allowedBlocks: string[];
	labels: LabelConfig;
	editorSettings: Record< string, unknown >;
	pollInterval: number;
	fullEditorBase: string;
	presenceLeave: string;
}

declare global {
	interface Window {
		newspackLiveConsole?: ConsoleConfig;
	}
}

export const config: ConsoleConfig = window.newspackLiveConsole ?? {
	liveId: 0,
	userId: 0,
	allowedBlocks: [],
	labels: { presets: [], taken: [], your_questions: 'Your questions', max_length: 24 },
	editorSettings: {},
	pollInterval: 15,
	fullEditorBase: '',
	presenceLeave: '',
};
