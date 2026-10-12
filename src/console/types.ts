/**
 * Console data shapes (mirror REST_Private payloads).
 */

export type EntryStatus = 'publish' | 'draft' | 'pending';

export interface ConsoleEntry {
	id: number;
	rev: number;
	status: EntryStatus;
	title: string;
	content: string;
	excerpt: string;
	time: number;
	modified: number;
	author: { id: number; name: string };
	pinned: boolean;
	question_ids: number[];
	/** A preset slug, `custom:<id>`, `your-questions` on answers, or ''. */
	label: string;
	permalink: string;
	edit_link: string;
	can_edit: boolean;
}

export interface LiveSettings {
	questions_mode: 'off' | 'anyone' | 'registered';
	questions_open: boolean;
	show_entry_author: boolean;
	[ key: string ]: unknown;
}

export interface LivePayload {
	id: number;
	title: string;
	status: string;
	state: 'draft' | 'upcoming' | 'live' | 'ended';
	url: string;
	edit_url: string;
	settings: LiveSettings;
	start: number;
	end: number;
	last_update: number;
	upcoming_label: string;
	/** The live's custom entry labels. */
	labels: CustomLabel[];
}

/**
 * A custom entry label of the live.
 */
export interface CustomLabel {
	id: string;
	/** `custom:<id>`. */
	value: string;
	text: string;
	/** Whether an entry of the live uses it (then it can't be deleted). */
	used: boolean;
}

export interface TeamMember {
	id: number;
	name: string;
	avatar: string;
	host: boolean;
}

export interface ConsoleUser {
	id: number;
	name: string;
	can_publish: boolean;
	can_manage: boolean;
	can_moderate: boolean;
	can_edit_others: boolean;
	can_upload: boolean;
}

export type QuestionStatus = 'nl_new' | 'nl_shortlisted' | 'nl_answered' | 'nl_rejected' | 'nl_spam';

export interface Question {
	id: number;
	text: string;
	name: string;
	registered: boolean;
	time: number;
	status: QuestionStatus;
	answer_entry: number;
	/** Similar questions grouped under this one. */
	similar?: Question[];
}

export interface AutoEnd {
	warning: boolean;
	at: number;
	hours: number;
	message?: string;
}

export interface ConsoleResponse {
	rev: number;
	reset: boolean;
	live: LivePayload;
	entries: ConsoleEntry[];
	removed: number[];
	drafts: ConsoleEntry[];
	question_count: Record< QuestionStatus, number >;
	auto_end: AutoEnd;
	team?: TeamMember[];
	has_more?: boolean;
	user?: ConsoleUser;
	drafts_more?: boolean;
	drafts_total?: number;
}

/**
 * A page of a console list.
 */
export interface ConsolePage< T > {
	items: T[];
	has_more: boolean;
}

/**
 * A team member currently on the live (who's online).
 */
export interface PresenceUser {
	id: number;
	name: string;
	avatar: string;
	self: boolean;
}

export interface EntryInput {
	title?: string;
	content?: string;
	time?: number | null;
	pinned?: boolean;
	author?: number;
	status?: EntryStatus;
	question_ids?: number[];
	label?: string;
	compose_ms?: number;
}
