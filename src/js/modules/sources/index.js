import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import {createRoot, useEffect, useState} from '@wordpress/element';
import '../../../css/modules/sources/index.scss';

const config = window.editorioSourcesConfig || {restNamespace: '/editorio/v1', nonce: ''};

if (config.nonce) {
	apiFetch.use((options, next) => {
		const headers = {
			...(options.headers || {}),
			'X-WP-Nonce': config.nonce,
		};

		return next({...options, headers});
	});
}

const endpoint = (path = '') => `${config.restNamespace}/sources${path}`;

function SourcesApp() {
	const [items, setItems] = useState([]);
	const [loading, setLoading] = useState(true);
	const [submitting, setSubmitting] = useState(false);
	const [error, setError] = useState('');
	const [editingId, setEditingId] = useState(null);
	const [form, setForm] = useState({
		name: '',
		feed_url: '',
		is_active: true,
	});

	const load = async () => {
		setLoading(true);
		setError('');

		try {
			const response = await apiFetch({path: endpoint()});
			setItems(Array.isArray(response) ? response : []);
		} catch (err) {
			setError(err?.message || 'Nao foi possivel carregar as fontes.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		load();
	}, []);

	const resetForm = () => {
		setEditingId(null);
		setForm({name: '', feed_url: '', is_active: true});
	};

	const onSubmit = async (event) => {
		event.preventDefault();
		setSubmitting(true);
		setError('');

		try {
			if (editingId) {
				await apiFetch({
					path: endpoint(`/${editingId}`),
					method: 'PUT',
					data: form,
				});
			} else {
				await apiFetch({
					path: endpoint(),
					method: 'POST',
					data: form,
				});
			}

			resetForm();
			await load();
		} catch (err) {
			setError(err?.message || 'Nao foi possivel salvar a fonte.');
		} finally {
			setSubmitting(false);
		}
	};

	const onEdit = (item) => {
		setEditingId(item.id);
		setForm({
			name: item.name || '',
			feed_url: item.feed_url || '',
			is_active: !!item.is_active,
		});
	};

	const onDelete = async (id) => {
		if (!window.confirm('Tem certeza que deseja excluir esta fonte?')) {
			return;
		}

		setError('');

		try {
			await apiFetch({
				path: endpoint(`/${id}`),
				method: 'DELETE',
			});

			if (editingId === id) {
				resetForm();
			}

			await load();
		} catch (err) {
			setError(err?.message || 'Nao foi possivel excluir a fonte.');
		}
	};

	return (
		<div className="editorio-sources">
			<form className="editorio-sources__form" onSubmit={onSubmit}>
				<h2>{editingId ? 'Editar fonte' : 'Nova fonte'}</h2>

				<label>
					Nome
					<input
						type="text"
						value={form.name}
						onChange={(event) => setForm({...form, name: event.target.value})}
						required
					/>
				</label>

				<label>
					Feed URL
					<input
						type="url"
						value={form.feed_url}
						onChange={(event) => setForm({...form, feed_url: event.target.value})}
						required
					/>
				</label>

				<label className="editorio-sources__checkbox">
					<input
						type="checkbox"
						checked={form.is_active}
						onChange={(event) => setForm({...form, is_active: event.target.checked})}
					/>
					Ativa
				</label>

				<div className="editorio-sources__actions">
					<button type="submit" className="button button-primary" disabled={submitting}>
						{submitting ? 'Salvando...' : editingId ? 'Atualizar' : 'Criar'}
					</button>
					{editingId ? (
						<button type="button" className="button" onClick={resetForm}>
							Cancelar
						</button>
					) : null}
				</div>
			</form>

			<section className="editorio-sources__list">
				<h2>Fontes cadastradas</h2>

				{error ? <p className="editorio-sources__error">{error}</p> : null}

				{loading ? <p>Carregando...</p> : null}

				{!loading && items.length === 0 ? <p>Nenhuma fonte cadastrada ainda.</p> : null}

				{!loading && items.length > 0 ? (
					<table className="widefat striped">
						<thead>
						<tr>
							<th>ID</th>
							<th>Nome</th>
							<th>Feed URL</th>
							<th>Status</th>
							<th>Acoes</th>
						</tr>
						</thead>
						<tbody>
						{items.map((item) => (
							<tr key={item.id}>
								<td>{item.id}</td>
								<td>{item.name}</td>
								<td>{item.feed_url}</td>
								<td>{item.is_active ? 'Ativa' : 'Inativa'}</td>
								<td className="editorio-sources__table-actions">
									<button type="button" className="button button-small" onClick={() => onEdit(item)}>
										Editar
									</button>
									<button
										type="button"
										className="button button-small"
										onClick={() => onDelete(item.id)}
									>
										Excluir
									</button>
								</td>
							</tr>
						))}
						</tbody>
					</table>
				) : null}
			</section>
		</div>
	);
}

domReady(() => {
	const container = document.getElementById('editorio-sources-app');
	if (!container) {
		return;
	}

	createRoot(container).render(<SourcesApp/>);
});
