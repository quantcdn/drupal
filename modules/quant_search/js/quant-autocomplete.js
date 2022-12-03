(function(Drupal, drupalSettings, once) {
    Drupal.behaviors.autocompleteBlockInit = {
        attach: function(context, settings) {
            once('autocompleteBlockInit', 'html', context).forEach(function(element) {
              const { autocomplete, getAlgoliaResults } = window["@algolia/autocomplete-js"];
              const searchClient = algoliasearch(
                drupalSettings.quantSearchAutocomplete.algolia_application_id,
                drupalSettings.quantSearchAutocomplete.algolia_read_key
              );

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
                    window.location.href = drupalSettings.quantSearchAutocomplete.search_path + '?' + drupalSettings.quantSearchAutocomplete.algolia_index + '%5Bquery%5D=' + state.query;
                  },
                  container: "#quant-search-autocomplete",
                  detachedMediaQuery: "none",
                  placeholder: drupalSettings.quantSearchAutocomplete.placeholder,
                  getSources() {
                      return [{
                          sourceId: drupalSettings.quantSearchAutocomplete.algolia_index,
                          getItemUrl({ item }) {
                              return item.url;
                          },
                          getItems({ query }) {
                              return getAlgoliaResults({
                                  searchClient,
                                  queries: [
                                      {
                                          indexName: drupalSettings.quantSearchAutocomplete.algolia_index,
                                          query,
                                          params: {
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
