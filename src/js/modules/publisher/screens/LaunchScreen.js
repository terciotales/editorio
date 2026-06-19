import {Button, Card, Notice} from '@wordpress/ui';
import {Spinner} from '@wordpress/components';
import {Page} from '@wordpress/admin-ui';
import {message} from '../config';
import {normalizeWorkflowStage} from '../utils/workflow';
import {stageLabels} from '../constants/stages';

const LaunchScreen = ({
  loading,
  onStart,
  resumeError,
  recentWorkflows,
  recentWorkflowsLoading,
  onResumeWorkflow,
  activeSources,
  activeSourcesLoading,
  activeSourcesError,
}) => {
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
                  onClick={onStart}
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
                          onClick={() => onResumeWorkflow(workflow.session_id)}
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
                      {message(
                        'activeSourcesLabel',
                        'Fontes ativas'
                      )}
                    </span>
                    <h3>
                      {message(
                        'activeSourcesTitle',
                        'Fontes que entram na coleta'
                      )}
                    </h3>
                    <p>
                      {message(
                        'activeSourcesHint',
                        'Essas fontes serão usadas quando o processo começar.'
                      )}
                    </p>
                  </div>
                  <strong>
                    {activeSourcesLoading ? '...' : activeSources.length}
                  </strong>
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
                      {message(
                        'activeSourcesEmpty',
                        'Nenhuma fonte está ativa agora. Ative fontes em Editorio > Fontes antes de iniciar.'
                      )}
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
};

export default LaunchScreen;
