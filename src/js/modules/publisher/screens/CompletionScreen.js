import {Notice} from '@wordpress/ui';
import CollectionShell from '../components/CollectionShell';

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
              <div className="editorio-publisher__completion-item-copy">
                <strong>{post.title}</strong>
                <small>ID #{post.post_id}</small>
              </div>
              <div className="editorio-publisher__completion-item-actions">
                <span>{actionLabel(post.action)}</span>
                {post.view_url ? (
                  <a
                    className="editorio-publisher__completion-icon-button"
                    href={post.view_url}
                    target="_blank"
                    rel="noreferrer"
                    aria-label="Ver notícia"
                    title="Ver notícia"
                  >
                    <span className="dashicons dashicons-visibility" aria-hidden="true" />
                  </a>
                ) : null}
                {post.edit_url ? (
                  <a
                    className="editorio-publisher__completion-icon-button"
                    href={post.edit_url}
                    target="_blank"
                    rel="noreferrer"
                    aria-label="Editar notícia"
                    title="Editar notícia"
                  >
                    <span className="dashicons dashicons-edit" aria-hidden="true" />
                  </a>
                ) : null}
              </div>
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


export default CompletionScreen;
