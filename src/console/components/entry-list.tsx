/**
 * Feed tab and Drafts tab.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis -- core confirm dialog; no stable equivalent.
import { useDispatch, useSelect } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import * as api from '../api';
import { copyText } from '../copy';
import { store } from '../store';
import type { ConsoleEntry } from '../types';
import LoadMore from './load-more';

type Pending = { entry: ConsoleEntry; action: 'draft' | 'trash' } | null;

/**
 * Formats an entry time for the console list.
 *
 * @param time UTC seconds (0 for a draft without a fixed time).
 * @return Label.
 */
const timeLabel = ( time: number ): string =>
	time ? dateI18n( getSettings().formats.time, time * 1000, undefined ) : __( 'Not scheduled', 'newspack-live' );

/**
 * One entry row.
 *
 * @param props            Props.
 * @param props.entry      Entry.
 * @param props.canPublish Whether the user can publish (pin).
 * @param props.onConfirm  Asks for a confirmation.
 * @return Row.
 */
function EntryRow( { entry, canPublish, onConfirm }: { entry: ConsoleEntry; canPublish: boolean; onConfirm: ( pending: Pending ) => void } ) {
	const { receiveEntry, setEditing } = useDispatch( store );
	const [ busy, setBusy ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const published = entry.status === 'publish';

	const togglePin = async () => {
		setBusy( true );
		try {
			const response = await api.setFlags( entry.id, { pinned: ! entry.pinned } );
			receiveEntry( response.entry );
		} catch ( error ) {
			speak( ( error as { message?: string } )?.message ?? '', 'assertive' );
		} finally {
			setBusy( false );
		}
	};

	return (
		<li className={ `newspack-live-console-entry${ entry.pinned ? ' is-pinned' : '' }` } data-item-id={ entry.id } tabIndex={ -1 }>
			<div className="newspack-live-console-entry__meta">
				<time>{ timeLabel( entry.time ) }</time>
				{ entry.status === 'pending' && (
					<span className="newspack-live-console-entry__pill">{ __( 'Pending review', 'newspack-live' ) }</span>
				) }
				{ entry.status === 'draft' && <span className="newspack-live-console-entry__pill">{ __( 'Draft', 'newspack-live' ) }</span> }
				{ entry.pinned && <span className="newspack-live-console-entry__pill is-pinned">{ __( 'Pinned', 'newspack-live' ) }</span> }
				{ entry.question_ids.length > 0 && <span className="newspack-live-console-entry__pill">{ __( 'Q&A', 'newspack-live' ) }</span> }
			</div>
			<p className="newspack-live-console-entry__excerpt">{ entry.excerpt || __( '(empty)', 'newspack-live' ) }</p>
			<p className="newspack-live-console-entry__author">{ entry.author.name }</p>
			<div className="newspack-live-console-entry__actions">
				{ entry.can_edit && (
					<Button variant="secondary" size="compact" onClick={ () => setEditing( entry ) } disabled={ busy }>
						{ __( 'Edit', 'newspack-live' ) }
					</Button>
				) }
				{ published && entry.can_edit && canPublish && (
					<Button variant="tertiary" size="compact" isPressed={ entry.pinned } onClick={ togglePin } disabled={ busy }>
						{ entry.pinned ? __( 'Unpin', 'newspack-live' ) : __( 'Pin', 'newspack-live' ) }
					</Button>
				) }
				{ published && (
					<Button
						variant="tertiary"
						size="compact"
						onClick={ async () => {
							const done = await copyText( entry.permalink );
							setCopied( done );
							speak(
								done ? __( 'Link copied', 'newspack-live' ) : __( 'The link could not be copied.', 'newspack-live' ),
								done ? 'polite' : 'assertive'
							);
						} }
					>
						{ copied ? __( 'Link copied', 'newspack-live' ) : __( 'Copy link', 'newspack-live' ) }
					</Button>
				) }
				{ published && entry.can_edit && (
					<Button variant="tertiary" size="compact" onClick={ () => onConfirm( { entry, action: 'draft' } ) } disabled={ busy }>
						{ __( 'Unpublish', 'newspack-live' ) }
					</Button>
				) }
				{ entry.can_edit && (
					<Button
						variant="tertiary"
						size="compact"
						isDestructive
						onClick={ () => onConfirm( { entry, action: 'trash' } ) }
						disabled={ busy }
					>
						{ __( 'Move to trash', 'newspack-live' ) }
					</Button>
				) }
			</div>
		</li>
	);
}

/**
 * List of entries with confirmations for unpublish and trash, and "Load more".
 *
 * @param props            Props.
 * @param props.entries    Entries.
 * @param props.empty      Empty-state copy.
 * @param props.label      Accessible name of the list.
 * @param props.hasMore    Whether older entries can be loaded.
 * @param props.onLoadMore Loads the next 50 entries; resolves to the number loaded.
 * @return List.
 */
export default function EntryList( {
	entries,
	empty,
	label,
	hasMore = false,
	onLoadMore,
}: {
	entries: ConsoleEntry[];
	empty: string;
	label: string;
	hasMore?: boolean;
	onLoadMore?: () => Promise< number >;
} ) {
	const listRef = useRef< HTMLUListElement >( null );
	const user = useSelect( select => select( store ).getUser(), [] );
	const { receiveEntry, removeEntry } = useDispatch( store );
	const [ pending, setPending ] = useState< Pending >( null );

	const confirm = async () => {
		if ( ! pending ) {
			return;
		}
		const { entry, action } = pending;
		setPending( null );
		try {
			const response = await api.setEntryStatus( entry.id, action );
			if ( action === 'trash' ) {
				removeEntry( entry.id );
				speak( __( 'Entry moved to the trash.', 'newspack-live' ) );
			} else {
				receiveEntry( response.entry );
				speak( __( 'Entry unpublished.', 'newspack-live' ) );
			}
		} catch ( error ) {
			speak( ( error as { message?: string } )?.message ?? '', 'assertive' );
		}
	};

	if ( ! entries.length ) {
		return <p className="newspack-live-console__empty">{ empty }</p>;
	}

	return (
		<>
			<ul className="newspack-live-console-entries" ref={ listRef } aria-label={ label }>
				{ entries.map( entry => (
					<EntryRow key={ entry.id } entry={ entry } canPublish={ !! user?.can_publish } onConfirm={ setPending } />
				) ) }
			</ul>
			{ hasMore && onLoadMore && <LoadMore onLoad={ onLoadMore } list={ () => listRef.current } /> }
			{ pending && (
				<ConfirmDialog
					confirmButtonText={ pending.action === 'trash' ? __( 'Move to trash', 'newspack-live' ) : __( 'Unpublish', 'newspack-live' ) }
					onConfirm={ confirm }
					onCancel={ () => setPending( null ) }
				>
					{ pending.action === 'trash'
						? sprintf(
								/* translators: %s: entry excerpt. */
								__( 'Move "%s" to the trash?', 'newspack-live' ),
								pending.entry.excerpt
							)
						: __( 'Unpublish this entry? It will disappear from the live.', 'newspack-live' ) }
				</ConfirmDialog>
			) }
		</>
	);
}
