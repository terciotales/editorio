import domReady from '@wordpress/dom-ready';
import {createRoot, useEffect, useState} from '@wordpress/element';
import {Button, Card, Notice} from '@wordpress/ui';
import {Spinner} from '@wordpress/components';
import {Page} from '@wordpress/admin-ui';
import apiFetch from '@wordpress/api-fetch';
import '../../../css/modules/publisher/index.scss';

const config = window.editorioPublisher || {
  restNamespace: '/editorio/v1',
  nonce: '',
  messages: {},
};

const stageLabels = {
  collecting: 'Coleta',
  curating: 'Curadoria',
  reviewing: 'Revisão',
  confirming: 'Confirmação',
  completed: 'Concluída',
};

if (config.nonce) {
  apiFetch.use((options, next) => {
    const headers = {
      ...(options.headers || {}),
      'X-WP-Nonce': config.nonce,
    };

    return next({ ...options, headers });
  });
}

const publisherEndpoint = (path = '') => `${config.restNamespace}/publisher${path}`;
const sourcesEndpoint = (path = '') => `${config.restNamespace}/sources${path}`;
const aiEndpoint = (path = '') => `${config.restNamespace}/ai${path}`;

function getSessionFromUrl() {
  if (typeof window === 'undefined') {
    return '';
  }

  return new URLSearchParams(window.location.search).get('session') || '';
}

function writeSessionToUrl(sessionId) {
  if (typeof window === 'undefined' || !sessionId) {
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set('session', sessionId);
  window.history.pushState({}, '', url.toString());
}

function normalizeWorkflowStage(stage) {
  if (stage === 'confirmation') {
    return 'confirming';
  }

  return ['collecting', 'curating', 'reviewing', 'confirming', 'completed'].includes(stage)
    ? stage
    : 'idle';
}

function message(key, fallback) {
  return config.messages && config.messages[key]
    ? config.messages[key]
    : fallback;
}

function parseJsonArray(value) {
  if (Array.isArray(value)) {
    return value;
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return [];
  }

  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

function getCuratedStoryTitle(item) {
  return item.generated_title || item.title || 'Pauta sem título';
}

function getCuratedStoryReason(item) {
  return item.curation_reason || item.reason || item.summary || item.description || '';
}

function getCuratedStorySources(item) {
  const sources = parseJsonArray(item.curation_sources);
  if (sources.length > 0) {
    return sources;
  }

  if (Array.isArray(item.sources)) {
    return item.sources;
  }

  return [];
}

function getCuratedStoryContent(item) {
  return item.generated_content || '';
}

function getCuratedSourceLabel(source) {
  return source.source_name || source.title || `ID ${source.workflow_item_id || source.id}`;
}

function getCuratedStoryMode(item) {
  if (item.curation_mode) {
    return item.curation_mode;
  }

  return item.curation_error ? 'automatic' : 'ai';
}

function getCuratedStoryError(item) {
  return item.curation_error || '';
}

function createEmptyReviewDraft(item) {
  return {
    title: getCuratedStoryTitle(item),
    summary: item.generated_summary || getCuratedStoryReason(item),
    categories_suggested: normalizeList(parseJsonArray(item.generated_categories).length > 0 ? parseJsonArray(item.generated_categories) : item.generated_categories),
    tags_suggested: normalizeList(parseJsonArray(item.generated_tags).length > 0 ? parseJsonArray(item.generated_tags) : item.generated_tags),
    content: getCuratedStoryContent(item),
  };
}

function hasSavedReviewDraft(item) {
  return ['approved', 'rejected'].includes(String(item.approval_status || ''));
}

function isReviewPending(item) {
  return !hasSavedReviewDraft(item);
}

function createGeneratedFieldState(item) {
  if (!hasSavedReviewDraft(item)) {
    return {};
  }

  return {
    title: Boolean(item.generated_title),
    summary: Boolean(item.generated_summary),
    categories: normalizeList(parseJsonArray(item.generated_categories).length > 0 ? parseJsonArray(item.generated_categories) : item.generated_categories).length > 0,
    tags: normalizeList(parseJsonArray(item.generated_tags).length > 0 ? parseJsonArray(item.generated_tags) : item.generated_tags).length > 0,
    content: Boolean(item.generated_content),
  };
}

function normalizeList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean);
  }

  if (typeof value === 'string') {
    return value.split(/[,;\n]+/).map((item) => item.trim()).filter(Boolean);
  }

  return [];
}

function extractReadableText(html) {
  if (!html) {
    return '';
  }

  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll('script, style, noscript, svg, iframe').forEach((node) => node.remove());

  return (doc.body?.innerText || '')
    .replace(/\s+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/[ \t]{2,}/g, ' ')
    .trim();
}

async function fetchSourceText(url) {
  if (!url) {
    return '';
  }

  try {
    const response = await fetch(url, {
      credentials: 'omit',
      cache: 'no-store',
    });

    if (!response.ok) {
      return '';
    }

    return extractReadableText(await response.text());
  } catch (error) {
    return '';
  }
}

async function buildReviewSourceContent(sources) {
  const sourceList = sources.slice(0, 4);
  const contents = await Promise.all(
    sourceList.map(async (source) => {
      const text = await fetchSourceText(source.content_url || '');
      const fallback = [source.source_name, source.title].filter(Boolean).join(' - ');
      return [fallback, text].filter(Boolean).join('\n\n').slice(0, 8000);
    })
  );

  return contents.filter(Boolean).join('\n\n---\n\n');
}

const collectionStages = [
  {
    label: 'Coleta',
    description: 'Sincroniza os lotes das fontes ativas.',
  },
  {
    label: 'Curadoria',
    description: 'Seleciona os itens mais relevantes para publicação.',
  },
  {
    label: 'Revisão',
    description: 'Valida o conteúdo gerado antes do rascunho.',
  },
  {
    label: 'Rascunho',
    description: 'Salva o resultado final no WordPress.',
  },
];

const CollectionShell = ({ eyebrow, title, lead, children, aside }) => {
  return (
    <Page className="editorio-publisher-page">
      <Card.Root className="editorio-publisher__launch-card editorio-publisher__collection-card">
        <Card.Content>
          <div
            className={
              aside
                ? 'editorio-publisher__collection'
                : 'editorio-publisher__collection editorio-publisher__collection--single'
            }
          >
            <div className="editorio-publisher__collection-main">
              <span className="editorio-publisher__eyebrow">{eyebrow}</span>
              <h2>{title}</h2>
              <p className="editorio-publisher__lead">{lead}</p>
              {children}
            </div>

            {aside ? (
              <div className="editorio-publisher__launch-aside">{aside}</div>
            ) : null}
          </div>
        </Card.Content>
      </Card.Root>
    </Page>
  );
};

// Etapa 1: Coleta de dados
const CollectionScreen = ({
  sessionId,
  onComplete,
  activeStageIndex = 0,
  activeSources = [],
  activeSourcesLoading = false,
  activeSourcesError = '',
}) => {
  const [collectorStatus, setCollectorStatus] = useState(null);
  const [loading, setLoading] = useState(false);
  const [noSources, setNoSources] = useState(false);

  useEffect(() => {
    if (!sessionId) return;

    let isMounted = true;
    let interval;

    const finalizeCollection = async () => {
      clearInterval(interval);
      if (isMounted) {
        setLoading(true);
      }

      try {
        const result = await apiFetch({
          path: publisherEndpoint(`/workflow/${sessionId}/finalize-collection`),
          method: 'POST',
        });

        if (isMounted) {
          onComplete(result);
        }
      } catch (error) {
        console.error('Erro ao finalizar coleta:', error);
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    interval = setInterval(async () => {
      try {
        const status = await apiFetch({
          path: publisherEndpoint(`/workflow/${sessionId}/status`),
        });
        setCollectorStatus(status);

        const totalItems = status.collector_status?.items || 0;

        if (totalItems === 0) {
          setNoSources(true);
          return;
        }

        await finalizeCollection();
      } catch (error) {
        console.error('Erro ao buscar status:', error);
      }
    }, 2000);

    return () => {
      isMounted = false;
      clearInterval(interval);
    };
  }, [sessionId]);

  const activeSourcesCount = Array.isArray(activeSources) ? activeSources.length : 0;
  const hasActiveSources = activeSourcesCount > 0;

  const aside = (
    <>
      <div className="editorio-publisher__launch-panel">
        <span className="editorio-publisher__launch-label">
          Fluxo em execução
        </span>
        <strong>Coleta automática</strong>

        <div className="editorio-publisher__stage-list">
          {collectionStages.map((stage, index) => (
            <div
              key={stage.label}
              className={
                index === activeStageIndex
                  ? 'editorio-publisher__stage-item editorio-publisher__stage-item--active'
                  : 'editorio-publisher__stage-item'
              }
            >
              <span className="editorio-publisher__stage-index">
                {index + 1}
              </span>
              <div>
                <strong>{stage.label}</strong>
                <p>{stage.description}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="editorio-publisher__sources-panel">
        <div className="editorio-publisher__sources-panel-header">
          <div>
            <span className="editorio-publisher__launch-label">
              {message('activeSourcesLabel', 'Fontes ativas')}
            </span>
            <h3>
              {message('activeSourcesTitle', 'Fontes que entram na coleta')}
            </h3>
            <p>
              {message(
                'activeSourcesHint',
                'Essas fontes serão usadas quando o processo começar.'
              )}
            </p>
          </div>

          <strong>{activeSourcesLoading ? '...' : activeSourcesCount}</strong>
        </div>

        {activeSourcesLoading ? (
          <div className="editorio-publisher__sources-loading">
            <Spinner />
            <p>
              {message(
                'activeSourcesLoading',
                'Carregando fontes ativas...'
              )}
            </p>
          </div>
        ) : activeSourcesError ? (
          <Notice.Root intent="warning">
            <Notice.Description>{activeSourcesError}</Notice.Description>
          </Notice.Root>
        ) : hasActiveSources ? (
          <div className="editorio-publisher__sources-list">
            {activeSources.map((source) => (
              <span
                key={source.id}
                className="editorio-publisher__source-chip"
                title={source.feed_url}
              >
                {source.name}
              </span>
            ))}
          </div>
        ) : (
          <Notice.Root intent="warning">
            <Notice.Description>
              {message(
                'activeSourcesEmpty',
                'Nenhuma fonte está ativa agora. Ative fontes em Editorio > Fontes antes de iniciar.'
              )}
            </Notice.Description>
          </Notice.Root>
        )}
      </div>
    </>
  );

  if (noSources) {
    return (
      <CollectionShell
        eyebrow="Etapa 1 de 4"
        title="Nenhuma notícia disponível para coleta"
        lead={
          hasActiveSources
            ? 'As fontes estão ativas, mas nenhum item entrou neste ciclo. Verifique se os feeds têm conteúdo recente.'
            : 'Cadastre e ative fontes RSS para que a coleta alimente a curadoria.'
        }
        aside={aside}
      >
        <Notice.Root intent="warning">
          <Notice.Description>
            {hasActiveSources
              ? 'A coleta terminou sem itens novos para processar.'
              : 'Você precisa cadastrar e ativar fontes RSS em Editorio > Fontes antes de iniciar o processo de publicação.'}
          </Notice.Description>
        </Notice.Root>
      </CollectionShell>
    );
  }

  if (!collectorStatus) {
    return (
      <CollectionShell
        eyebrow="Etapa 1 de 4"
        title="Coletando dados das fontes"
        lead="O processo já começou. Estamos preparando os lotes iniciais para a curadoria."
        aside={aside}
      >
        <div className="editorio-publisher__progress-shell">
          <div className="editorio-publisher__progress-header">
            <span>Sincronização inicial</span>
            <strong>--</strong>
          </div>
          <div className="editorio-publisher__loading editorio-publisher__loading--inline">
            <Spinner />
            <p>Montando a base de notícias em lotes pequenos.</p>
          </div>
        </div>
      </CollectionShell>
    );
  }

  const total = collectorStatus.collector_status?.items || 0;
  const collected = collectorStatus.collector_status?.counts?.collected || 0;
  const pending = collectorStatus.collector_status?.queue?.pending || 0;
  const failed = collectorStatus.collector_status?.queue?.failed || 0;
  const progress = total > 0 ? Math.round((collected / total) * 100) : 0;

  return (
    <CollectionShell
      eyebrow="Etapa 1 de 4"
      title="Coletando dados das fontes"
      lead="A coleta roda em lotes pequenos para manter o fluxo leve e preparar a curadoria em seguida."
      aside={aside}
    >
      <div className="editorio-publisher__progress-shell">
        <div className="editorio-publisher__progress-header">
          <span>Progresso da coleta</span>
          <strong>{progress}%</strong>
        </div>
        <div className="editorio-publisher__progress">
          <div
            className="editorio-publisher__progress-bar"
            style={{ width: `${progress}%` }}
          />
        </div>
        <p className="editorio-publisher__progress-text">
          {collected} de {total} itens preparados para curadoria.
        </p>
      </div>

      <div className="editorio-publisher__stats">
        <div className="editorio-publisher__stat">
          <span className="editorio-publisher__stat-label">Total</span>
          <span className="editorio-publisher__stat-value">{total}</span>
        </div>
        <div className="editorio-publisher__stat">
          <span className="editorio-publisher__stat-label">Coletados</span>
          <span className="editorio-publisher__stat-value">{collected}</span>
        </div>
        <div className="editorio-publisher__stat">
          <span className="editorio-publisher__stat-label">Pendentes</span>
          <span className="editorio-publisher__stat-value">{pending}</span>
        </div>
        <div className="editorio-publisher__stat">
          <span className="editorio-publisher__stat-label">Erros</span>
          <span className="editorio-publisher__stat-value">{failed}</span>
        </div>
      </div>

      {loading && (
        <div className="editorio-publisher__loading editorio-publisher__loading--inline">
          <Spinner />
          <p>Preparando itens para curadoria...</p>
        </div>
      )}
    </CollectionShell>
  );
};

// Etapa 2: Curadoria sintetizada
const CurationScreen = ({
  sessionId,
  items,
  totalCollected = 0,
  initialSelectedIds = [],
  onSelect,
  onRetry,
  activeSources = [],
  activeSourcesLoading = false,
  activeSourcesError = '',
}) => {
  const [selectedIds, setSelectedIds] = useState([]);
  const [loading, setLoading] = useState(false);
  const [retryLoading, setRetryLoading] = useState(false);

  useEffect(() => {
    const baseIds = Array.isArray(initialSelectedIds) && initialSelectedIds.length > 0
      ? initialSelectedIds
      : items.map((item) => item.id);

    setSelectedIds(
      baseIds
        .map((itemId) => Number(itemId))
        .filter((itemId) => Number.isFinite(itemId) && itemId > 0)
    );
  }, [items, initialSelectedIds]);

  const activeSourcesCount = Array.isArray(activeSources) ? activeSources.length : 0;
  const hasActiveSources = activeSourcesCount > 0;
  const hasAutomaticCuration = items.some((item) => getCuratedStoryMode(item) !== 'ai');
  const automaticItem = items.find((item) => getCuratedStoryMode(item) !== 'ai');
  const automaticError = automaticItem ? getCuratedStoryError(automaticItem) : '';
  const collectedCount = Number(totalCollected) > 0 ? Number(totalCollected) : 0;

  const handleToggle = (itemId) => {
    setSelectedIds((prev) =>
      prev.includes(itemId)
        ? prev.filter((id) => id !== itemId)
        : [...prev, itemId]
    );
  };

  const handleContinue = async () => {
    setLoading(true);
    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/select-items`),
        method: 'POST',
        data: { item_ids: selectedIds },
      });
      onSelect({
        ...result,
        items,
        selected_item_ids: selectedIds,
        selected_items: result.selected_items || items.filter((item) => selectedIds.includes(Number(item.id))),
      });
    } catch (error) {
      console.error('Erro ao selecionar items:', error);
    }
    setLoading(false);
  };

  const handleRetryCuration = async () => {
    if (!onRetry) {
      return;
    }

    setRetryLoading(true);
    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/retry-curation`),
        method: 'POST',
      });

      onRetry({ ...result, items: result.items || [] });
    } catch (error) {
      console.error('Erro ao refazer curadoria com IA:', error);
    }
    setRetryLoading(false);
  };

  const aside = (
    <>
      <div className="editorio-publisher__launch-panel">
        <span className="editorio-publisher__launch-label">
          Curadoria em IA
        </span>
        <strong>Pautas sintetizadas</strong>

        <div className="editorio-publisher__stage-list">
          {collectionStages.map((stage, index) => (
            <div
              key={stage.label}
              className={
                index === 1
                  ? 'editorio-publisher__stage-item editorio-publisher__stage-item--active'
                  : 'editorio-publisher__stage-item'
              }
            >
              <span className="editorio-publisher__stage-index">
                {index + 1}
              </span>
              <div>
                <strong>{stage.label}</strong>
                <p>{stage.description}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="editorio-publisher__sources-panel">
        <div className="editorio-publisher__sources-panel-header">
          <div>
            <span className="editorio-publisher__launch-label">
              {message('activeSourcesLabel', 'Fontes ativas')}
            </span>
            <h3>
              {message('activeSourcesTitle', 'Fontes usadas na curadoria')}
            </h3>
            <p>
              {message(
                'activeSourcesHint',
                'As pautas geradas pela IA se apoiam nestas fontes ativas.'
              )}
            </p>
          </div>

          <strong>{activeSourcesLoading ? '...' : activeSourcesCount}</strong>
        </div>

        {activeSourcesLoading ? (
          <div className="editorio-publisher__sources-loading">
            <Spinner />
            <p>
              {message(
                'activeSourcesLoading',
                'Carregando fontes ativas...'
              )}
            </p>
          </div>
        ) : activeSourcesError ? (
          <Notice.Root intent="warning">
            <Notice.Description>{activeSourcesError}</Notice.Description>
          </Notice.Root>
        ) : hasActiveSources ? (
          <div className="editorio-publisher__sources-list">
            {activeSources.map((source) => (
              <span
                key={source.id}
                className="editorio-publisher__source-chip"
                title={source.feed_url}
              >
                {source.name}
              </span>
            ))}
          </div>
        ) : (
          <Notice.Root intent="warning">
            <Notice.Description>
              {message(
                'activeSourcesEmpty',
                'Nenhuma fonte está ativa agora. Ative fontes em Editorio > Fontes antes de iniciar.'
              )}
            </Notice.Description>
          </Notice.Root>
        )}
      </div>
    </>
  );

  if (items.length === 0) {
    return (
      <CollectionShell
        activeStageIndex={1}
        eyebrow="Etapa 2 de 4"
        title="Nenhuma pauta sintetizada"
        lead="A IA não conseguiu consolidar itens suficientes em uma pauta nova para seguir para revisão."
        aside={aside}
      >
        <Notice.Root intent="warning">
          <Notice.Description>
            Não foi possível gerar pautas sintetizadas com base nos itens coletados.
          </Notice.Description>
        </Notice.Root>
      </CollectionShell>
    );
  }

  return (
    <CollectionShell
      activeStageIndex={1}
      eyebrow="Etapa 2 de 4"
      title="Pautas sintetizadas pela IA"
      lead="A IA agrupa matérias relacionadas, cria um novo título editorial e mostra quais fontes sustentam cada pauta."
      aside={aside}
    >
      <Notice.Root intent="info">
        <Notice.Description>
          Esta etapa já entrega pautas novas. Você pode revisar o texto sintetizado e as fontes citadas em cada card antes de seguir para revisão.
        </Notice.Description>
      </Notice.Root>

      {collectedCount > 0 ? (
        <Notice.Root intent="info">
          <Notice.Description>
            Foram coletadas {collectedCount} notícia(s) nesta sessão. A IA consolidou esse material em {items.length} pauta(s).
          </Notice.Description>
        </Notice.Root>
      ) : null}

      {hasAutomaticCuration && automaticError ? (
        <Notice.Root intent="warning">
          <Notice.Description>{automaticError}</Notice.Description>
        </Notice.Root>
      ) : null}

      <div className="editorio-publisher__curation-grid">
        {items.map((item, index) => {
          const sources = getCuratedStorySources(item);
          const title = getCuratedStoryTitle(item);
          const reason = getCuratedStoryReason(item);
          const generatedContent = getCuratedStoryContent(item);
          const curationMode = getCuratedStoryMode(item);
          const itemId = Number(item.id);

          return (
            <Card.Root key={itemId > 0 ? itemId : `${title}-${index}`} className="editorio-publisher__curation-card">
              <Card.Content>
                <label className="editorio-publisher__curation-select">
                  <input
                    type="checkbox"
                    checked={selectedIds.includes(itemId)}
                    onChange={() => handleToggle(itemId)}
                  />
                  <div>
                    <span className="editorio-publisher__launch-label">
                      Pauta sintetizada
                    </span>
                    <h3>{title}</h3>
                  </div>
                </label>

                <div className="editorio-publisher__curation-status-row">
                  <span
                    className={
                      curationMode === 'ai'
                        ? 'editorio-publisher__curation-status editorio-publisher__curation-status--ai'
                        : 'editorio-publisher__curation-status editorio-publisher__curation-status--automatic'
                    }
                  >
                    {curationMode === 'ai' ? 'Curadoria com IA' : 'Curadoria automática'}
                  </span>
                </div>

                {generatedContent ? (
                  <div className="editorio-publisher__review-content" dangerouslySetInnerHTML={{ __html: generatedContent }} />
                ) : (
                  <>
                    {reason ? (
                      <p className="editorio-publisher__curation-reason">{reason}</p>
                    ) : null}

                    {sources.length > 0 ? (
                      <div className="editorio-publisher__curation-sources">
                        <span className="editorio-publisher__curation-sources-label">
                          Fontes usadas
                        </span>
                        <div className="editorio-publisher__curation-source-list">
                          {sources.map((source) => (
                            <span
                              key={`${item.id}-${source.workflow_item_id || source.id}`}
                              className="editorio-publisher__source-chip editorio-publisher__source-chip--soft"
                              title={source.content_url || source.source_name || source.title}
                            >
                              {getCuratedSourceLabel(source)}
                            </span>
                          ))}
                        </div>
                      </div>
                    ) : null}
                  </>
                )}
              </Card.Content>
            </Card.Root>
          );
        })}
      </div>

      {hasAutomaticCuration ? (
        <div className="editorio-publisher__curation-banner editorio-publisher__curation-banner--automatic">
          <div>
            <span className="editorio-publisher__curation-banner-label">
              Curadoria automática
            </span>
            <p>
              A IA não pôde ser usada nesta rodada. A seleção foi feita de modo automático.
            </p>
          </div>
        </div>
      ) : (
        <div className="editorio-publisher__curation-banner editorio-publisher__curation-banner--ai">
          <div>
            <span className="editorio-publisher__curation-banner-label">
              Curadoria com IA
            </span>
            <p>
              A curadoria desta rodada foi gerada com o provedor configurado.
            </p>
          </div>
        </div>
      )}

      <div className="editorio-publisher__actions editorio-publisher__actions--curation">
        <Button
          variant="secondary"
          onClick={handleRetryCuration}
          disabled={retryLoading}
          isBusy={retryLoading}
        >
          Refazer curadoria com IA
        </Button>
        <Button
          variant="primary"
          onClick={handleContinue}
          disabled={selectedIds.length === 0 || loading}
          isBusy={loading}
        >
          Revisar {selectedIds.length} pauta(s) gerada(s)
        </Button>
      </div>
    </CollectionShell>
  );
};

// Etapa 3: Revisão e aprovação
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

        <Card.Root className="editorio-publisher__review-card">
          <Card.Content>
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
            <Notice.Root intent="warning">
              <Notice.Description>{reviewError}</Notice.Description>
            </Notice.Root>
          ) : null}

          {hasSavedReviewDraft(item) ? (
            <Notice.Root intent="info">
              <Notice.Description>
                Esta pauta já foi {item.approval_status === 'approved' ? 'aprovada' : 'rejeitada'}. Você pode revisar, editar e enviar uma nova decisão.
              </Notice.Description>
            </Notice.Root>
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

            <section className={getFieldSectionClass('categories', 'editorio-publisher__mockup-section--meta')}>
              <div className="editorio-publisher__mockup-section-header">
                <span>Categorias sugeridas</span>
                {renderFieldActions('categories')}
              </div>
              {renderFieldLoading('categories')}
              {isEditing('categories') ? (
                <input
                  className="editorio-publisher__mockup-input"
                  value={normalizeList(draft.categories_suggested).join(', ')}
                  onChange={(event) => updateDraft({ categories_suggested: event.target.value })}
                />
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

            <section className={getFieldSectionClass('content')}>
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
          </Card.Content>
        </Card.Root>
      </div>
    </Page>
  );
};

// Etapa 4: Confirmação Final
const ConfirmationScreen = ({ sessionId, summary, onConfirm, onBack }) => {
  const [loading, setLoading] = useState(false);
  const [confirmationError, setConfirmationError] = useState('');
  const approvedItems = Array.isArray(summary?.approved_items) ? summary.approved_items : [];
  const [itemActions, setItemActions] = useState({});

  useEffect(() => {
    setItemActions((prev) => {
      const next = { ...prev };
      approvedItems.forEach((item) => {
        if (!next[item.id]) {
          next[item.id] = { action: 'draft', scheduled_at: '' };
        }
      });

      return next;
    });
  }, [summary]);

  const updateFinalAction = (itemId, patch) => {
    setItemActions((prev) => ({
      ...prev,
      [itemId]: {
        ...(prev[itemId] || { action: 'draft', scheduled_at: '' }),
        ...patch,
      },
    }));
  };

  const hasInvalidSchedule = approvedItems.some((item) => (
    itemActions[item.id]?.action === 'schedule' && !itemActions[item.id]?.scheduled_at
  ));

  const handleConfirm = async () => {
    if (hasInvalidSchedule) {
      setConfirmationError('Informe data e hora para todas as notícias agendadas.');
      return;
    }

    setLoading(true);
    setConfirmationError('');
    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/save-drafts`),
        method: 'POST',
        data: {
          items: approvedItems.map((item) => ({
            item_id: Number(item.id),
            action: itemActions[item.id]?.action || 'draft',
            scheduled_at: itemActions[item.id]?.scheduled_at || '',
          })),
        },
      });
      onConfirm(result);
    } catch (error) {
      setConfirmationError(error?.message || 'Não foi possível finalizar a publicação.');
    }
    setLoading(false);
  };

  return (
    <CollectionShell
      activeStageIndex={3}
      eyebrow="Etapa 4 de 4"
      title="Confirmação final"
      lead="Revise as notícias aprovadas e defina se cada uma será publicada, salva como rascunho, agendada ou descartada."
    >
          <div className="editorio-publisher__summary-stats">
            <div className="editorio-publisher__stat">
              <span className="editorio-publisher__stat-label">Aprovadas</span>
              <span className="editorio-publisher__stat-value">
                {summary?.approved || 0}
              </span>
            </div>
            <div className="editorio-publisher__stat">
              <span className="editorio-publisher__stat-label">Rejeitadas</span>
              <span className="editorio-publisher__stat-value">
                {summary?.rejected || 0}
              </span>
            </div>
          </div>

          <Notice.Root intent="info">
            <Notice.Description>
              Defina o destino de cada notícia aprovada antes de finalizar.
            </Notice.Description>
          </Notice.Root>

          {confirmationError ? (
            <Notice.Root intent="warning">
              <Notice.Description>{confirmationError}</Notice.Description>
            </Notice.Root>
          ) : null}

          {hasInvalidSchedule ? (
            <Notice.Root intent="warning">
              <Notice.Description>
                Há notícia marcada para agendamento sem data e hora.
              </Notice.Description>
            </Notice.Root>
          ) : null}

          <div className="editorio-publisher__final-item-list">
            {approvedItems.map((item) => {
              const action = itemActions[item.id]?.action || 'draft';

              return (
                <div key={item.id} className="editorio-publisher__final-item">
                  <div className="editorio-publisher__final-item-copy">
                    <span>Notícia aprovada</span>
                    <strong>{item.generated_title || item.title || 'Sem título'}</strong>
                  </div>
                  <div className="editorio-publisher__final-item-controls">
                    <select
                      value={action}
                      onChange={(event) => updateFinalAction(item.id, { action: event.target.value })}
                      disabled={loading}
                    >
                      <option value="draft">Salvar como rascunho</option>
                      <option value="publish">Publicar agora</option>
                      <option value="schedule">Agendar</option>
                      <option value="exclude">Excluir</option>
                    </select>
                    {action === 'schedule' ? (
                      <input
                        type="datetime-local"
                        value={itemActions[item.id]?.scheduled_at || ''}
                        onChange={(event) => updateFinalAction(item.id, { scheduled_at: event.target.value })}
                        disabled={loading}
                      />
                    ) : null}
                  </div>
                </div>
              );
            })}
          </div>

          <div className="editorio-publisher__confirmation-actions">
            <Button
              variant="secondary"
              className="editorio-publisher__confirmation-button editorio-publisher__confirmation-button--back"
              onClick={onBack}
              disabled={loading}
            >
              <span className="dashicons dashicons-arrow-left-alt2" aria-hidden="true" />
              Voltar para revisão
            </Button>
            <Button
              variant="primary"
              className="editorio-publisher__confirmation-button editorio-publisher__confirmation-button--finish"
              onClick={handleConfirm}
              disabled={loading || approvedItems.length === 0 || hasInvalidSchedule}
              isBusy={loading}
            >
              <span className="dashicons dashicons-yes" aria-hidden="true" />
              Finalizar publicação
            </Button>
          </div>
    </CollectionShell>
  );
};

// Etapa 5: Conclusão
const CompletionScreen = ({ summary, createdPosts = [], failedPosts = [] }) => {
  const actionLabel = (action) => ({
    publish: 'Publicada',
    draft: 'Rascunho',
    schedule: 'Agendada',
    exclude: 'Excluída',
  }[action] || action);

  return (
    <CollectionShell
      eyebrow="Fluxo concluído"
      title="Resumo da publicação"
      lead="Confira o resultado final das ações executadas para esta sessão editorial."
    >
      <div className="editorio-publisher__completion-hero">
        <span className="dashicons dashicons-yes" aria-hidden="true" />
        <div>
          <strong>Publicação finalizada</strong>
          <p>
            {summary?.created || 0} post(s) criado(s), {summary?.excluded || 0} notícia(s) descartada(s).
          </p>
        </div>
      </div>

      <div className="editorio-publisher__completion-stats">
        <div>
          <span>Total</span>
          <strong>{summary?.total || 0}</strong>
        </div>
        <div>
          <span>Publicadas</span>
          <strong>{summary?.published || 0}</strong>
        </div>
        <div>
          <span>Rascunhos</span>
          <strong>{summary?.drafted || 0}</strong>
        </div>
        <div>
          <span>Agendadas</span>
          <strong>{summary?.scheduled || 0}</strong>
        </div>
        <div>
          <span>Excluídas</span>
          <strong>{summary?.excluded || 0}</strong>
        </div>
      </div>

      {createdPosts.length > 0 ? (
        <div className="editorio-publisher__completion-list">
          <h3>Posts criados</h3>
          {createdPosts.map((post) => (
            <div key={post.post_id} className="editorio-publisher__completion-item">
              <div>
                <strong>{post.title}</strong>
                <small>ID #{post.post_id}</small>
              </div>
              <span>{actionLabel(post.action)}</span>
            </div>
          ))}
        </div>
      ) : null}

      {failedPosts.length > 0 ? (
        <Notice.Root intent="warning">
          <Notice.Description>
            {failedPosts.length} notícia(s) não foram processadas. Verifique as datas de agendamento ou permissões do WordPress.
          </Notice.Description>
        </Notice.Root>
      ) : null}
    </CollectionShell>
  );
};

// Componente Principal
const Publisher = () => {
  const [sessionId, setSessionId] = useState(null);
  const [stage, setStage] = useState('idle');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [activeSources, setActiveSources] = useState([]);
  const [activeSourcesLoading, setActiveSourcesLoading] = useState(true);
  const [activeSourcesError, setActiveSourcesError] = useState('');
  const [resumeError, setResumeError] = useState('');
  const [recentWorkflows, setRecentWorkflows] = useState([]);
  const [recentWorkflowsLoading, setRecentWorkflowsLoading] = useState(true);

  useEffect(() => {
    let isMounted = true;

    const loadActiveSources = async () => {
      setActiveSourcesLoading(true);
      setActiveSourcesError('');

      try {
        const response = await apiFetch({
          path: sourcesEndpoint('?is_active=1'),
        });

        if (isMounted) {
          setActiveSources(Array.isArray(response) ? response : []);
        }
      } catch (error) {
        if (isMounted) {
          setActiveSourcesError(
            error?.message ||
              message(
                'activeSourcesLoadError',
                'Não foi possível carregar as fontes ativas.'
              )
          );
        }
      } finally {
        if (isMounted) {
          setActiveSourcesLoading(false);
        }
      }
    };

    void loadActiveSources();

    return () => {
      isMounted = false;
    };
  }, []);

  const hydrateWorkflow = (result, fallbackSessionId = '') => {
    const nextSessionId = result.session_id || fallbackSessionId;
    setSessionId(nextSessionId);
    setStage(normalizeWorkflowStage(result.stage));
    setData(result.data || {});
    writeSessionToUrl(nextSessionId);
  };

  const resumeWorkflowById = async (workflowSessionId) => {
    if (!workflowSessionId) {
      return;
    }

    setLoading(true);
    setResumeError('');

    try {
      const result = await apiFetch({
        path: publisherEndpoint(`/workflow/${encodeURIComponent(workflowSessionId)}/resume`),
      });

      hydrateWorkflow(result, workflowSessionId);
    } catch (error) {
      setResumeError(error?.message || 'Não foi possível retomar esta execução.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    let isMounted = true;

    const loadRecentWorkflows = async () => {
      setRecentWorkflowsLoading(true);

      try {
        const result = await apiFetch({
          path: publisherEndpoint('/workflows?limit=8'),
        });

        if (isMounted) {
          setRecentWorkflows(Array.isArray(result?.items) ? result.items : []);
        }
      } catch (error) {
        if (isMounted) {
          setRecentWorkflows([]);
        }
      } finally {
        if (isMounted) {
          setRecentWorkflowsLoading(false);
        }
      }
    };

    void loadRecentWorkflows();

    return () => {
      isMounted = false;
    };
  }, []);

  useEffect(() => {
    const resumeSessionId = getSessionFromUrl();
    if (!resumeSessionId) {
      return undefined;
    }

    let isMounted = true;

    const resumeWorkflow = async () => {
      setLoading(true);
      setResumeError('');

      try {
        const result = await apiFetch({
          path: publisherEndpoint(`/workflow/${encodeURIComponent(resumeSessionId)}/resume`),
        });

        if (!isMounted) {
          return;
        }

        hydrateWorkflow(result, resumeSessionId);
      } catch (error) {
        if (isMounted) {
          setResumeError(error?.message || 'Não foi possível retomar o processo pela URL.');
          setStage('idle');
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    void resumeWorkflow();

    return () => {
      isMounted = false;
    };
  }, []);

  const handleStartProcess = async () => {
    setLoading(true);
    setResumeError('');
    try {
      const result = await apiFetch({
        path: publisherEndpoint('/start'),
        method: 'POST',
      });
      setSessionId(result.session_id);
      writeSessionToUrl(result.session_id);
      setStage('collecting');
      setData(result);
    } catch (error) {
      console.error('Erro ao iniciar processo:', error);
      setResumeError(error?.message || 'Não foi possível iniciar o processo.');
    }
    setLoading(false);
  };

  if (stage === 'idle') {
    return (
      <Page className="editorio-publisher-page">
        <Card.Root
          className={
            loading
              ? 'editorio-publisher__launch-card editorio-publisher__launch-card--loading'
              : 'editorio-publisher__launch-card'
          }
        >
          <Card.Content>
            <div className="editorio-publisher__init">
              <div className="editorio-publisher__init-copy">
                <span className="editorio-publisher__eyebrow">
                  Fluxo editorial assistido
                </span>
                <h2>Publicar Notícias</h2>
                <p className="editorio-publisher__lead">
                  Inicie o processo para coletar itens das fontes ativas,
                  selecionar os melhores com IA e revisar tudo antes de virar
                  rascunho no WordPress.
                </p>

                <ul className="editorio-publisher__feature-list">
                  <li>Coleta automática das fontes habilitadas</li>
                  <li>Curadoria com IA para priorizar os melhores itens</li>
                  <li>Revisão final antes de salvar como rascunho</li>
                </ul>

                <div className="editorio-publisher__actions editorio-publisher__actions--primary">
                  <Button
                    variant="primary"
                    onClick={handleStartProcess}
                    disabled={loading}
                    isBusy={loading}
                  >
                    Iniciar Processo
                  </Button>
                </div>

                {resumeError ? (
                  <Notice.Root intent="warning">
                    <Notice.Description>{resumeError}</Notice.Description>
                  </Notice.Root>
                ) : null}

                <div className="editorio-publisher__recent-workflows">
                  <div className="editorio-publisher__recent-workflows-header">
                    <span className="editorio-publisher__launch-label">
                      Últimas execuções
                    </span>
                    <strong>
                      {recentWorkflowsLoading ? '...' : recentWorkflows.length}
                    </strong>
                  </div>

                  {recentWorkflowsLoading ? (
                    <div className="editorio-publisher__sources-loading">
                      <Spinner />
                      <p>Carregando execuções...</p>
                    </div>
                  ) : recentWorkflows.length > 0 ? (
                    <div className="editorio-publisher__recent-workflow-list">
                      {recentWorkflows.map((workflow) => {
                        const workflowStage = normalizeWorkflowStage(workflow.stage);

                        return (
                          <button
                            key={workflow.session_id}
                            type="button"
                            className={
                              workflow.is_finished
                                ? 'editorio-publisher__recent-workflow editorio-publisher__recent-workflow--finished'
                                : 'editorio-publisher__recent-workflow'
                            }
                            onClick={() => resumeWorkflowById(workflow.session_id)}
                            disabled={loading}
                          >
                            <span className="editorio-publisher__recent-workflow-main">
                              <strong>{stageLabels[workflowStage] || workflowStage}</strong>
                              <small>
                                Atualizada em {workflow.updated_at || workflow.created_at}
                              </small>
                            </span>
                            <span className="editorio-publisher__recent-workflow-counts">
                              {workflow.collected_count || 0} coletadas
                              {' · '}
                              {workflow.approved_count || 0} aprovadas
                            </span>
                            <span className="editorio-publisher__recent-workflow-action">
                              {workflow.is_finished ? 'Ver resumo' : 'Continuar'}
                            </span>
                          </button>
                        );
                      })}
                    </div>
                  ) : (
                    <p className="editorio-publisher__recent-workflows-empty">
                      Nenhuma execução recente encontrada.
                    </p>
                  )}
                </div>
              </div>

              <div className="editorio-publisher__launch-aside">
                <div className="editorio-publisher__launch-panel">
                  <span className="editorio-publisher__launch-label">
                    Etapas do fluxo
                  </span>
                  <strong>1. Coleta</strong>
                  <strong>2. Curadoria</strong>
                  <strong>3. Revisão</strong>
                  <strong>4. Rascunho</strong>
                </div>

                <div className="editorio-publisher__sources-panel">
                  <div className="editorio-publisher__sources-panel-header">
                    <div>
                      <span className="editorio-publisher__launch-label">
                        { message(
                          'activeSourcesLabel',
                          'Fontes ativas'
                        ) }
                      </span>
                      <h3>
                        { message(
                          'activeSourcesTitle',
                          'Fontes que entram na coleta'
                        ) }
                      </h3>
                      <p>
                        { message(
                          'activeSourcesHint',
                          'Essas fontes serão usadas quando o processo começar.'
                        ) }
                      </p>
                    </div>
                    <strong>
                      { activeSourcesLoading ? '...' : activeSources.length }
                    </strong>
                  </div>

                  {activeSourcesLoading ? (
                    <div className="editorio-publisher__sources-loading">
                      <Spinner />
                      <p>
                        { message(
                          'activeSourcesLoading',
                          'Carregando fontes ativas...'
                        ) }
                      </p>
                    </div>
                  ) : activeSourcesError ? (
                    <Notice.Root intent="warning">
                      <Notice.Description>{activeSourcesError}</Notice.Description>
                    </Notice.Root>
                  ) : activeSources.length > 0 ? (
                    <div className="editorio-publisher__sources-list">
                      {activeSources.map((source) => (
                        <span
                          key={source.id}
                          className="editorio-publisher__source-chip"
                          title={source.feed_url}
                        >
                          {source.name}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <Notice.Root intent="warning">
                      <Notice.Description>
                        { message(
                          'activeSourcesEmpty',
                          'Nenhuma fonte está ativa agora. Ative fontes em Editorio > Fontes antes de iniciar.'
                        ) }
                      </Notice.Description>
                    </Notice.Root>
                  )}
                </div>
              </div>
            </div>
          </Card.Content>

          {loading ? (
            <div className="editorio-publisher__launch-overlay" aria-live="polite" aria-busy="true">
              <div className="editorio-publisher__launch-overlay-box">
                <Spinner />
                <strong>Iniciando processo</strong>
                <p>
                  Coletando feeds ativos e preparando itens para curadoria.
                </p>
              </div>
            </div>
          ) : null}
        </Card.Root>
      </Page>
    );
  }

  if (stage === 'collecting') {
    return (
      <CollectionScreen
        sessionId={sessionId}
        activeSources={activeSources}
        activeSourcesLoading={activeSourcesLoading}
        activeSourcesError={activeSourcesError}
        onComplete={(result) => {
          setStage('curating');
          setData(result);
          writeSessionToUrl(sessionId);
        }}
      />
    );
  }

  if (stage === 'curating') {
    return (
        <CurationScreen
        sessionId={sessionId}
        items={data?.items || []}
        totalCollected={data?.total_items || data?.items?.length || 0}
        initialSelectedIds={data?.selected_item_ids || []}
        activeSources={activeSources}
        activeSourcesLoading={activeSourcesLoading}
        activeSourcesError={activeSourcesError}
        onSelect={(result) => {
          setStage('reviewing');
          setData(result);
          writeSessionToUrl(sessionId);
        }}
        onRetry={(result) => {
          setData(result);
        }}
      />
    );
  }

  if (stage === 'reviewing') {
    return (
      <ReviewScreen
        sessionId={sessionId}
        items={data?.selected_items || []}
        onComplete={(result) => {
          setStage('confirming');
          setData((currentData) => ({
            ...(currentData || {}),
            ...(result || {}),
            summary: result?.summary || currentData?.summary,
          }));
          writeSessionToUrl(sessionId);
        }}
        onBack={() => {
          setStage('curating');
        }}
      />
    );
  }

  if (stage === 'confirming') {
    return (
      <ConfirmationScreen
        sessionId={sessionId}
        summary={data?.summary}
        onBack={() => {
          setStage('reviewing');
        }}
        onConfirm={(result) => {
          setStage('completed');
          setData((currentData) => ({
            ...(currentData || {}),
            ...(result || {}),
            summary: result?.summary || currentData?.summary,
          }));
          writeSessionToUrl(sessionId);
        }}
      />
    );
  }

  if (stage === 'completed') {
    return (
      <CompletionScreen
        summary={data?.summary}
        createdPosts={data?.created_posts || []}
        failedPosts={data?.failed_posts || []}
      />
    );
  }

  return <Spinner />;
};

// Mount the app
domReady( () => {
const container = document.getElementById( 'editorio-publisher-root' );
if ( ! container ) {
return;
}

createRoot( container ).render( <Publisher /> );
} );
