(function(Drupal, drupalSettings, once) {
    Drupal.behaviors.autocompleteBlockInit = {
        attach: function(context, settings) {
            once('autocompleteBlockInit', 'html', context).forEach(function(element) {
              const { autocomplete, getAlgoliaResults } = window["@algolia/autocomplete-js"];
              const searchClient = Drupal.quantSearch.createSearchClient(drupalSettings.quantSearchAutocomplete);
              // Result URLs come from the search index: open only http,
              // https and relative ones. Other suggestions are not links.
              const safeUrl = Drupal.quantSearch.safeUrl;
              const openUrl = function (url, newWindow) {
                const target = safeUrl(url);
                if (!target) {
                  return null;
                }
                if (!newWindow) {
                  window.location.assign(target);
                  return null;
                }
                return window.open(target, '_blank', 'noopener');
              };

              // A theme can widen the suggestions beyond a narrow box with the
              // --quant-search-autocomplete-panel-placement custom property:
              // start, end, full-width or input-wrapper-width (the default).
              const container = document.querySelector('#quant-search-autocomplete');
              const placements = ['start', 'end', 'full-width', 'input-wrapper-width'];
              const placement = container ? getComputedStyle(container).getPropertyValue('--quant-search-autocomplete-panel-placement').trim() : '';

              autocomplete({
                  navigator: {
                    navigate({ itemUrl }) {
                      openUrl(itemUrl, false);
                    },
                    navigateNewTab({ itemUrl }) {
                      const windowReference = openUrl(itemUrl, true);

                      if (windowReference) {
                        windowReference.focus();
                      }
                    },
                    navigateNewWindow({ itemUrl }) {
                      openUrl(itemUrl, true);
                    },
                  },
                  onSubmit({ state, event }) {
                    // The search page reads its query from `keys`.
                    window.location.href = drupalSettings.quantSearchAutocomplete.search_path + '?keys=' + encodeURIComponent(state.query);
                  },
                  container: "#quant-search-autocomplete",
                  detachedMediaQuery: "none",
                  panelPlacement: placements.includes(placement) ? placement : 'input-wrapper-width',
                  placeholder: drupalSettings.quantSearchAutocomplete.placeholder,
                  getSources() {
                      return [{
                          sourceId: drupalSettings.quantSearchAutocomplete.index,
                          getItemUrl({ item }) {
                              return safeUrl(item.url) || undefined;
                          },
                          getItems({ query }) {
                              return getAlgoliaResults({
                                  searchClient,
                                  queries: [
                                      {
                                          indexName: drupalSettings.quantSearchAutocomplete.index,
                                          query,
                                          // The query goes in both places. Algolia reads the
                                          // sibling; the Typesense adapter reads only
                                          // params.query and otherwise searches '*'.
                                          params: {
                                            query,
                                            filters: drupalSettings.quantSearchAutocomplete.filters,
                                          }
                                      },
                                  ],
                              });
                          },
                          onSelect: function (event) {
                            openUrl(event.item.url, false);
                          },
                          templates: {
                              item({ item, html }) {
                                  // No heading element: an option in a listbox must
                                  // not contain one, and theme heading margins
                                  // spread the suggestions apart.
                                  return html`
                                  <div class="autocomplete-hit">
                                    <span class="autocomplete-hit-name">${item.title}</span>
                                    ${drupalSettings.quantSearchAutocomplete.show_summary == true ? html`<div class="autocomplete-hit-description">${item.summary}</div>` : ''}
                                </div>`
                              },
                          },
                      }];
                  },
              });
            })
        }
    }
}(Drupal, drupalSettings, once));
