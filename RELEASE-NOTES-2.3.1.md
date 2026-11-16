# Quant 2.3.1 (Drupal 11)

## Upgrade notes

- **A search page publishes whenever it is saved.** Before, only the search
  page forms published it. Now a page saved by drush, a recipe, custom code
  or a configuration import (`drush cim`) publishes too, like a node save.
  The publish runs at the end of the request, after the import is complete,
  and only when Quant is enabled (`quant.settings:quant_enabled`) and the
  site is connected. A change saved during installation or in maintenance
  mode is not published then: it is listed with the changes that did not
  reach Quant, and the next save of the page or a seed publishes it. A site
  that shares its configuration with another site
  publishes to its own project only; turn `quant_enabled` off on a copy that
  must not publish. Pages saved before the upgrade are not published again:
  save them or seed.
- **A disabled or deleted search page is unpublished, and a moved one
  redirects.** When a page gets a new address, the old address is
  unpublished and redirects (301) to the new one, unless another search page
  uses it now. A Quant error during the publish is logged and listed with
  the other failed pushes; it does not stop the save or the import.
- **"Create a search page" suggests a region for the search box.** It
  suggests `secondary_menu` (Olivero and themes based on it), then the region
  of the theme's Drupal search box, then `header`, and says which one. The
  choice is shown only when the block module is installed.
- **Two search pages cannot have the same address.** The search page form
  refuses an address that another search page uses, also when only the case
  differs (`/Search` and `/search`), because Quant paths do not depend on
  case. A route is saved without spaces or slashes at either end; its case
  is kept, so links to it on the site keep working. A change of case only is
  not a move: nothing is unpublished or redirected.
