import {useEffect, useState} from '@wordpress/element';
import {Button, Notice} from '@wordpress/ui';
import apiFetch from '@wordpress/api-fetch';
import CollectionShell from '../components/CollectionShell';
import {publisherEndpoint} from '../api/endpoints';

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


export default ConfirmationScreen;
