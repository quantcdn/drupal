# Quant Purger

The Quant Purger helps you keep content fresh on your static Quant site
after content updates within Drupal.

## Purge Plugins

This module is built on top of the [Purge module suite](https://www.drupal.org/project/purge).

### Purger Plugin

Processes cache invalidations based on type: 'everything', 'path' and 'tag'.
The Quant cache will be purged based on these invalidations.

- *Everything:* A site-wide cache purge, e.g. `/*`.
- *Path:* Purges the given path.
- *Tag:* Purges the given tag.

### Queuer Plugin

Adds a cache tag queuer plugin which listens to Drupal invalidation events in
order to queue Quant updates for related content.

For example, this allows node edits to trigger the main (`/node`) page to update
along with any other pages associated with the node through cache tags (e.g.
views pages, taxonomy term pages, etc).

This also works with other entities. For example, if a term is associated with
several nodes, those nodes will be queued for updates when the term is edited.

To ensure that queued content is processed in a timely manner, you can set up a
Quant cron process that is separate from the core cron which just processes the
Quant queue. This Quant cron can be run more regularly than the core cron.

### TagsHeader Plugin

Sets and formats the default response header with hashed cache tags.

## Documentation

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
