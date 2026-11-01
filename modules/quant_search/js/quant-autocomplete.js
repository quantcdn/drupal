(function(Drupal, drupalSettings, once) {
    Drupal.behaviors.autocompleteBlockInit = {
        attach: function(context, settings) {
            once('autocompleteBlockInit', 'html', context).forEach(function(element) {
              const { autocomplete, getAlgoliaResults } = window["@algolia/autocomplete-js"];
              const searchClient = Drupal.quantSearch.createSearchClient(drupalSettings.quantSearchAutocomplete);

              autocomplete({
                  navigator: {
                    navigate({ itemUrl }) {
                      window.location.assign(itemUrl);
                    },
                    navigateNewTab({ itemUrl }) {
                      const windowReference = window.open(itemUrl, '_blank', 'noopener');

                      if (windowReference) {
                        windowReference.focus();
                      }
                    },
                    navigateNewWindow({ itemUrl }) {
                      window.open(itemUrl, '_blank', 'noopener');
                    },
                  },
                  onSubmit({ state, event }) {
                    // The search page reads its query from `keys`.
                    window.location.href = drupalSettings.quantSearchAutocomplete.search_path + '?keys=' + encodeURIComponent(state.query);
                  },
                  container: "#quant-search-autocomplete",
                  detachedMediaQuery: "none",
                  placeholder: drupalSettings.quantSearchAutocomplete.placeholder,
                  getSources() {
                      return [{
                          sourceId: drupalSettings.quantSearchAutocomplete.index,
                          getItemUrl({ item }) {
                              return item.url;
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
                            window.location.assign(event.item.url);
                          },
                          templates: {
                              item({ item, html }) {
                                  return html`
                                  <div>
                                    <h6 class="autocomplete-hit-name">${item.title}</h6>
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
