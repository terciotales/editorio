import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import {Page} from '@wordpress/admin-ui';
import {createRoot, useEffect, useMemo, useRef, useState} from '@wordpress/element';
import {Button, Card, Notice, Stack} from '@wordpress/ui';
import {Spinner, ToggleControl} from '@wordpress/components';
import '../../../css/modules/sources/index.scss';

const config = window.editorioSourcesConfig || {
	restNamespace: '/editorio/v1',
	nonce: '',
	messages: {},
};

if ( config.nonce ) {
	apiFetch.use( ( options, next ) => {
		const headers = {
			...( options.headers || {} ),
			'X-WP-Nonce': config.nonce,
		};

		return next( { ...options, headers } );
	} );
}

const endpoint = ( path = '' ) => `${ config.restNamespace }/sources${ path }`;

function message( key, fallback ) {
	return config.messages && config.messages[ key ]
		? config.messages[ key ]
		: fallback;
}

function initialForm() {
	return {
		name: '',
		feed_url: '',
		news_limit: 10,
		is_active: true,
	};
}

function SourcesApp() {
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ editingId, setEditingId ] = useState( null );
	const [ pendingDelete, setPendingDelete ] = useState( null );
	const [ deleteAnchor, setDeleteAnchor ] = useState( null );
	const [ isFormOpen, setIsFormOpen ] = useState( false );
	const [ form, setForm ] = useState( initialForm() );
	const [ toast, setToast ] = useState( null );
	const toastTimeout = useRef( null );

	const totalCount = items.length;
	const activeCount = useMemo(
		() => items.filter( ( item ) => item.is_active ).length,
		[ items ]
	);
	const isEditing = editingId !== null;
	let submitLabel = message( 'create', 'Criar' );
	if ( submitting ) {
		submitLabel = message( 'saving', 'Salvando...' );
	} else if ( isEditing ) {
		submitLabel = message( 'update', 'Atualizar' );
	}
	const deletePromptText = pendingDelete?.name
		? message( 'deletePrompt', `Excluir "${ pendingDelete.name }"?` )
		: message( 'deletePromptFallback', 'Excluir esta fonte?' );
	const deleteAnchorRect = deleteAnchor
		? deleteAnchor.getBoundingClientRect()
		: null;
	const deletePopupStyle = deleteAnchorRect
		? {
				position: 'fixed',
				top: `${ deleteAnchorRect.bottom + 8 }px`,
				left: `${ deleteAnchorRect.right }px`,
				transform: 'translateX(-100%)',
		  }
		: null;

	useEffect( () => {
		return () => {
			if ( toastTimeout.current ) {
				clearTimeout( toastTimeout.current );
			}
		};
	}, [] );

	const showToast = ( intent, text ) => {
		if ( toastTimeout.current ) {
			clearTimeout( toastTimeout.current );
		}

		setToast( { intent, text } );
		toastTimeout.current = setTimeout( () => {
			setToast( null );
			toastTimeout.current = null;
		}, 3500 );
	};

	const load = async () => {
		setLoading( true );
		setError( '' );

		try {
			const response = await apiFetch( { path: endpoint() } );
			setItems( Array.isArray( response ) ? response : [] );
		} catch ( err ) {
			setError(
				err?.message ||
					message(
						'loadError',
						'Não foi possível carregar as fontes.'
					)
			);
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		void load();
	}, [] );

	const openCreateModal = () => {
		setPendingDelete( null );
		setEditingId( null );
		setForm( initialForm() );
		setIsFormOpen( true );
	};

	const openEditModal = ( item ) => {
		setPendingDelete( null );
		setEditingId( item.id );
		setForm( {
			name: item.name || '',
			feed_url: item.feed_url || '',
			news_limit: Number.isFinite( Number( item.news_limit ) )
				? Number( item.news_limit )
				: 10,
			is_active: !! item.is_active,
		} );
		setIsFormOpen( true );
	};

	const closeFormModal = () => {
		setIsFormOpen( false );
		setEditingId( null );
		setForm( initialForm() );
	};

	const resetForm = () => {
		setEditingId( null );
		setForm( initialForm() );
	};

	const upsertItem = ( id, updater ) => {
		setItems( ( current ) =>
			current.map( ( item ) =>
				item.id === id ? updater( item ) : item
			)
		);
	};

	const saveSource = async ( payload, successMessage ) => {
		setSubmitting( true );
		setError( '' );

		try {
			const response = await apiFetch( payload );
			showToast( 'success', successMessage );
			return response;
		} catch ( err ) {
			showToast(
				'error',
				err?.message ||
					message( 'saveError', 'Não foi possível salvar a fonte.' )
			);
			throw err;
		} finally {
			setSubmitting( false );
		}
	};

	const onSubmit = async ( event ) => {
		event.preventDefault();

		try {
			if ( isEditing ) {
				await saveSource(
					{
						path: endpoint( `/${ editingId }` ),
						method: 'PUT',
						data: form,
					},
					message( 'updatedSuccess', 'Fonte atualizada com sucesso.' )
				);
			} else {
				await saveSource(
					{
						path: endpoint(),
						method: 'POST',
						data: form,
					},
					message( 'createdSuccess', 'Fonte criada com sucesso.' )
				);
			}

			closeFormModal();
			await load();
		} catch {
			// Toast already handled in saveSource.
		}
	};

	const toggleActive = async ( item, nextValue ) => {
		setError( '' );
		upsertItem( item.id, ( current ) => ( {
			...current,
			is_active: nextValue,
		} ) );

		try {
			await apiFetch( {
				path: endpoint( `/${ item.id }` ),
				method: 'PUT',
				data: {
					name: item.name || '',
					feed_url: item.feed_url || '',
					news_limit:
						Number.isFinite( Number( item.news_limit ) )
							? Number( item.news_limit )
							: 10,
					is_active: nextValue,
				},
			} );

			showToast(
				'success',
				nextValue
					? message(
							'activatedSuccess',
							'Fonte ativada com sucesso.'
					  )
					: message(
							'deactivatedSuccess',
							'Fonte desativada com sucesso.'
					  )
			);
		} catch ( err ) {
			upsertItem( item.id, ( current ) => ( {
				...current,
				is_active: ! nextValue,
			} ) );
			showToast(
				'error',
				err?.message ||
					message(
						'toggleError',
						'Não foi possível alterar o status da fonte.'
					)
			);
		}
	};

	const requestDelete = ( item, event ) => {
		setPendingDelete( item );
		setDeleteAnchor( event.currentTarget );
	};

	const cancelDelete = () => {
		setPendingDelete( null );
		setDeleteAnchor( null );
	};

	const confirmDelete = async () => {
		if ( ! pendingDelete ) {
			return;
		}

		const id = pendingDelete.id;
		setError( '' );

		try {
			await apiFetch( {
				path: endpoint( `/${ id }` ),
				method: 'DELETE',
			} );

			if ( editingId === id ) {
				resetForm();
				setIsFormOpen( false );
			}

			showToast(
				'success',
				message( 'deletedSuccess', 'Fonte excluída com sucesso.' )
			);
			cancelDelete();
			await load();
		} catch ( err ) {
			cancelDelete();
			showToast(
				'error',
				err?.message ||
					message(
						'deleteError',
						'Não foi possível excluir a fonte.'
					)
			);
		}
	};

	return (
		<Page
			title={ message( 'pageTitle', 'Fontes' ) }
			subTitle={ message(
				'pageSubtitle',
				'Gerencie feeds e conteúdo de origem em um painel mais organizado.'
			) }
			actions={
				<div className="editorio-sources-page__page-actions">
					<Button variant="primary" onClick={ openCreateModal }>
						{ message( 'addSource', 'Adicionar fonte' ) }
					</Button>
					<Stack align="center" gap="xl" direction="row">
						<Button
							variant="secondary"
							disabled={ loading || submitting }
							onClick={ () => {
								void load();
							} }
						>
							{ message( 'refresh', 'Atualizar' ) }
						</Button>
					</Stack>
				</div>
			}
		>
			<Stack
				className="editorio-sources-page"
				direction="column"
				gap="md"
			>
				<Card.Root>
					<Card.Content>
						<Stack direction="column" gap="sm">
							<Notice.Root intent="info">
								<Notice.Description>
									{ message(
										'overview',
										'Use o painel para cadastrar, editar e ativar fontes de conteúdo.'
									) }
								</Notice.Description>
							</Notice.Root>

							<div className="editorio-sources-page__stats">
								<div>
									<strong>{ totalCount }</strong>
									<span>
										{ message(
											'totalLabel',
											'Total de fontes'
										) }
									</span>
								</div>
								<div>
									<strong>{ activeCount }</strong>
									<span>
										{ message(
											'activeLabel',
											'Fontes ativas'
										) }
									</span>
								</div>
							</div>

						</Stack>
					</Card.Content>
				</Card.Root>

				<Card.Root>
					<Card.Content>
						<Stack direction="column" gap="sm">
							<div className="editorio-sources-page__list-header">
								<div>
									<h2>
										{ message(
											'listTitle',
											'Fontes cadastradas'
										) }
									</h2>
									<p>
										{ message(
											'listHint',
											'Edite, desative ou remova itens sem sair da página.'
										) }
									</p>
								</div>
							</div>

							{ error ? (
								<Notice.Root intent="error">
									<Notice.Description>
										{ error }
									</Notice.Description>
								</Notice.Root>
							) : null }

							{ loading ? (
								<div className="editorio-sources-page__loading">
									<Spinner />
								</div>
							) : null }

							{ ! loading && items.length === 0 ? (
								<div className="editorio-sources-page__empty">
									<p>
										{ message(
											'emptyState',
											'Nenhuma fonte cadastrada ainda. Clique em “Adicionar fonte” para criar a primeira.'
										) }
									</p>
								</div>
							) : null }

							{ ! loading && items.length > 0 ? (
								<div className="editorio-sources-page__table-wrap">
									<table className="widefat striped editorio-sources-page__table">
										<thead>
											<tr>
												<th>
													{ message(
														'nameColumn',
														'Nome'
													) }
												</th>
										<th>
											{ message(
												'feedColumn',
												'Feed URL'
											) }
										</th>
										<th>
											{ message(
												'limitColumn',
												'Limite'
											) }
										</th>
										<th>
											{ message(
												'statusColumn',
												'Status'
											) }
												</th>
												<th>
													{ message(
														'actionsColumn',
														'Ações'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ items.map( ( item ) => (
												<tr key={ item.id }>
													<td>{ item.name }</td>
													<td className="editorio-sources-page__feed-cell">
														{ item.feed_url }
													</td>
													<td className="editorio-sources-page__limit-cell">
														{ item.news_limit || 10 }
													</td>
											<td className="editorio-sources-page__toggle-cell">
														<ToggleControl
															label={ message(
																'statusToggleLabel',
																'Ativar fonte'
															) }
															checked={
																!! item.is_active
															}
															disabled={ submitting }
															onChange={ (
																value
															) => {
																void toggleActive(
																	item,
																	!! value
																);
															} }
														/>
													</td>
													<td className="editorio-sources-page__table-actions">
														<Button
															variant="secondary"
															size="small"
															onClick={ () =>
																openEditModal(
																	item
																)
															}
														>
															{ message(
																'edit',
																'Editar'
															) }
														</Button>
														<Button
															variant="secondary"
															size="small"
															onClick={ (
																event
															) =>
																requestDelete(
																	item,
																	event
																)
															}
														>
															{ message(
																'delete',
																'Excluir'
															) }
														</Button>
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							) : null }
						</Stack>
					</Card.Content>
				</Card.Root>

				{ toast ? (
					<div className="editorio-sources-page__toast">
						<Notice.Root intent={ toast.intent }>
							<Notice.Description>
								{ toast.text }
							</Notice.Description>
						</Notice.Root>
					</div>
				) : null }
			</Stack>

			{ pendingDelete && deletePopupStyle ? (
				<div className="editorio-sources-page__delete-popup-root">
					<button
						type="button"
						className="editorio-sources-page__overlay"
						aria-label={ message( 'close', 'Fechar' ) }
						onClick={ cancelDelete }
					/>
					<div
						className="editorio-sources-page__delete-popover"
						style={ deletePopupStyle }
					>
						<Notice.Root intent="warning">
							<Notice.Description>
								{ deletePromptText }
							</Notice.Description>
						</Notice.Root>
						<div className="editorio-sources-page__delete-actions">
							<Button variant="primary" onClick={ confirmDelete }>
								{ message( 'delete', 'Excluir' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ cancelDelete }
							>
								{ message( 'cancel', 'Cancelar' ) }
							</Button>
						</div>
					</div>
				</div>
			) : null }

			{ isFormOpen ? (
				<div className="editorio-sources-page__overlay-root">
					<button
						type="button"
						className="editorio-sources-page__overlay"
						aria-label={ message( 'close', 'Fechar' ) }
						onClick={ closeFormModal }
					/>
					<div
						className="editorio-sources-page__dialog"
						role="dialog"
						aria-modal="true"
						aria-labelledby="editorio-sources-modal-title"
					>
						<Card.Root>
							<Card.Content>
								<Stack direction="column" gap="xl">
									<div className="editorio-sources-page__dialog-header">
										<div>
											<h2 id="editorio-sources-modal-title">
												{ isEditing
													? message(
															'editTitle',
															'Editar fonte'
													  )
													: message(
															'createTitle',
															'Nova fonte'
													  ) }
											</h2>
											<p className="editorio-sources-page__modal-hint">
												{ message(
													'formHint',
													'Preencha os dados do feed para manter a lista fácil de revisar.'
												) }
											</p>
										</div>
										<Button
											variant="secondary"
											onClick={ closeFormModal }
										>
											{ message( 'close', 'Fechar' ) }
										</Button>
									</div>

									<form onSubmit={ onSubmit }>
										<Stack direction="column" gap="xl">
											<label
												className="editorio-sources-page__field"
												htmlFor="editorio-source-name"
											>
												<span>
													{ message(
														'nameLabel',
														'Nome'
													) }
												</span>
												<input
													id="editorio-source-name"
													type="text"
													value={ form.name }
													onChange={ ( event ) =>
														setForm( {
															...form,
															name: event.target
																.value,
														} )
													}
													required
													disabled={ submitting }
												/>
											</label>

												<label
													className="editorio-sources-page__field"
													htmlFor="editorio-source-feed-url"
												>
												<span>
													{ message(
														'feedLabel',
														'Feed URL'
													) }
												</span>
												<input
													id="editorio-source-feed-url"
													type="url"
													value={ form.feed_url }
													onChange={ ( event ) =>
														setForm( {
															...form,
															feed_url:
																event.target
																	.value,
														} )
													}
													required
													disabled={ submitting }
												/>
												</label>

											<label
												className="editorio-sources-page__field"
												htmlFor="editorio-source-news-limit"
											>
												<span>
													{ message(
														'limitLabel',
														'Limite de notícias'
													) }
												</span>
												<input
													id="editorio-source-news-limit"
													type="number"
													min="1"
													max="100"
													value={ form.news_limit }
													onChange={ ( event ) =>
														setForm( {
															...form,
															news_limit: Number(
																event.target.value
															) || 10,
														} )
													}
													required
													disabled={ submitting }
												/>
											</label>

											<ToggleControl
												label={ message(
													'activeLabelToggle',
													'Fonte ativa'
												) }
												help={ message(
													'activeHelp',
													'Fontes ativas podem ser usadas no processamento.'
												) }
												checked={ form.is_active }
												onChange={ ( value ) =>
													setForm( {
														...form,
														is_active: !! value,
													} )
												}
												disabled={ submitting }
											/>

											<div className="editorio-sources-page__actions">
												<Button
													variant="primary"
													type="submit"
													disabled={ submitting }
												>
													{ submitLabel }
												</Button>
												<Button
													variant="secondary"
													type="button"
													disabled={ submitting }
													onClick={ closeFormModal }
												>
													{ message(
														'cancel',
														'Cancelar'
													) }
												</Button>
											</div>
										</Stack>
									</form>
								</Stack>
							</Card.Content>
						</Card.Root>
					</div>
				</div>
			) : null }
		</Page>
	);
}

domReady( () => {
	const container = document.getElementById( 'editorio-sources-app' );
	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <SourcesApp /> );
} );
