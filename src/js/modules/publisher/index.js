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
          <div className="editorio-publisher__collection">
            <div className="editorio-publisher__collection-main">
              <span className="editorio-publisher__eyebrow">{eyebrow}</span>
              <h2>{title}</h2>
              <p className="editorio-publisher__lead">{lead}</p>
              {children}
            </div>

            <div className="editorio-publisher__launch-aside">{aside}</div>
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
  const [currentIndex, setCurrentIndex] = useState(0);
  const [approvals, setApprovals] = useState({});
  const [loading, setLoading] = useState(false);

  const handleApprove = async (approved) => {
    setLoading(true);
    try {
      const item = items[currentIndex];
      const generatedContent = getCuratedStoryContent(item);
      await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/approve-item`),
        method: 'POST',
        data: {
          item_id: item.id,
          approved,
          generated_title: getCuratedStoryTitle(item),
          generated_content: generatedContent,
        },
      });

      setApprovals((prev) => ({ ...prev, [item.id]: approved }));
      if (currentIndex < items.length - 1) {
        setCurrentIndex(currentIndex + 1);
      } else {
        onComplete();
      }
    } catch (error) {
      console.error('Erro ao aprovar item:', error);
    }
    setLoading(false);
  };

  if (items.length === 0) {
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

  const item = items[currentIndex];
  const title = getCuratedStoryTitle(item);
  const reason = getCuratedStoryReason(item);
  const sources = getCuratedStorySources(item);
  const generatedContent = getCuratedStoryContent(item);
  const curationMode = getCuratedStoryMode(item);
  const curationError = getCuratedStoryError(item);
  const progress = `Revisando notícia ${currentIndex + 1}/${items.length}`;

  return (
    <Page className="editorio-publisher-page">
      <Card.Root>
        <Card.Content>
          <h2>{progress}</h2>
          <div className="editorio-publisher__review-item">
            <span className="editorio-publisher__launch-label">
              Pauta sintetizada
            </span>
            <h3>{title}</h3>

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

              {curationMode !== 'ai' && curationError ? (
                <span className="editorio-publisher__curation-error">
                  {curationError}
                </span>
              ) : null}
            </div>

            {reason ? (
              <p className="editorio-publisher__review-description">
                {reason}
              </p>
            ) : null}

            {sources.length > 0 ? (
              <div className="editorio-publisher__review-sources">
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

            {generatedContent && (
              <div
                className="editorio-publisher__review-content"
                dangerouslySetInnerHTML={{ __html: generatedContent }}
              />
            )}

            <div className="editorio-publisher__actions">
              <Button
                variant="secondary"
                onClick={onBack}
                disabled={loading}
              >
                Voltar para seleção
              </Button>
              <Button
                variant="secondary"
                onClick={() => handleApprove(false)}
                disabled={loading}
              >
                Rejeitar pauta
              </Button>
              <Button
                variant="primary"
                onClick={() => handleApprove(true)}
                disabled={loading}
                isBusy={loading}
              >
                Aprovar pauta
              </Button>
            </div>
          </div>
        </Card.Content>
      </Card.Root>
    </Page>
  );
};

// Etapa 4: Confirmação Final
const ConfirmationScreen = ({ sessionId, summary, onConfirm }) => {
  const [loading, setLoading] = useState(false);

  const handleConfirm = async () => {
    setLoading(true);
    try {
      await apiFetch({
        path: publisherEndpoint(`/workflow/${sessionId}/save-drafts`),
        method: 'POST',
      });
      onConfirm();
    } catch (error) {
      console.error('Erro ao salvar rascunhos:', error);
    }
    setLoading(false);
  };

  return (
    <Page className="editorio-publisher-page">
      <Card.Root>
        <Card.Content>
          <h2>Confirmação Final</h2>
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
              As notícias aprovadas serão salvas como rascunho em seu WordPress.
            </Notice.Description>
          </Notice.Root>

          <div className="editorio-publisher__actions">
            <Button
              variant="primary"
              onClick={handleConfirm}
              disabled={loading}
              isBusy={loading}
            >
              Confirmar e Salvar Rascunhos
            </Button>
          </div>
        </Card.Content>
      </Card.Root>
    </Page>
  );
};

// Etapa 5: Conclusão
const CompletionScreen = ({ summary }) => {
  return (
    <Page className="editorio-publisher-page">
      <Card.Root>
        <Card.Content>
          <h2>Processo Completo!</h2>
          <div className="editorio-publisher__success-message">
            <div style={{ fontSize: '48px', marginBottom: '16px' }}>✓</div>
            <h3>Publicação finalizada com sucesso!</h3>
            <p>
              {summary?.approved || 0} notícia(s) foram salvas como rascunho.
            </p>
          </div>

          <div className="editorio-publisher__final-stats">
            <div>
              <strong>Total coletado:</strong> {summary?.total || 0}
            </div>
            <div>
              <strong>Aprovado:</strong> {summary?.approved || 0}
            </div>
            <div>
              <strong>Rejeitado:</strong> {summary?.rejected || 0}
            </div>
          </div>
        </Card.Content>
      </Card.Root>
    </Page>
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

  const handleStartProcess = async () => {
    setLoading(true);
    try {
      const result = await apiFetch({
        path: publisherEndpoint('/start'),
        method: 'POST',
      });
      setSessionId(result.session_id);
      setStage('collecting');
      setData(result);
    } catch (error) {
      console.error('Erro ao iniciar processo:', error);
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
        onComplete={() => {
          setStage('confirming');
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
        onConfirm={() => {
          setStage('completed');
        }}
      />
    );
  }

  if (stage === 'completed') {
    return <CompletionScreen summary={data?.summary} />;
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
