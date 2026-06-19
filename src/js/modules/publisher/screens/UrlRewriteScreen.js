import {useEffect, useRef, useState} from '@wordpress/element';
import {Button, Card, Notice} from '@wordpress/ui';
import {FormTokenField, Modal, Spinner} from '@wordpress/components';
import {Page} from '@wordpress/admin-ui';
import apiFetch from '@wordpress/api-fetch';
import {publisherEndpoint} from '../api/endpoints';
import {message} from '../config';
import {normalizeList} from '../utils/review';

const createEmptyDraft = () => ({
  title: '',
  summary: '',
  categories_suggested: [],
  tags_suggested: [],
  content: '',
  featured_image_id: 0,
  featured_image_url: '',
});

const createInitialSources = () => ([
  {id: 1, url: ''},
  {id: 2, url: ''},
  {id: 3, url: ''},
]);

const createDefaultGenerationOptions = () => ({
  article_size: 'media',
  tone: 'neutro',
  title_style: 'objetivo',
  include_subheadings: true,
  strict_facts: true,
});

const UrlRewriteScreen = () => {
  const [prompt, setPrompt] = useState(
    message(
      'urlRewriteDefaultPrompt',
      'Escreva uma nova notícia em português do Brasil com tom jornalístico neutro, sem copiar trechos das fontes. Cruze apenas os fatos confirmados nas URLs fornecidas, elimine redundâncias e entregue título, resumo, categorias, tags e conteúdo pronto para o editor de blocos do WordPress.'
    )
  );
  const [sources, setSources] = useState(createInitialSources);
  const [resolvedSources, setResolvedSources] = useState([]);
  const [sourceResults, setSourceResults] = useState([]);
  const [draft, setDraft] = useState(createEmptyDraft);
  const [categories, setCategories] = useState([]);
  const [generationOptions, setGenerationOptions] = useState(createDefaultGenerationOptions);
  const [action, setAction] = useState('draft');
  const [scheduledAt, setScheduledAt] = useState('');
  const [fieldLoading, setFieldLoading] = useState({});
  const [generatingField, setGeneratingField] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [resultModal, setResultModal] = useState(null);
  const contentTextareaRef = useRef(null);

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
      } catch (loadError) {
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

  useEffect(() => {
    const textarea = contentTextareaRef.current;
    if (!textarea) {
      return;
    }

    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
  }, [draft.content]);

  const categorySuggestions = categories
    .map((category) => String(category.name || '').trim())
    .filter(Boolean);

  const normalizedUrls = sources
    .map((source) => String(source.url || '').trim())
    .filter(Boolean);

  const isGenerating = Object.values(fieldLoading).some(Boolean);
  const successfulSourceResults = sourceResults.filter((item) => item?.success);
  const failedSourceResults = sourceResults.filter((item) => item && !item.success);

  const updateSource = (id, value) => {
    setSources((current) => current.map((source) => (
      source.id === id ? {...source, url: value} : source
    )));
  };

  const addSourceField = () => {
    setSources((current) => [...current, {id: Date.now(), url: ''}]);
  };

  const removeSourceField = (id) => {
    setSources((current) => {
      if (current.length <= 1) {
        return [{id: current[0]?.id || 1, url: ''}];
      }

      return current.filter((source) => source.id !== id);
    });
  };

  const updateDraft = (patch) => {
    setDraft((current) => ({
      ...current,
      ...patch,
      categories_suggested: normalizeList(patch.categories_suggested ?? current.categories_suggested),
      tags_suggested: normalizeList(patch.tags_suggested ?? current.tags_suggested),
    }));
  };

  const updateGenerationOptions = (patch) => {
    setGenerationOptions((current) => ({
      ...current,
      ...patch,
    }));
  };

  const generateDraft = async (field = 'all') => {
    if (normalizedUrls.length === 0) {
      setError('Informe pelo menos uma URL de notícia.');
      return;
    }

    setFieldLoading((current) => ({...current, [field]: true}));
    setGeneratingField(field);
    setError('');
    setResultModal(null);

    try {
      const result = await apiFetch({
        path: publisherEndpoint('/url-rewrite-draft'),
        method: 'POST',
        data: {
          field,
          prompt,
          urls: normalizedUrls,
          categories,
          options: generationOptions,
          current_draft: draft,
        },
      });

      if (!result?.success) {
        setSourceResults(Array.isArray(result?.source_results) ? result.source_results : []);
        setError(result?.error || 'Não foi possível gerar a matéria.');
        return;
      }

      setResolvedSources(Array.isArray(result.sources) ? result.sources : []);
      setSourceResults(Array.isArray(result.source_results) ? result.source_results : []);
      updateDraft({
        ...createEmptyDraft(),
        ...draft,
        ...(result.draft || {}),
        categories_suggested: normalizeList(result?.draft?.categories_suggested),
        tags_suggested: normalizeList(result?.draft?.tags_suggested),
      });
    } catch (requestError) {
      setError(requestError?.message || 'Não foi possível gerar a matéria.');
    } finally {
      setFieldLoading((current) => ({...current, [field]: false}));
      setGeneratingField('');
    }
  };

  const getGenerateAction = (field) => {
    const labels = {
      all: 'Gerar novamente',
      title: 'Gerar título',
      summary: 'Gerar resumo',
      categories: 'Sugerir categorias',
      tags: 'Sugerir tags',
      content: 'Gerar conteúdo',
    };

    const icons = {
      all: 'dashicons dashicons-update',
      title: 'dashicons dashicons-lightbulb',
      summary: 'dashicons dashicons-lightbulb',
      categories: 'dashicons dashicons-category',
      tags: 'dashicons dashicons-tag',
      content: 'dashicons dashicons-media-text',
    };

    return {
      label: labels[field] || 'Gerar novamente',
      icon: icons[field] || 'dashicons dashicons-update',
    };
  };

  const renderGenerateFieldAction = (field) => {
    const actionConfig = getGenerateAction(field);

    return (
      <div className="editorio-publisher__mockup-actions editorio-publisher__mockup-actions--static">
        <Button
          variant="secondary"
          size="small"
          className="editorio-publisher__mockup-icon-button"
          onClick={() => generateDraft(field)}
          disabled={isGenerating || saving || normalizedUrls.length === 0}
          aria-label={actionConfig.label}
          title={actionConfig.label}
        >
          <span className={actionConfig.icon} aria-hidden="true" />
        </Button>
      </div>
    );
  };

  const openFeaturedImageFrame = () => {
    if (!window.wp?.media) {
      setError('A biblioteca de mídia do WordPress não está disponível.');
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
    });

    mediaFrame.open();
  };

  const clearFeaturedImage = () => {
    updateDraft({
      featured_image_id: 0,
      featured_image_url: '',
    });
  };

  const handleSave = async () => {
    if (!draft.title.trim()) {
      setError('Informe um título para a matéria.');
      return;
    }

    if (!draft.content.trim()) {
      setError('A matéria precisa ter conteúdo antes de salvar.');
      return;
    }

    if (action === 'schedule' && !scheduledAt) {
      setError('Informe data e hora para o agendamento.');
      return;
    }

    setSaving(true);
    setError('');

    try {
      const result = await apiFetch({
        path: publisherEndpoint('/url-generated-post'),
        method: 'POST',
        data: {
          title: draft.title,
          summary: draft.summary,
          content: draft.content,
          categories: normalizeList(draft.categories_suggested),
          tags: normalizeList(draft.tags_suggested),
          featured_image_id: Number(draft.featured_image_id || 0),
          action,
          scheduled_at: scheduledAt,
        },
      });

      setResultModal({
        type: 'success',
        data: result || null,
      });
    } catch (requestError) {
      setResultModal({
        type: 'error',
        message: requestError?.message || 'Não foi possível salvar a matéria.',
      });
    } finally {
      setSaving(false);
    }
  };

  const closeResultModal = () => {
    if (saving) {
      return;
    }

    setResultModal(null);
  };

  const resetGenerator = () => {
    setSources(createInitialSources());
    setResolvedSources([]);
    setSourceResults([]);
    setDraft(createEmptyDraft());
    setAction('draft');
    setScheduledAt('');
    setFieldLoading({});
    setGeneratingField('');
    setError('');
    setResultModal(null);
  };

  const openSavedPostEditor = () => {
    const editUrl = resultModal?.data?.edit_url;
    if (!editUrl) {
      return;
    }

    window.location.assign(editUrl);
  };

  const openSavedPostView = () => {
    const viewUrl = resultModal?.data?.view_url;
    if (!viewUrl) {
      return;
    }

    window.open(viewUrl, '_blank', 'noopener,noreferrer');
  };

  const retryGenerateCurrentArticle = async () => {
    closeResultModal();
    await generateDraft('all');
  };

  const getSaveResultHeadline = () => {
    const savedAction = resultModal?.data?.action || action;

    if (savedAction === 'publish') {
      return 'A matéria foi publicada com sucesso.';
    }

    if (savedAction === 'schedule') {
      return 'A matéria foi agendada com sucesso.';
    }

    return 'A matéria foi salva como rascunho.';
  };

  return (
    <Page className="editorio-publisher-page">
      <Card.Root className="editorio-publisher__launch-card">
        <Card.Content>
          <div className="editorio-publisher__collection editorio-publisher__collection--single">
            <div className="editorio-publisher__collection-main">
              <span className="editorio-publisher__eyebrow">Reescrita por URLs</span>
              <h2>{message('urlRewritePageTitle', 'Gerar notícia por URLs')}</h2>
              <p className="editorio-publisher__lead">
                {message(
                  'urlRewritePageSubtitle',
                  'Colete o conteúdo de matérias publicadas e gere uma nova notícia com orientação editorial extra.'
                )}
              </p>

              {error ? (
                <Notice.Root intent="warning">
                  <Notice.Description>{error}</Notice.Description>
                </Notice.Root>
              ) : null}

              {sourceResults.length > 0 && failedSourceResults.length > 0 ? (
                <Notice.Root intent="warning">
                  <Notice.Description>
                    {successfulSourceResults.length > 0
                      ? `${successfulSourceResults.length} fonte(s) foram aproveitadas e ${failedSourceResults.length} falharam na coleta.`
                      : 'Nenhuma fonte pôde ser aproveitada. Veja abaixo os motivos por URL.'}
                  </Notice.Description>
                </Notice.Root>
              ) : null}

              <section className="editorio-publisher__url-rewrite-form">
                <div className="editorio-publisher__url-rewrite-field">
                  <label htmlFor="editorio-url-rewrite-prompt">Instrução editorial</label>
                  <textarea
                    id="editorio-url-rewrite-prompt"
                    className="editorio-publisher__mockup-textarea editorio-publisher__mockup-textarea--content"
                    value={prompt}
                    onChange={(event) => setPrompt(event.target.value)}
                    rows={6}
                    disabled={isGenerating || saving}
                  />
                </div>

                <div className="editorio-publisher__url-rewrite-settings">
                  <div className="editorio-publisher__url-rewrite-option">
                    <div className="editorio-publisher__url-rewrite-option-copy">
                      <label htmlFor="editorio-url-rewrite-size">Tamanho da matéria</label>
                      <p>Define o nível de aprofundamento, contexto e volume total do texto gerado.</p>
                    </div>
                    <select
                      id="editorio-url-rewrite-size"
                      value={generationOptions.article_size}
                      onChange={(event) => updateGenerationOptions({article_size: event.target.value})}
                      disabled={isGenerating || saving}
                    >
                      <option value="curta">Curta</option>
                      <option value="media">Média</option>
                      <option value="longa">Longa</option>
                      <option value="completa">Completa</option>
                    </select>
                  </div>

                  <div className="editorio-publisher__url-rewrite-option">
                    <div className="editorio-publisher__url-rewrite-option-copy">
                      <label htmlFor="editorio-url-rewrite-tone">Tom</label>
                      <p>Controla a voz editorial da matéria, do relato mais seco ao texto mais analítico.</p>
                    </div>
                    <select
                      id="editorio-url-rewrite-tone"
                      value={generationOptions.tone}
                      onChange={(event) => updateGenerationOptions({tone: event.target.value})}
                      disabled={isGenerating || saving}
                    >
                      <option value="neutro">Neutro</option>
                      <option value="direto">Direto</option>
                      <option value="analitico">Analítico</option>
                      <option value="informativo">Informativo</option>
                    </select>
                  </div>

                  <div className="editorio-publisher__url-rewrite-option">
                    <div className="editorio-publisher__url-rewrite-option-copy">
                      <label htmlFor="editorio-url-rewrite-title-style">Estilo do título</label>
                      <p>Orienta a IA sobre como abrir a matéria, priorizando objetividade, impacto ou busca.</p>
                    </div>
                    <select
                      id="editorio-url-rewrite-title-style"
                      value={generationOptions.title_style}
                      onChange={(event) => updateGenerationOptions({title_style: event.target.value})}
                      disabled={isGenerating || saving}
                    >
                      <option value="objetivo">Objetivo</option>
                      <option value="chamativo">Chamativo</option>
                      <option value="seo">SEO</option>
                      <option value="institucional">Institucional</option>
                    </select>
                  </div>

                  <div className="editorio-publisher__url-rewrite-option">
                    <div className="editorio-publisher__url-rewrite-option-copy">
                      <label htmlFor="editorio-url-rewrite-subheadings">Usar subtítulos no conteúdo</label>
                      <p>Organiza a leitura em blocos e ajuda a separar contexto, fatos principais e desdobramentos.</p>
                    </div>
                    <label className="editorio-publisher__url-rewrite-toggle" htmlFor="editorio-url-rewrite-subheadings">
                      <input
                        id="editorio-url-rewrite-subheadings"
                        type="checkbox"
                        checked={generationOptions.include_subheadings}
                        onChange={(event) => updateGenerationOptions({include_subheadings: event.target.checked})}
                        disabled={isGenerating || saving}
                      />
                      <span>{generationOptions.include_subheadings ? 'Ativado' : 'Desativado'}</span>
                    </label>
                  </div>

                  <div className="editorio-publisher__url-rewrite-option">
                    <div className="editorio-publisher__url-rewrite-option-copy">
                      <label htmlFor="editorio-url-rewrite-strict-facts">Modo estrito aos fatos das fontes</label>
                      <p>Evita extrapolar informações que não estejam claramente confirmadas nas URLs fornecidas.</p>
                    </div>
                    <label className="editorio-publisher__url-rewrite-toggle" htmlFor="editorio-url-rewrite-strict-facts">
                      <input
                        id="editorio-url-rewrite-strict-facts"
                        type="checkbox"
                        checked={generationOptions.strict_facts}
                        onChange={(event) => updateGenerationOptions({strict_facts: event.target.checked})}
                        disabled={isGenerating || saving}
                      />
                      <span>{generationOptions.strict_facts ? 'Ativado' : 'Desativado'}</span>
                    </label>
                  </div>
                </div>

                <div className="editorio-publisher__url-rewrite-field">
                  <div className="editorio-publisher__url-rewrite-field-header">
                    <label>Fontes</label>
                    <Button
                      variant="secondary"
                      size="small"
                      className="editorio-publisher__url-rewrite-add-button"
                      onClick={addSourceField}
                      disabled={isGenerating || saving}
                    >
                      <span className="dashicons dashicons-plus-alt2" aria-hidden="true" />
                      Adicionar URL
                    </Button>
                  </div>

                  <div className="editorio-publisher__url-rewrite-source-list">
                    {sources.map((source, index) => (
                      <div key={source.id} className="editorio-publisher__url-rewrite-source-row">
                        <input
                          type="url"
                          value={source.url}
                          onChange={(event) => updateSource(source.id, event.target.value)}
                          placeholder={`https://fonte-${index + 1}.com/noticia`}
                          disabled={isGenerating || saving}
                        />
                        <Button
                          variant="secondary"
                          size="small"
                          className="editorio-publisher__url-rewrite-remove-button"
                          onClick={() => removeSourceField(source.id)}
                          disabled={isGenerating || saving}
                          aria-label={`Remover URL ${index + 1}`}
                          title="Remover URL"
                        >
                          <span className="dashicons dashicons-no-alt" aria-hidden="true" />
                        </Button>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="editorio-publisher__actions editorio-publisher__actions--primary">
                    <Button
                      variant="primary"
                      onClick={() => generateDraft('all')}
                      disabled={isGenerating || saving || normalizedUrls.length === 0}
                      isBusy={isGenerating}
                    >
                      Gerar matéria
                    </Button>
                </div>
              </section>

              {resolvedSources.length > 0 ? (
                <section className="editorio-publisher__review-sources">
                  <span className="editorio-publisher__curation-sources-label">Fontes consideradas</span>
                  <div className="editorio-publisher__curation-source-list">
                    {resolvedSources.map((source, index) => (
                      <a
                        key={`${source.content_url || source.title || index}`}
                        href={source.content_url}
                        target="_blank"
                        rel="noreferrer"
                        className="editorio-publisher__source-chip editorio-publisher__source-chip--soft"
                      >
                        {source.title || source.source_name || `Fonte ${index + 1}`}
                      </a>
                    ))}
                  </div>
                </section>
              ) : null}

              {sourceResults.length > 0 ? (
                <section className="editorio-publisher__review-sources">
                  <span className="editorio-publisher__curation-sources-label">Status da coleta</span>
                  <div className="editorio-publisher__source-result-list">
                    {sourceResults.map((sourceResult, index) => (
                      <div
                        key={`${sourceResult.url || index}-${sourceResult.success ? 'ok' : 'error'}`}
                        className={
                          sourceResult.success
                            ? 'editorio-publisher__source-result editorio-publisher__source-result--success'
                            : 'editorio-publisher__source-result editorio-publisher__source-result--error'
                        }
                      >
                        <div className="editorio-publisher__source-result-header">
                          <strong>{sourceResult.title || sourceResult.source_name || `Fonte ${index + 1}`}</strong>
                          <span>{sourceResult.success ? 'Coletada' : 'Falhou'}</span>
                        </div>
                        <a href={sourceResult.url} target="_blank" rel="noreferrer">
                          {sourceResult.url}
                        </a>
                        {sourceResult.success ? (
                          <p>
                            {sourceResult.warning
                              ? sourceResult.warning
                              : 'Texto lido com sucesso'}
                            {sourceResult.content_length ? ` (${sourceResult.content_length} caracteres extraídos).` : '.'}
                          </p>
                        ) : (
                          <p>{sourceResult.error || 'A fonte falhou sem detalhe retornado pelo servidor.'}</p>
                        )}
                      </div>
                    ))}
                  </div>
                </section>
              ) : null}

              {draft.title || draft.content ? (
                <div className="editorio-publisher__url-rewrite-draft">
                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--title">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Título</h3>
                      {renderGenerateFieldAction('title')}
                    </div>
                    <textarea
                      className="editorio-publisher__mockup-textarea editorio-publisher__mockup-textarea--title"
                      value={draft.title}
                      onChange={(event) => updateDraft({title: event.target.value})}
                      disabled={saving || isGenerating}
                      rows={3}
                    />
                  </section>

                  <section className="editorio-publisher__mockup-section">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Resumo</h3>
                      {renderGenerateFieldAction('summary')}
                    </div>
                    <textarea
                      className="editorio-publisher__mockup-textarea"
                      value={draft.summary}
                      onChange={(event) => updateDraft({summary: event.target.value})}
                      rows={4}
                      disabled={saving || isGenerating}
                    />
                  </section>

                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--media">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Imagem destacada</h3>
                      <div className="editorio-publisher__mockup-actions editorio-publisher__mockup-actions--static">
                        <Button
                          variant="secondary"
                          size="small"
                          className="editorio-publisher__mockup-icon-button"
                          onClick={openFeaturedImageFrame}
                          disabled={saving}
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
                            disabled={saving}
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
                      </div>
                    ) : (
                      <p className="editorio-publisher__featured-image-help">
                        Selecione uma imagem da biblioteca do WordPress, se necessário.
                      </p>
                    )}
                  </section>

                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--meta">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Categorias</h3>
                      {renderGenerateFieldAction('categories')}
                    </div>
                    <FormTokenField
                      className="editorio-publisher__token-field"
                      value={normalizeList(draft.categories_suggested)}
                      suggestions={categorySuggestions}
                      onChange={(values) => updateDraft({categories_suggested: values})}
                      disabled={saving || isGenerating}
                    />
                  </section>

                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--meta">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Tags</h3>
                      {renderGenerateFieldAction('tags')}
                    </div>
                    <FormTokenField
                      className="editorio-publisher__token-field"
                      value={normalizeList(draft.tags_suggested)}
                      onChange={(values) => updateDraft({tags_suggested: values})}
                      disabled={saving || isGenerating}
                    />
                  </section>

                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--content">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Conteúdo</h3>
                      {renderGenerateFieldAction('content')}
                    </div>
                    <textarea
                      ref={contentTextareaRef}
                      className="editorio-publisher__mockup-textarea editorio-publisher__mockup-textarea--content editorio-publisher__url-rewrite-content-editor"
                      value={draft.content}
                      onChange={(event) => updateDraft({content: event.target.value})}
                      rows={18}
                      disabled={saving || isGenerating}
                    />
                  </section>

                  <section className="editorio-publisher__mockup-section editorio-publisher__mockup-section--meta">
                    <div className="editorio-publisher__mockup-section-header">
                      <h3>Destino</h3>
                    </div>
                    <div className="editorio-publisher__url-rewrite-publish-row">
                      <select
                        value={action}
                        onChange={(event) => setAction(event.target.value)}
                        disabled={saving}
                      >
                        <option value="draft">Salvar como rascunho</option>
                        <option value="publish">Publicar agora</option>
                        <option value="schedule">Agendar</option>
                      </select>
                      {action === 'schedule' ? (
                        <input
                          type="datetime-local"
                          value={scheduledAt}
                          onChange={(event) => setScheduledAt(event.target.value)}
                          disabled={saving}
                        />
                      ) : null}
                    </div>
                  </section>

                  <div className="editorio-publisher__actions editorio-publisher__actions--primary">
                    <Button
                      variant="secondary"
                      onClick={() => generateDraft('all')}
                      disabled={isGenerating || saving || normalizedUrls.length === 0}
                      isBusy={Boolean(fieldLoading.all)}
                    >
                      Gerar novamente
                    </Button>
                    <Button
                      variant="primary"
                      onClick={handleSave}
                      disabled={isGenerating || saving}
                      isBusy={saving}
                    >
                      {action === 'publish' ? 'Publicar matéria' : action === 'schedule' ? 'Agendar matéria' : 'Salvar matéria'}
                    </Button>
                  </div>
                </div>
              ) : null}
            </div>

          </div>
        </Card.Content>

        {(isGenerating || saving) ? (
          <div className="editorio-publisher__launch-overlay" aria-live="polite" aria-busy="true">
            <div className="editorio-publisher__launch-overlay-box">
              <Spinner />
              <strong>{isGenerating ? 'Gerando matéria' : 'Salvando matéria'}</strong>
              <p>
                {isGenerating
                  ? (
                    generatingField && generatingField !== 'all'
                      ? 'Atualizando apenas o campo selecionado com base nas URLs fornecidas.'
                      : 'Coletando conteúdo das URLs e montando o rascunho.'
                  )
                  : 'Criando o post no WordPress com os dados revisados.'}
              </p>
            </div>
          </div>
        ) : null}

        {resultModal ? (
          <Modal
            title={resultModal.type === 'success' ? 'Matéria salva' : 'Erro ao salvar'}
            onRequestClose={closeResultModal}
            className="editorio-publisher__review-modal editorio-publisher__result-modal"
          >
            <div className="editorio-publisher__review-modal-copy">
              {resultModal.type === 'success' ? (
                <>
                  <p>{getSaveResultHeadline()}</p>
                  <p>Escolha o próximo passo para continuar o trabalho editorial.</p>
                </>
              ) : (
                <>
                  <p>{resultModal.message || 'Não foi possível salvar a matéria.'}</p>
                  <p>Você pode continuar editando aqui ou tentar salvar novamente.</p>
                </>
              )}
            </div>

            <div className="editorio-publisher__review-modal-actions editorio-publisher__result-modal-actions">
              {resultModal.type === 'success' ? (
                <>
                  <Button
                    variant="secondary"
                    className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--secondary"
                    onClick={closeResultModal}
                  >
                    Continuar nesta matéria
                  </Button>
                  <Button
                    variant="secondary"
                    className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--secondary"
                    onClick={resetGenerator}
                  >
                    Gerar outra
                  </Button>
                  <Button
                    variant="secondary"
                    className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--secondary"
                    onClick={retryGenerateCurrentArticle}
                    disabled={isGenerating}
                  >
                    Gerar nova versão
                  </Button>
                  {resultModal?.data?.edit_url ? (
                    <Button
                      variant="primary"
                      className="editorio-publisher__review-modal-button editorio-publisher__result-modal-button--success"
                      onClick={openSavedPostEditor}
                    >
                      Editar no WordPress
                    </Button>
                  ) : null}
                  {resultModal?.data?.view_url ? (
                    <Button
                      variant="secondary"
                      className="editorio-publisher__review-modal-button editorio-publisher__result-modal-button--view"
                      onClick={openSavedPostView}
                    >
                      Ver matéria
                    </Button>
                  ) : null}
                </>
              ) : (
                <>
                  <Button
                    variant="secondary"
                    className="editorio-publisher__review-modal-button editorio-publisher__review-modal-button--secondary"
                    onClick={closeResultModal}
                  >
                    Continuar editando
                  </Button>
                  <Button
                    variant="primary"
                    className="editorio-publisher__review-modal-button editorio-publisher__result-modal-button--danger"
                    onClick={handleSave}
                    isBusy={saving}
                    disabled={saving}
                  >
                    Tentar salvar novamente
                  </Button>
                </>
              )}
            </div>
          </Modal>
        ) : null}
      </Card.Root>
    </Page>
  );
};

export default UrlRewriteScreen;
