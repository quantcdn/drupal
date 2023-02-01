(function(Drupal, drupalSettings, once) {
    Drupal.behaviors.searchPageInit = {
        attach: function(context, settings) {
            once('searchPageInit', 'html', context).forEach(function(element) {

                /* global instantsearch algoliasearch */
                function getParameterByName(name) {
                    const match = RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search)
                    return match && decodeURIComponent(match[1].replace(/\+/g, ' '))
                }

                const search = instantsearch({
                    indexName: drupalSettings.quantSearch.algolia_index,
                    searchClient: algoliasearch(drupalSettings.quantSearch.algolia_application_id, drupalSettings.quantSearch.algolia_read_key),
                    routing: true,
                    initialUiState: {
                        [drupalSettings.quantSearch.algolia_index]: {
                            query: getParameterByName('keys')
                        }
                    }
                });

                search.templatesConfig.helpers.image = function(text, render) {
                    if (render(text)) {
                        return '<img ' +
                            'src="' + render(text) + '" ' +
                            'alt="' + render("{{title}}") + '" ' +
                            '/>';
                    }
                };

                search.templatesConfig.helpers.tags = function(text, render) {
                    if (render('{{' + text + '}}')) {
                        const tags = render('{{' + text + '}}');
                        var markup = '';
                        for (tag of tags.split(',')) {
                            markup += '<span class="hit-tag">' + tag + '</span>';
                        }
                        return markup;
                    }
                };

                if (drupalSettings.quantSearch.display.results.display_search) {
                    search.addWidgets([
                        instantsearch.widgets.searchBox({
                            container: '#searchbox',
                        }),
                    ]);
                }

                if (drupalSettings.quantSearch.display.pagination.pagination_enabled) {
                    search.addWidgets([
                        instantsearch.widgets.pagination({
                            container: '#pagination',
                        }),
                    ]);
                }

                if (drupalSettings.quantSearch.display.results.display_stats) {
                    search.addWidgets([
                        instantsearch.widgets.stats({
                            container: '#stats',
                            templates: {
                                text: `
                        {{#hasOneResult}}1 result{{/hasOneResult}}
                        {{#hasManyResults}}{{#helpers.formatNumber}}{{nbHits}}{{/helpers.formatNumber}} results{{/hasManyResults}}
                        `,
                            },
                        }),
                    ]);
                }

                var facets = drupalSettings.quantSearch.facets;
                if (typeof facets === 'object' && facets !== null && !Array.isArray(facets)) {
                   if (drupalSettings.quantSearch.display.results.show_clear_refinements) {
                        search.addWidgets([
                            instantsearch.widgets.clearRefinements({
                                container: '#clear-refinements',
                            }),
                        ]);
                    }
                    for (var facet_key in facets) {
                        const facet = facets[facet_key];

                        switch (facet.facet_display) {
                            case "checkbox":
                                search.addWidgets([
                                    instantsearch.widgets.refinementList({
                                        container: '#facet_' + facet.facet_container,
                                        attribute: facet.facet_key,
                                        limit: facet.facet_limit,
                                    }),
                                ]);
                                break;

                            case "menu":
                                search.addWidgets([
                                    instantsearch.widgets.menu({
                                        container: '#facet_' + facet.facet_container,
                                        attribute: facet.facet_key,
                                        limit: facet.facet_limit,
                                    }),
                                ]);
                                break;

                            case "select":
                                search.addWidgets([
                                    instantsearch.widgets.menuSelect({
                                        container: '#facet_' + facet.facet_container,
                                        attribute: facet.facet_key,
                                        limit: facet.facet_limit,
                                    }),
                                ]);
                                break;
                        }
                    }
                }

                search.addWidgets([
                    instantsearch.widgets.configure({
                        attributesToSnippet: ['summary:100'],
                        snippetEllipsisText: '…',
                        filters: drupalSettings.quantSearch.filters,
                        hitsPerPage: drupalSettings.quantSearch.display.pagination.per_page
                    }),
                    instantsearch.widgets.hits({
                        container: '#hits',
                        templates: {
                            empty: '<h4>No results found.</h4><p>Please try again with different search terms or filters.</p>',
                            item: `
                    <div>
                        <a href="{{url}}">
                        {{#helpers.image}}{{image}}{{/helpers.image}}
                        <h4 class="hit-name">
                            {{#helpers.highlight}}{ "attribute": "title" }{{/helpers.highlight}}
                        </h4>
                        </a>
                        <div class="hit-description">
                            {{#helpers.snippet}}{ "attribute": "summary" }{{/helpers.snippet}}
                        </div>
                    </div>
                    `,
                        },
                    }),

                ]);

                search.start();

            })
        }
    }
}(Drupal, drupalSettings, once));
