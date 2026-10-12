/**
 * Sticky status bar: title, state, state actions, questions toggle, links and banners.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Notice, ToggleControl, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis -- core confirm dialog; no stable equivalent.
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import * as api from '../api';
import { store } from '../store';
import Presence from './presence';

const STATE_LABELS: Record< string, string > = {
	draft: __( 'Draft', 'newspack-live' ),
	upcoming: __( 'Upcoming', 'newspack-live' ),
	live: __( 'Live', 'newspack-live' ),
	ended: __( 'Ended', 'newspack-live' ),
};

type StateAction = 'go_live' | 'end' | 'reopen' | 'keep_live';

/**
 * Status bar.
 *
 * @return Element.
 */
export default function StatusBar() {
	const { live, user, autoEnd } = useSelect( select => {
		const console = select( store );
		return { live: console.getLive(), user: console.getUser(), autoEnd: console.getAutoEnd() };
	}, [] );
	const { receiveLive, load } = useDispatch( store );
	const [ busy, setBusy ] = useState( false );
	const [ confirmEnd, setConfirmEnd ] = useState( false );
	const [ error, setError ] = useState( '' );

	if ( ! live || ! user ) {
		return null;
	}

	const run = async ( action: StateAction ) => {
		setBusy( true );
		setError( '' );
		try {
			const response = await api.setState( live.id, action );
			receiveLive( response.live );
			await load( live.id );
			speak( STATE_LABELS[ response.live.state ] ?? response.live.state );
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message ?? __( 'Something went wrong. Please try again.', 'newspack-live' );
			setError( message );
			speak( message, 'assertive' );
		} finally {
			setBusy( false );
		}
	};

	const toggleQuestions = async ( open: boolean ) => {
		setBusy( true );
		try {
			const response = await api.setQuestionsOpen( live.id, open );
			receiveLive( response.live );
			speak( open ? __( 'Questions open', 'newspack-live' ) : __( 'Questions closed', 'newspack-live' ) );
		} catch ( failure ) {
			setError( ( failure as { message?: string } )?.message ?? '' );
		} finally {
			setBusy( false );
		}
	};

	const isDraft = live.status !== 'publish';
	const questionsOpen = !! live.settings.questions_open;

	return (
		<div className="newspack-live-console__status">
			<div className="newspack-live-console__status-main">
				<h2 className="newspack-live-console__title">
					<a href={ live.url } target="_blank" rel="noreferrer">
						{ live.title || __( '(no title)', 'newspack-live' ) }
					</a>
				</h2>
				<span className={ `newspack-live-console__badge newspack-live-console__badge--${ live.state }` } data-testid="newspack-live-state">
					{ STATE_LABELS[ live.state ] ?? live.state }
				</span>
				{ live.state === 'upcoming' && live.upcoming_label && (
					<span className="newspack-live-console__upcoming">{ live.upcoming_label }</span>
				) }
			</div>
			<div className="newspack-live-console__actions">
				{ user.can_manage && ( isDraft || live.state === 'upcoming' ) && (
					<Button
						variant="primary"
						onClick={ () => run( 'go_live' ) }
						disabled={ busy || isDraft }
						aria-describedby={ isDraft ? 'newspack-live-draft-hint' : undefined }
						__next40pxDefaultSize
					>
						{ __( 'Go live', 'newspack-live' ) }
					</Button>
				) }
				{ user.can_manage && live.state === 'live' && (
					<>
						<Button variant="secondary" onClick={ () => run( 'keep_live' ) } disabled={ busy } __next40pxDefaultSize>
							{ __( 'Keep live', 'newspack-live' ) }
						</Button>
						<Button variant="secondary" isDestructive onClick={ () => setConfirmEnd( true ) } disabled={ busy } __next40pxDefaultSize>
							{ __( 'End live', 'newspack-live' ) }
						</Button>
					</>
				) }
				{ user.can_manage && live.state === 'ended' && (
					<Button variant="primary" onClick={ () => run( 'reopen' ) } disabled={ busy } __next40pxDefaultSize>
						{ __( 'Reopen', 'newspack-live' ) }
					</Button>
				) }
				{ user.can_moderate && live.settings.questions_mode !== 'off' && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ questionsOpen ? __( 'Questions open', 'newspack-live' ) : __( 'Questions closed', 'newspack-live' ) }
						checked={ questionsOpen }
						disabled={ busy }
						onChange={ toggleQuestions }
					/>
				) }
				<Button variant="link" href={ live.url } target="_blank">
					{ __( 'View live', 'newspack-live' ) }
				</Button>
				{ user.can_manage && (
					<Button variant="link" href={ live.edit_url }>
						{ __( 'Edit settings', 'newspack-live' ) }
					</Button>
				) }
			</div>
			<Presence />
			{ isDraft && (
				<p id="newspack-live-draft-hint" className="newspack-live-console__hint">
					{ __( 'Publish the post to go live.', 'newspack-live' ) }
				</p>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ autoEnd?.warning && autoEnd.message && live.state === 'live' && (
				<Notice
					status="warning"
					isDismissible={ false }
					spokenMessage=""
					actions={
						user.can_manage
							? [ { label: __( 'Keep live', 'newspack-live' ), onClick: () => run( 'keep_live' ), variant: 'primary' } ]
							: []
					}
				>
					{ autoEnd.message }
				</Notice>
			) }
			{ live.state === 'ended' && (
				<Notice status="info" isDismissible={ false } spokenMessage="">
					{ __( 'This live has ended. Readers can still read it. Reopen it to post again.', 'newspack-live' ) }
				</Notice>
			) }
			{ confirmEnd && (
				<ConfirmDialog
					confirmButtonText={ __( 'End live', 'newspack-live' ) }
					onConfirm={ () => {
						setConfirmEnd( false );
						run( 'end' );
					} }
					onCancel={ () => setConfirmEnd( false ) }
				>
					{ __( 'End this live? Readers will see it as ended and questions will close.', 'newspack-live' ) }
				</ConfirmDialog>
			) }
		</div>
	);
}
