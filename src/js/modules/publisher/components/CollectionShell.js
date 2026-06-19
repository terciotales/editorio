import { Card } from '@wordpress/ui';
import { Page } from '@wordpress/admin-ui';

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

export default CollectionShell;
