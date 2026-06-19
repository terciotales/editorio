import {useEffect, useState} from '@wordpress/element';
import {Button, Notice} from '@wordpress/ui';
import {FormTokenField, Modal, Spinner} from '@wordpress/components';
import {Page} from '@wordpress/admin-ui';
import apiFetch from '@wordpress/api-fetch';
import {aiEndpoint, publisherEndpoint} from '../api/endpoints';
import {buildReviewSourceContent} from '../api/reviewSources';
import {
	createEmptyReviewDraft,
	createGeneratedFieldState,
	getCuratedSourceLabel,
	getCuratedStoryReason,
	getCuratedStorySources,
	getCuratedStoryTitle,
	hasSavedReviewDraft,
	isReviewPending,
	normalizeList,
} from '../utils/review';

const ReviewScreen = ({ sessionId, items, onComplete, onBack }) => {
  const [reviewItems, setReviewItems] = useState(items);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [drafts, setDrafts] = useState({});
  const [categories, setCategories] = useState([]);
  const [editingFields, setEditingFields] = useState({});
  const [generatedFields, setGeneratedFields] = useState({});
  const [fieldLoading, setFieldLoading] = useState({});
  const [loading, setLoading] = useState(false);
  const [reviewError, setReviewError] = useState('');
  const [isFinalizeModalOpen, setIsFinalizeModalOpen] = useState(false);

  useEffect(() => {
    const nextItems = Array.isArray(items) ? items : [];
    const nextDrafts = {};
    const nextGeneratedFields = {};

    nextItems.forEach((nextItem) => {
      const nextItemId = Number(nextItem.id);
      if (nextItemId > 0 && hasSavedReviewDraft(nextItem)) {
        nextDrafts[nextItemId] = createEmptyReviewDraft(nextItem);
        nextGeneratedFields[nextItemId] = createGeneratedFieldState(nextItem);
      }
    });

    setReviewItems(nextItems);
    setDrafts((prev) => ({ ...nextDrafts, ...prev }));
    setGeneratedFields((prev) => ({ ...nextGeneratedFields, ...prev }));
    setCurrentIndex(Math.max(0, nextItems.findIndex(isReviewPending)));
  }, [items]);

  useEffect(() => {
    let mounted = true;

    const loadCategories = async () => {
      try {
        const response = await apiFetch({
          path: '/wp/v2/categories?per_page=100&hide_empty=false',
        });

        if (mounted) {
          setCategories(Array.isArray(response) ? response.map((category) => ({
            id: category.id,
            name: category.name,
          })) : []);
        }
      } catch (error) {
        if (mounted) {
          setCategories([]);
        }
      }
    };

    void loadCategories();

    return () => {
      mounted = false;
    };
  }, []);

  const item = reviewItems[currentIndex] || null;
  const itemId = item ? Number(item.id) : 0;
  const draft = item ? (drafts[itemId] || createEmptyReviewDraft(item)) : null;
  const sources = item ? getCuratedStorySources(item) : [];
  const progress = item ? `Revisando notícia ${currentIndex + 1}/${reviewItems.length}` : '';
  const isGenerating = Object.values(fieldLoading).some(Boolean);
  const fieldOrder = ['title', 'summary', 'categories', 'tags', 'content'];
  const categorySuggestions = categories
    .map((category) => String(category.name || '').trim())
    .filter(Boolean);
  const isFieldGenerating = (field) => Boolean(fieldLoading.all || fieldLoading[field]);
  const hasFieldGenerated = (field) => Boolean(generatedFields[itemId]?.[field]);

  const hasFieldContent = (field) => {
    if (!draft) {
      return false;
    }

    if (field === 'categories') {
      return normalizeList(draft.categories_suggested).length > 0;
    }

    if (field === 'tags') {
      return normalizeList(draft.tags_suggested).length > 0;
    }

    return Boolean(String(draft[field] || '').trim());
  };

  const markFieldsGenerated = (field) => {
    setGeneratedFields((prev) => {
      const nextFieldState = field === 'all'
        ? fieldOrder.reduce((fields, fieldName) => ({ ...fields, [fieldName]: true }), {})
        : { [field]: true };

      return {
        ...prev,
        [itemId]: {
          ...(prev[itemId] || {}),
          ...nextFieldState,
        },
      };
    });
  };

  const updateDraft = (nextDraft) => {
    if (!itemId) {
      return;
    }

    setDrafts((prev) => ({
      ...prev,
      [itemId]: {
        ...createEmptyReviewDraft(item),
        ...(prev[itemId] || {}),
        ...nextDraft,
        categories_suggested: normalizeList(nextDraft.categories_suggested ?? prev[itemId]?.categories_suggested),
        tags_suggested: normalizeList(nextDraft.tags_suggested ?? prev[itemId]?.tags_suggested),
      },
    }));
  };

  const generateDraft = async (field = 'all') => {
    if (!item || !itemId) {
      return;
    }

    setReviewError('');
    setFieldLoading((prev) => ({ ...prev, [field]: true }));

    try {
      const sourceContent = await buildReviewSourceContent(sources);
      const result = await apiFetch({
        path: aiEndpoint('/review-draft'),
        method: 'POST',
        data: {
          field,
          item: {
            id: item.id,
            title: getCuratedStoryTitle(item),
            summary: getCuratedStoryReason(item),
            sources,
          },
          categories,
          current_draft: draft,
          content: sourceContent || `${getCuratedStoryTitle(item)}\n\n${getCuratedStoryReason(item)}`,
        },
      });

      if (!result?.success) {
        setReviewError(result?.error || 'Não foi possível gerar conteúdo com IA.');
        return;
      }

      updateDraft(result.draft || {});
      markFieldsGenerated(field);
    } catch (error) {
      setReviewError(error?.message || 'Não foi possível gerar conteúdo com IA.');
    } finally {
      setFieldLoading((prev) => ({ ...prev, [field]: false }));
    }
  };

  const setFieldEditing = (field, enabled) => {
    setEditingFields((prev) => ({
      ...prev,
      [`${itemId}:${field}`]: enabled,
    }));
  };

  const isEditing = (field) => !!editingFields[`${itemId}:${field}`];

  const getGenerateAction = (field) => {
    if (hasFieldGenerated(field)) {
      return {
        label: 'Gerar novamente',
        icon: 'dashicons dashicons-update',
      };
    }

    const labels = {
      title: 'Gerar título',
      summary: 'Gerar resumo',
      categories: 'Sugerir categorias',
      tags: 'Sugerir tags',
      content: 'Gerar conteúdo',
    };

    const icons = {
      title: 'dashicons dashicons-lightbulb',
      summary: 'dashicons dashicons-lightbulb',
      categories: 'dashicons dashicons-category',
      tags: 'dashicons dashicons-tag',
      content: 'dashicons dashicons-media-text',
    };

    return {
      label: labels[field] || 'Gerar conteúdo',
      icon: icons[field] || 'dashicons dashicons-lightbulb',
    };
  };

  const getFieldLoadingLabel = (field) => {
    const labels = {
      title: 'Gerando título',
      summary: 'Gerando resumo',
      categories: 'Sugerindo categorias',
      tags: 'Sugerindo tags',
      content: 'Gerando conteúdo',
    };

    return labels[field] || 'Gerando conteúdo';
  };

  const getFieldSectionClass = (field, extraClass = '') => [
    'editorio-publisher__mockup-section',
    extraClass,
    isFieldGenerating(field) ? 'editorio-publisher__mockup-section--generating' : '',
  ].filter(Boolean).join(' ');

  const renderFieldLoading = (field) => (
    isFieldGenerating(field) ? (
      <div className="editorio-publisher__field-generating" aria-live="polite" aria-busy="true">
        <Spinner />
        <span>{getFieldLoadingLabel(field)}</span>
      </div>
    ) : null
  );

  const renderEmptyFieldActions = (field) => {
    const generateAction = getGenerateAction(field);

    return (
      <div className="editorio-publisher__empty-field-actions">
        <Button
          variant="primary"
          className="editorio-publisher__empty-field-button editorio-publisher__empty-field-button--ai"
          onClick={() => generateDraft(field)}
          disabled={isGenerating}
        >
          <span className={generateAction.icon} aria-hidden="true" />
          {generateAction.label}
        </Button>
        <Button
          variant="secondary"
          className="editorio-publisher__empty-field-button"
          onClick={() => setFieldEditing(field, true)}
          disabled={isGenerating}
        >
          <span className="dashicons dashicons-edit" aria-hidden="true" />
          Adicionar conteúdo
        </Button>
      </div>
    );
  };

  const openFeaturedImageFrame = () => {
    if (!itemId) {
      return;
    }

    if (!window.wp?.media) {
      setReviewError('A biblioteca de mídia do WordPress não está disponível.');
      return;
    }

    const mediaFrame = window.wp.media({
      title: 'Selecionar imagem destacada',
      button: {
        text: 'Usar esta imagem',
      },
      library: {
        type: 'image',
      },
      multiple: false,
    });

    mediaFrame.on('select', () => {
      const selection = mediaFrame.state().get('selection').first();
      const attachment = selection ? selection.toJSON() : null;
      if (!attachment) {
        return;
      }

      const preferredUrl =
        attachment.sizes?.large?.url ||
        attachment.sizes?.medium_large?.url ||
        attachment.sizes?.medium?.url ||
        attachment.url ||
        '';

      updateDraft({
        featured_image_id: Number(attachment.id || 0),
        featured_image_url: preferredUrl,
      });
      setReviewError('');
    });

    mediaFrame.open();
  };

  const clearFeaturedImage = () => {
    updateDraft({
      featured_image_id: 0,
      featured_image_url: '',
    });
  };

  const handleApprove = async (approved) => {
    if (!item || !draft) {
      return;
    }

    setLoading(true);
    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/approve-item`),
        method: 'POST',
        data: {
          item_id: item.id,
          approved,
          generated_title: draft.title,
          generated_content: draft.content,
          generated_summary: draft.summary,
          generated_categories: normalizeList(draft.categories_suggested),
          generated_tags: normalizeList(draft.tags_suggested),
          featured_image_id: Number(draft.featured_image_id || 0),
          featured_image_url: draft.featured_image_url || '',
        },
      });

      setReviewItems((prev) => prev.map((reviewItem) => (
        Number(reviewItem.id) === itemId
          ? {
            ...reviewItem,
            approval_status: approved ? 'approved' : 'rejected',
            generated_title: draft.title,
            generated_content: draft.content,
            generated_summary: draft.summary,
            generated_categories: JSON.stringify(normalizeList(draft.categories_suggested)),
            generated_tags: JSON.stringify(normalizeList(draft.tags_suggested)),
            featured_image_id: Number(draft.featured_image_id || 0),
            featured_image_url: draft.featured_image_url || '',
          }
          : reviewItem
      )));
      markFieldsGenerated('all');

      if (currentIndex < reviewItems.length - 1) {
        setCurrentIndex(currentIndex + 1);
      } else {
        onComplete(result);
      }
    } catch (error) {
      setReviewError(error?.message || 'Erro ao aprovar item.');
    } finally {
      setLoading(false);
    }
  };

  const renderFieldActions = (field) => {
    if (isFieldGenerating(field) || !hasFieldContent(field)) {
      return null;
    }

    const generateAction = getGenerateAction(field);

    return (
      <div className="editorio-publisher__mockup-actions">
        <Button
          variant="secondary"
          size="small"
          className="editorio-publisher__mockup-icon-button"
          onClick={() => setFieldEditing(field, !isEditing(field))}
          aria-label={isEditing(field) ? 'Concluir edição' : 'Editar'}
          title={isEditing(field) ? 'Concluir edição' : 'Editar'}
        >
          <span
            className={
              isEditing(field)
                ? 'dashicons dashicons-yes'
                : 'dashicons dashicons-edit'
            }
            aria-hidden="true"
          />
        </Button>
        <Button
          variant="secondary"
          size="small"
          className="editorio-publisher__mockup-icon-button"
          onClick={() => generateDraft(field)}
          aria-label={generateAction.label}
          title={generateAction.label}
        >
          <span className={generateAction.icon} aria-hidden="true" />
        </Button>
      </div>
    );
  };

  const goPrevious = () => {
    setReviewError('');
    setCurrentIndex((prev) => Math.max(0, prev - 1));
  };

  const goNext = () => {
    setReviewError('');
    setCurrentIndex((prev) => Math.min(reviewItems.length - 1, prev + 1));
  };

  const getReviewItemStatus = (reviewItem, index) => {
    if (index === currentIndex) {
      return 'current';
    }

    const status = String(reviewItem.approval_status || '');
    if (status === 'approved' || status === 'rejected') {
      return status;
    }

    return 'pending';
  };

  const getReviewItemStatusLabel = (status) => ({
    current: 'Atual',
    approved: 'Aprovada',
    rejected: 'Rejeitada',
    pending: 'Pendente',
  }[status] || 'Pendente');

  const getReviewItemStatusIcon = (status) => ({
    current: 'dashicons dashicons-marker',
    approved: 'dashicons dashicons-yes',
    rejected: 'dashicons dashicons-no-alt',
    pending: 'dashicons dashicons-clock',
  }[status] || 'dashicons dashicons-clock');
  const pendingReviewItems = reviewItems.filter(isReviewPending);
  const pendingReviewCount = pendingReviewItems.length;

  const closeFinalizeModal = () => {
    if (loading) {
      return;
    }

    setIsFinalizeModalOpen(false);
  };

  const finalizeReview = async () => {
    setLoading(true);
    setReviewError('');

    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/finalize-review`),
        method: 'POST',
      });

      setIsFinalizeModalOpen(false);
      onComplete(result);
    } catch (error) {
      setReviewError(error?.message || 'Não foi possível finalizar a revisão.');
    } finally {
      setLoading(false);
    }
  };

  const handleFinalizeReviewClick = () => {
    if (pendingReviewCount > 0) {
      setIsFinalizeModalOpen(true);
      return;
    }

    void finalizeReview();
  };

  if (reviewItems.length === 0) {
    return (
      <Page className="editorio-publisher-page">
        <Card.Root>
          <Card.Content>
            <p>Nenhum item para revisar</p>
          </Card.Content>
        </Card.Root>
      </Page>
    );
  }

  return (
    <Page className="editorio-publisher-page editorio-publisher-page--review">
      <div className="editorio-publisher__review-layout">
        <aside className="editorio-publisher__review-sidebar" aria-label="Pautas selecionadas">
          <div className="editorio-publisher__review-sidebar-header">
            <span className="editorio-publisher__eyebrow">Selecionadas</span>
            <strong>{reviewItems.length} pauta(s)</strong>
          </div>
          <div className="editorio-publisher__review-sidebar-list">
            {reviewItems.map((reviewItem, index) => {
              const status = getReviewItemStatus(reviewItem, index);

              return (
                <button
                  key={reviewItem.id || index}
                  type="button"
                  className={`editorio-publisher__review-sidebar-item editorio-publisher__review-sidebar-item--${status}`}
                  onClick={() => {
                    setReviewError('');
                    setCurrentIndex(index);
                  }}
                  disabled={isGenerating}
                  aria-current={index === currentIndex ? 'step' : undefined}
                >
                  <span className="editorio-publisher__review-sidebar-index">
                    {index + 1}
                  </span>
                  <span className="editorio-publisher__review-sidebar-copy">
                    <strong>{getCuratedStoryTitle(reviewItem)}</strong>
                    <span>
                      <span className={getReviewItemStatusIcon(status)} aria-hidden="true" />
                      {getReviewItemStatusLabel(status)}
                    </span>
                  </span>
                </button>
              );
            })}
          </div>
        </aside>

        <div className="editorio-publisher__review-main">
          <div className="editorio-publisher__review-header">
            <div>
              <span className="editorio-publisher__eyebrow">Etapa 3 de 4</span>
              <h2>{progress}</h2>
            </div>
            <div className="editorio-publisher__review-header-actions">
              <span className="editorio-publisher__review-counter">
                {currentIndex + 1} de {reviewItems.length}
              </span>
              <Button
                variant="primary"
                onClick={() => generateDraft('all')}
                disabled={isGenerating}
                isBusy={!!fieldLoading.all}
              >
                {hasSavedReviewDraft(item) ? 'Gerar novamente' : 'Gerar com IA'}
              </Button>
            </div>
          </div>

          {reviewError ? (
            <div className="editorio-publisher__review-notice">
              <Notice.Root intent="warning">
                <Notice.Description>{reviewError}</Notice.Description>
              </Notice.Root>
            </div>
          ) : null}

          {hasSavedReviewDraft(item) ? (
            <div className="editorio-publisher__review-notice">
              <Notice.Root intent="info">
                <Notice.Description>
                  Esta pauta já foi {item.approval_status === 'approved' ? 'aprovada' : 'rejeitada'}. Você pode revisar, editar e enviar uma nova decisão.
                </Notice.Description>
              </Notice.Root>
            </div>
          ) : null}

          <article className="editorio-publisher__news-mockup">
            <section className={getFieldSectionClass('title', 'editorio-publisher__mockup-section--title')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Título</span>
                {renderFieldActions('title')}
              </div>
              {renderFieldLoading('title')}
              {isEditing('title') ? (
                <input
                  className="editorio-publisher__mockup-input editorio-publisher__mockup-input--title"
                  value={draft.title}
                  onChange={(event) => updateDraft({ title: event.target.value })}
                />
              ) : !hasFieldContent('title') && !isFieldGenerating('title') ? (
                renderEmptyFieldActions('title')
              ) : (
                <h1>{draft.title}</h1>
              )}
            </section>

            <section className={getFieldSectionClass('summary')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Resumo</span>
                {renderFieldActions('summary')}
              </div>
              {renderFieldLoading('summary')}
              {isEditing('summary') ? (
                <textarea
                  className="editorio-publisher__mockup-textarea"
                  value={draft.summary}
                  onChange={(event) => updateDraft({ summary: event.target.value })}
                  rows={3}
                />
              ) : !hasFieldContent('summary') && !isFieldGenerating('summary') ? (
                renderEmptyFieldActions('summary')
              ) : (
                <p className="editorio-publisher__mockup-summary">{draft.summary}</p>
              )}
            </section>

            <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--media">
              <div className="editorio-publisher__mockup-section-header">
                <span>Imagem destacada</span>
                <div className="editorio-publisher__mockup-actions editorio-publisher__mockup-actions--static">
                  <Button
                    variant="secondary"
                    size="small"
                    className="editorio-publisher__mockup-icon-button"
                    onClick={openFeaturedImageFrame}
                    aria-label={draft.featured_image_url ? 'Trocar imagem destacada' : 'Selecionar imagem destacada'}
                    title={draft.featured_image_url ? 'Trocar imagem destacada' : 'Selecionar imagem destacada'}
                  >
                    <span className="dashicons dashicons-format-image" aria-hidden="true" />
                  </Button>
                  {draft.featured_image_url ? (
                    <Button
                      variant="secondary"
                      size="small"
                      className="editorio-publisher__mockup-icon-button"
                      onClick={clearFeaturedImage}
                      aria-label="Remover imagem destacada"
                      title="Remover imagem destacada"
                    >
                      <span className="dashicons dashicons-trash" aria-hidden="true" />
                    </Button>
                  ) : null}
                </div>
              </div>

              {draft.featured_image_url ? (
                <div className="editorio-publisher__featured-image">
                  <img src={draft.featured_image_url} alt="" />
                  <div className="editorio-publisher__featured-image-meta">
                    <span>
                      {draft.featured_image_id ? `Anexo #${draft.featured_image_id}` : 'Imagem selecionada'}
                    </span>
                    <Button
                      variant="secondary"
                      className="editorio-publisher__featured-image-button"
                      onClick={openFeaturedImageFrame}
                    >
                      Trocar imagem
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="editorio-publisher__empty-field-actions editorio-publisher__empty-field-actions--media">
                  <Button
                    variant="primary"
                    className="editorio-publisher__empty-field-button editorio-publisher__empty-field-button--media"
                    onClick={openFeaturedImageFrame}
                  >
                    <span className="dashicons dashicons-upload" aria-hidden="true" />
                    Enviar ou selecionar imagem
                  </Button>
                  <p className="editorio-publisher__featured-image-help">
                    A imagem escolhida será usada como capa do post criado ao final do fluxo.
                  </p>
                </div>
              )}
            </section>

            <section className={getFieldSectionClass('categories', 'editorio-publisher__mockup-section--meta')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Categorias sugeridas</span>
                {renderFieldActions('categories')}
              </div>
              {renderFieldLoading('categories')}
              {isEditing('categories') ? (
                <div className="editorio-publisher__token-field-wrap">
                  <FormTokenField
                    className="editorio-publisher__category-token-field"
                    value={normalizeList(draft.categories_suggested)}
                    suggestions={categorySuggestions}
                    onChange={(tokens) => updateDraft({ categories_suggested: tokens })}
                    placeholder="Digite para buscar categorias existentes"
                    __experimentalExpandOnFocus
                  />
                  <p className="editorio-publisher__token-field-help">
                    Comece a digitar para ver categorias existentes do WordPress.
                  </p>
                </div>
              ) : !hasFieldContent('categories') && !isFieldGenerating('categories') ? (
                renderEmptyFieldActions('categories')
              ) : (
                <div className="editorio-publisher__mockup-chips">
                  {normalizeList(draft.categories_suggested).map((category) => (
                    <span key={category}>{category}</span>
                  ))}
                </div>
              )}
            </section>

            <section className={getFieldSectionClass('tags', 'editorio-publisher__mockup-section--meta')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Tags sugeridas</span>
                {renderFieldActions('tags')}
              </div>
              {renderFieldLoading('tags')}
              {isEditing('tags') ? (
                <input
                  className="editorio-publisher__mockup-input"
                  value={normalizeList(draft.tags_suggested).join(', ')}
                  onChange={(event) => updateDraft({ tags_suggested: event.target.value })}
                />
              ) : !hasFieldContent('tags') && !isFieldGenerating('tags') ? (
                renderEmptyFieldActions('tags')
              ) : (
                <div className="editorio-publisher__mockup-chips editorio-publisher__mockup-chips--tags">
                  {normalizeList(draft.tags_suggested).map((tag) => (
                    <span key={tag}>{tag}</span>
                  ))}
                </div>
              )}
            </section>

            <section className={getFieldSectionClass('content', 'editorio-publisher__mockup-section--content')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Conteúdo</span>
                {renderFieldActions('content')}
              </div>
              {renderFieldLoading('content')}
              {isEditing('content') ? (
                <textarea
                  className="editorio-publisher__mockup-textarea editorio-publisher__mockup-textarea--content"
                  value={draft.content}
                  onChange={(event) => updateDraft({ content: event.target.value })}
                  rows={14}
                />
              ) : !hasFieldContent('content') && !isFieldGenerating('content') ? (
                renderEmptyFieldActions('content')
              ) : (
                <div
                  className="editorio-publisher__mockup-content"
                  dangerouslySetInnerHTML={{ __html: draft.content }}
                />
              )}
            </section>
          </article>

          {sources.length > 0 ? (
            <div className="editorio-publisher__review-sources">
              <span className="editorio-publisher__curation-sources-label">
                Fontes usadas
              </span>
              <div className="editorio-publisher__curation-source-list">
                {sources.map((source) => (
                  <span
                    key={`${item.id}-${source.workflow_item_id || source.id || source.content_url}`}
                    className="editorio-publisher__source-chip editorio-publisher__source-chip--soft"
                    title={source.content_url || source.source_name || source.title}
                  >
                    {getCuratedSourceLabel(source)}
                  </span>
                ))}
              </div>
            </div>
          ) : null}

          <div className="editorio-publisher__review-action-bar">
            <div className="editorio-publisher__review-action-group editorio-publisher__review-action-group--navigation">
              <Button
                variant="secondary"
                className="editorio-publisher__review-footer-button"
                onClick={onBack}
                disabled={loading || isGenerating}
              >
                <span className="dashicons dashicons-list-view" aria-hidden="true" />
                Seleção
              </Button>
              <Button
                variant="secondary"
                className="editorio-publisher__review-footer-button"
                onClick={goPrevious}
                disabled={loading || isGenerating || currentIndex === 0}
              >
                <span className="dashicons dashicons-arrow-left-alt2" aria-hidden="true" />
                Anterior
              </Button>
              <Button
                variant="secondary"
                className="editorio-publisher__review-footer-button"
                onClick={goNext}
                disabled={loading || isGenerating || currentIndex >= reviewItems.length - 1}
              >
                Próxima
                <span className="dashicons dashicons-arrow-right-alt2" aria-hidden="true" />
              </Button>
              <Button
                variant="secondary"
                className="editorio-publisher__review-footer-button editorio-publisher__review-footer-button--finalize"
                onClick={handleFinalizeReviewClick}
                disabled={loading || isGenerating}
                isBusy={loading && !fieldLoading.all}
              >
                <span className="dashicons dashicons-saved" aria-hidden="true" />
                Finalizar revisão
              </Button>
            </div>
            <div className="editorio-publisher__review-action-group editorio-publisher__review-action-group--decision">
              <Button
                variant="secondary"
                className="editorio-publisher__review-footer-button editorio-publisher__review-footer-button--reject"
                onClick={() => handleApprove(false)}
                disabled={loading || isGenerating}
              >
                <span className="dashicons dashicons-no-alt" aria-hidden="true" />
                Rejeitar
              </Button>
              <Button
                variant="primary"
                className="editorio-publisher__review-footer-button editorio-publisher__review-footer-button--approve"
                onClick={() => handleApprove(true)}
                disabled={loading || isGenerating}
                isBusy={loading}
              >
                <span className="dashicons dashicons-yes" aria-hidden="true" />
                Aprovar pauta
              </Button>
            </div>
          </div>

          {isFinalizeModalOpen ? (
            <Modal
              title="Finalizar revisão"
              onRequestClose={closeFinalizeModal}
              className="editorio-publisher__review-modal"
            >
              <div className="editorio-publisher__review-modal-copy">
                <p>
                  {pendingReviewCount === 1
                    ? 'Existe 1 notícia sem revisão.'
                    : `Existem ${pendingReviewCount} notícias sem revisão.`}
                </p>
                <p>
                  Se você continuar, elas serão marcadas como rejeitadas automaticamente e descartadas desta publicação.
                </p>
              </div>
              <div className="editorio-publisher__review-modal-actions">
                <Button
                  variant="secondary"
                  className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--secondary"
                  onClick={closeFinalizeModal}
                  disabled={loading}
                >
                  Continuar revisando
                </Button>
                <Button
                  variant="primary"
                  className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--primary"
                  onClick={finalizeReview}
                  isBusy={loading}
                  disabled={loading}
                >
                  Finalizar e descartar pendentes
                </Button>
              </div>
            </Modal>
          ) : null}
        </div>
      </div>
    </Page>
  );
};


export default ReviewScreen;
