# Quant cache tag purger

Adds a cache tag plugin which listens to Drupal invalidation events in order to
queue Quant updates for related content.

For example, this allows node edits to trigger the main (`/node`) page to update
along with any other pages associated with the node through cache tags (e.g.
views pages, taxonomy term pages, etc).

This also works with other entities. For example, if a term is associated with
several nodes, those nodes will be queued for updates when the term is edited.

To ensure that queued content is processed in a timely manner, you can set up a
Quant cron process that is separate from the core cron which just processes the
Quant queue. This Quant cron can be run more regularly than the core cron.

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
