import {useEffect, useState} from '@wordpress/element';
import {Button, Card, Notice} from '@wordpress/ui';
import {Spinner} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import CollectionShell from '../components/CollectionShell';
import {publisherEndpoint} from '../api/endpoints';
import {collectionStages} from '../constants/stages';
import {message} from '../config';
import {
	getCuratedSourceLabel,
	getCuratedStoryContent,
	getCuratedStoryError,
	getCuratedStoryMode,
	getCuratedStoryReason,
	getCuratedStorySources,
	getCuratedStoryTitle,
} from '../utils/review';

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


export default CurationScreen;
