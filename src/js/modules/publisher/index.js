import domReady from '@wordpress/dom-ready';
import {createRoot, useEffect, useState} from '@wordpress/element';
import {Spinner} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import '../../../css/modules/publisher/index.scss';
import {config, message, setupPublisherApiFetch} from './config';
import {publisherEndpoint, sourcesEndpoint} from './api/endpoints';
import {getSessionFromUrl, normalizeWorkflowStage, writeSessionToUrl} from './utils/workflow';
import CollectionScreen from './screens/CollectionScreen';
import CurationScreen from './screens/CurationScreen';
import ReviewScreen from './screens/ReviewScreen';
import ConfirmationScreen from './screens/ConfirmationScreen';
import CompletionScreen from './screens/CompletionScreen';
import LaunchScreen from './screens/LaunchScreen';
import UrlRewriteScreen from './screens/UrlRewriteScreen';

setupPublisherApiFetch();

const PublisherWorkflow = () => {
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
      <LaunchScreen
        loading={loading}
        onStart={handleStartProcess}
        resumeError={resumeError}
        recentWorkflows={recentWorkflows}
        recentWorkflowsLoading={recentWorkflowsLoading}
        onResumeWorkflow={resumeWorkflowById}
        activeSources={activeSources}
        activeSourcesLoading={activeSourcesLoading}
        activeSourcesError={activeSourcesError}
      />
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

const Publisher = () => (
  config.screenMode === 'url-rewrite'
    ? <UrlRewriteScreen />
    : <PublisherWorkflow />
);

domReady(() => {
  const container = document.getElementById('editorio-publisher-root');
  if (!container) {
    return;
  }

  createRoot(container).render(<Publisher />);
});
