# Quant cache tag purger

Adds a cache tag plugin which listen to invalidation events to queue Quant repopulation. This allows node_saves to update the main page but also trigger updates for pages that might be affected by the content update (eg. views pages). These will be added to the queue for the next core cron run to publish the updates.

## Requirements

  - quant
  - purge
