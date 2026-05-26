import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import {Page} from '@wordpress/admin-ui';
import {Button, Card, Link, Notice, Stack} from '@wordpress/ui';
import {createRoot, useEffect, useMemo, useRef, useState} from '@wordpress/element';
import {Spinner, TextareaControl, ToggleControl} from '@wordpress/components';
import '../../../css/modules/ai-settings/index.scss';

const config = window.editorioAiConfig || {
	restUrl: '',
	nonce: '',
	settings: {
		enabled: false,
		system_instruction: '',
	},
	dependency: {
		available: false,
		has_credentials: false,
		has_valid_credentials: false,
		connectors_url: '',
	},
	messages: {},
};

if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

function message( key, fallback ) {
	return config.messages && config.messages[ key ]
		? config.messages[ key ]
		: fallback;
}

function getDependencyState( dependency ) {
	if ( ! dependency?.available ) {
		return {
			intent: 'error',
			text: message(
				'dependencyMissing',
				'Plugin WordPress AI is not active. Activate it to enable this module.'
			),
			showLink: false,
		};
	}

	if ( ! dependency?.has_credentials ) {
		return {
			intent: 'warning',
			text: message(
				'dependencyNoCredentials',
				'No AI connector is configured. Set up a provider in the connectors screen.'
			),
			showLink: true,
		};
	}

	if ( ! dependency?.has_valid_credentials ) {
		return {
			intent: 'warning',
			text: message(
				'dependencyInvalidCredentials',
				'Configured AI connector credentials are not valid for text generation.'
			),
			showLink: true,
		};
	}

	return {
		intent: 'success',
		text: message(
			'dependencyReady',
			'WordPress AI is active and ready to use.'
		),
		showLink: true,
	};
}

function AISettingsApp() {
	const [ settings, setSettings ] = useState( {
		enabled: !! config.settings?.enabled,
		system_instruction: String( config.settings?.system_instruction || '' ),
	} );
	const [ draftInstruction, setDraftInstruction ] = useState(
		String( config.settings?.system_instruction || '' )
	);
	const [ isSavingToggle, setIsSavingToggle ] = useState( false );
	const [ isSavingInstruction, setIsSavingInstruction ] = useState( false );
	const [ snackbar, setSnackbar ] = useState( null );
	const snackbarTimeout = useRef( null );

	const isInstructionDirty = useMemo(
		() => draftInstruction !== String( settings.system_instruction || '' ),
		[ draftInstruction, settings.system_instruction ]
	);

	const dependencyState = useMemo(
		() => getDependencyState( config.dependency ),
		[]
	);

	useEffect( () => {
		return () => {
			if ( snackbarTimeout.current ) {
				clearTimeout( snackbarTimeout.current );
			}
		};
	}, [] );

	const showSnackbar = ( intent, text ) => {
		if ( snackbarTimeout.current ) {
			clearTimeout( snackbarTimeout.current );
		}

		setSnackbar( { intent, text } );
		snackbarTimeout.current = setTimeout( () => {
			setSnackbar( null );
			snackbarTimeout.current = null;
		}, 3500 );
	};

	const persist = async ( nextSettings, kind, successText ) => {
		if ( kind === 'toggle' ) {
			setIsSavingToggle( true );
		} else {
			setIsSavingInstruction( true );
		}

		try {
			const response = await apiFetch( {
				url: config.restUrl,
				method: 'POST',
				data: {
					enabled: !! nextSettings.enabled,
					system_instruction: String(
						nextSettings.system_instruction || ''
					),
				},
			} );

			const normalized = {
				enabled: !! response.enabled,
				system_instruction: String( response.system_instruction || '' ),
			};

			setSettings( normalized );
			setDraftInstruction( normalized.system_instruction );
			showSnackbar( 'success', successText );
		} catch ( error ) {
			showSnackbar(
				'error',
				error?.message ||
					message( 'saveError', 'Failed to save settings.' )
			);
		} finally {
			if ( kind === 'toggle' ) {
				setIsSavingToggle( false );
			} else {
				setIsSavingInstruction( false );
			}
		}
	};

	return (
		<Page
			title={ message( 'pageTitle', 'IA' ) }
			subTitle={ message(
				'pageSubtitle',
				'Configure AI features for the Editorio plugin.'
			) }
			actions={
				<Stack align="center" gap="xs" direction="row">
					{ dependencyState.showLink ? (
						<Link
							href={ config.dependency?.connectors_url || '#' }
							openInNewTab
						>
							{ message(
								'manageConnectors',
								'Gerenciar conectores'
							) }
						</Link>
					) : (
						<span>
							{ message(
								'manageConnectors',
								'Gerenciar conectores'
							) }
						</span>
					) }
				</Stack>
			}
		>
			<Stack
				className="editorio-ai-settings-page"
				direction="column"
				gap="md"
			>
				<Card.Root>
					<Card.Content>
						<Notice.Root intent={ dependencyState.intent }>
							<Notice.Description>
								{ dependencyState.text }
							</Notice.Description>
						</Notice.Root>
					</Card.Content>
				</Card.Root>

				<Card.Root>
					<Card.Content>
						<Stack direction="column" gap="sm">
							<ToggleControl
								label={ message(
									'toggleLabel',
									'Ativar IA no Editorio'
								) }
								help={ message(
									'toggleHelp',
									'Usar IA durante processamento de conteúdo.'
								) }
								checked={ !! settings.enabled }
								disabled={
									isSavingToggle || isSavingInstruction
								}
								onChange={ ( value ) => {
									const next = {
										enabled: !! value,
										system_instruction: draftInstruction,
									};
									setSettings( next );
									void persist(
										next,
										'toggle',
										value
											? message(
													'toggleEnabled',
													'AI enabled.'
											  )
											: message(
													'toggleDisabled',
													'AI disabled.'
											  )
									);
								} }
							/>
							<p className="editorio-ai-settings-page__hint">
								{ message(
									'toggleCardHint',
									'This setting controls whether AI processing is available in Editorio.'
								) }
							</p>
						</Stack>
					</Card.Content>
				</Card.Root>

				<Card.Root>
					<Card.Content>
						<Stack direction="column" gap="sm">
							<TextareaControl
								label={ message(
									'instructionLabel',
									'Instrução do sistema'
								) }
								help={ message(
									'instructionHelp',
									'Prompt base para reescrita. Provedor e credenciais vêm do WordPress AI.'
								) }
								value={ draftInstruction }
								rows={ 7 }
								disabled={ isSavingInstruction }
								onChange={ ( value ) => {
									setDraftInstruction(
										String( value || '' )
									);
								} }
							/>

							<div className="editorio-ai-settings-page__actions">
								<Button
									variant="primary"
									disabled={
										! isInstructionDirty ||
										isSavingInstruction
									}
									onClick={ () => {
										void persist(
											{
												enabled: settings.enabled,
												system_instruction:
													draftInstruction,
											},
											'instruction',
											message(
												'instructionSaved',
												'System instruction saved.'
											)
										);
									} }
								>
									{ message(
										'saveInstruction',
										'Salvar instrução'
									) }
								</Button>
								{ isSavingInstruction ? <Spinner /> : null }
							</div>
						</Stack>
					</Card.Content>
				</Card.Root>

				{ snackbar ? (
					<div className="editorio-ai-settings-page__snackbar">
						<Notice.Root intent={ snackbar.intent }>
							<Notice.Description>
								{ snackbar.text }
							</Notice.Description>
						</Notice.Root>
					</div>
				) : null }
			</Stack>
		</Page>
	);
}

domReady( () => {
	const container = document.getElementById( 'editorio-ai-settings-react' );
	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <AISettingsApp /> );
} );
