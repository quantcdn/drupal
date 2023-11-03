# Quant cache tag purger

Adds a cache tag plugin which listens to Drupal invalidation events in order to
queue Quant updates for related content.

For example, this allows node edits to trigger the main (`/node`) page to update
along with any other pages associated with the node through cache tags (e.g.
views pages, taxonomy term pages, etc).

This also works with other entities. For example, if a term is associated with
several nodes, those nodes will be queued for updates when the term is edited.

The associated content is added to the Quant queue and will be processed during
the next core cron run. You can run the cron manually or wait for the site's
cron to run on its regular schedule. For the latter, note that the static
content will be out-of-sync with the Drupal site until the cron runs, which may
cause confusion in some cases. Thus, it is recommended that cron is run right
after content is edited if there are key pages that show the updated content.

See [Quant Purger documentation](https://docs.quantcdn.io/docs/integrations/drupal/purger)
for additional information.

## Requirements

  - quant
  - purge

## Recommendations

For the best performance, it is highly recommended that your settings include:

```
$settings['queue_service_quant_seed_worker'] = 'quant.queue_factory';
```
