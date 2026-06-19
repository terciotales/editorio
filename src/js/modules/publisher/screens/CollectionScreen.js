import { useEffect, useState } from '@wordpress/element';
import { Notice } from '@wordpress/ui';
import { Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import CollectionShell from '../components/CollectionShell';
import { publisherEndpoint } from '../api/endpoints';
import { collectionStages } from '../constants/stages';
import { message } from '../config';

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


export default CollectionScreen;
