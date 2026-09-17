import React, { Component } from 'react';
import he from 'he';
import AsyncSelect from 'react-select/async';
import AsyncCreatableSelect from 'react-select/async-creatable';

import { __wprm } from 'Shared/Translations';
import ApiWrapper from 'Shared/ApiWrapper';

export default class FieldCategory extends Component {
    constructor(props) {
        super(props);
        
        // Cache for loaded terms to avoid re-fetching.
        this.loadedTerms = {};
        
        // Track if component is mounted to prevent setState warnings.
        this._isMounted = false;
        this.loadSelectedRequestId = 0;
        this.loadOptionsRequestId = 0;
        
        // Load selected terms initially.
        this.state = {
            selectedOptions: [],
            loadingSelected: false,
        };
    }

    componentDidMount() {
        this._isMounted = true;
        this.resetLoadedTerms();
        
        // Load selected terms after component is mounted.
        if ( this.props.value && this.props.value.length > 0 ) {
            this.loadSelectedTerms();
        }
    }

    componentWillUnmount() {
        this._isMounted = false;
        this.loadSelectedRequestId += 1;
        this.loadOptionsRequestId += 1;
    }

    componentDidUpdate(prevProps) {
        // Term IDs are only unique within their taxonomy. Never carry cache entries or
        // in-flight requests over when this component is reused for another taxonomy.
        if ( prevProps.id !== this.props.id ) {
            this.loadSelectedRequestId += 1;
            this.loadOptionsRequestId += 1;
            this.resetLoadedTerms();

            if ( this.props.value && this.props.value.length > 0 ) {
                this.loadSelectedTerms();
            } else if ( this._isMounted ) {
                this.setState({
                    selectedOptions: [],
                    loadingSelected: false,
                });
            }
            return;
        }

        // Reload selected terms if value changed.
        if ( JSON.stringify(prevProps.value) !== JSON.stringify(this.props.value) ) {
            if ( this.props.value && this.props.value.length > 0 ) {
                this.loadSelectedTerms();
            } else {
                if ( this._isMounted ) {
                    this.loadSelectedRequestId += 1;
                    this.setState({
                        selectedOptions: [],
                        loadingSelected: false,
                    });
                }
            }
        }
    }

    resetLoadedTerms() {
        this.loadedTerms = {};
        this.selectedTermSnapshots = {};

        // These are full WP_Term records, formatted by the same
        // category_editor_terms() method as the on-demand endpoint.
        const category = wprm_admin_modal.categories[ this.props.id ] || {};
        const defaultTerms = category.terms || [];
        defaultTerms.forEach(term => {
            this.loadedTerms[term.term_id] = term;
        });
    }

    getTermIdentifier(term) {
        return term.term_id || term.name;
    }

    refreshCachedSelectedTerms(selectedTerms) {
        selectedTerms.forEach(term => {
            const id = this.getTermIdentifier(term);

            if ( id && 'number' === typeof id ) {
                const snapshot = JSON.stringify(term);

                // Only new or changed recipe data can refresh a cached record.
                // Reusing unchanged props after an API lookup must not overwrite
                // the newer response. Keep snapshots through deselection too.
                if ( snapshot !== this.selectedTermSnapshots[id] && this.loadedTerms[id] ) {
                    this.loadedTerms[id] = {
                        ...this.loadedTerms[id],
                        ...term,
                    };
                }

                // Track uncached selections as well, before their lookup resolves.
                this.selectedTermSnapshots[id] = snapshot;
            }
        });
    }

    getSelectedOptions(selectedTerms) {
        return selectedTerms.reduce((options, selectedTerm) => {
            const id = this.getTermIdentifier(selectedTerm);

            if ( ! id ) {
                return options;
            }

            if ( 'number' === typeof id ) {
                const term = this.loadedTerms[id] || selectedTerm;
                const name = term.name || selectedTerm.name || id;
                options.push({
                    value: id,
                    label: he.decode(String(name)),
                    term,
                });
            } else {
                // Keep newly typed terms exactly as entered. They are not encoded
                // WordPress term names yet.
                const name = selectedTerm.name || id;
                options.push({
                    value: name,
                    label: name,
                    term: selectedTerm,
                });
            }

            return options;
        }, []);
    }

    loadSelectedTerms() {
        const requestId = ++this.loadSelectedRequestId;
        const taxonomy = this.props.id;

        if ( ! this._isMounted ) {
            return;
        }

        const selectedTerms = Array.isArray(this.props.value) ? this.props.value.slice() : [];
        this.refreshCachedSelectedTerms(selectedTerms);

        // Existing numeric terms absent from the taxonomy-local cache still need the
        // API. String IDs are newly typed terms and must never be looked up.
        const seenTermIds = {};
        const missingTermIds = selectedTerms
            .map(term => {
                const id = this.getTermIdentifier(term);
                if ( id && 'number' === typeof id && ! this.loadedTerms[id] && ! seenTermIds[id] ) {
                    seenTermIds[id] = true;
                    return id;
                }
                return null;
            })
            .filter(id => null !== id);

        if ( missingTermIds.length > 0 ) {
            this.setState({ loadingSelected: true });

            const modalEndpoint = wprm_admin.endpoints.modal;
            const endpoint = `${modalEndpoint}/categories`;

            ApiWrapper.call(endpoint, 'POST', {
                taxonomy,
                term_ids: missingTermIds,
            }).then((response) => {
                if ( ! this._isMounted || requestId !== this.loadSelectedRequestId || taxonomy !== this.props.id ) {
                    return;
                }

                if ( response && response.terms ) {
                    // Cache loaded terms.
                    response.terms.forEach(term => {
                        this.loadedTerms[term.term_id] = term;
                    });
                }

                this.setState({
                    selectedOptions: this.getSelectedOptions(selectedTerms),
                    loadingSelected: false,
                });
            }).catch(() => {
                if ( this._isMounted && requestId === this.loadSelectedRequestId && taxonomy === this.props.id ) {
                    // Preserve the complete selection using cached records and the
                    // recipe value as a display fallback when the lookup fails.
                    this.setState({
                        selectedOptions: this.getSelectedOptions(selectedTerms),
                        loadingSelected: false,
                    });
                }
            });
        } else {
            this.setState({
                selectedOptions: this.getSelectedOptions(selectedTerms),
                loadingSelected: false,
            });
        }
    }

    loadOptions(input) {
        const requestId = ++this.loadOptionsRequestId;

        // Return empty array if no search input (unless we have cached terms).
        if ( ! input ) {
            return Promise.resolve([]);
        }

        const modalEndpoint = wprm_admin.endpoints.modal;
        const endpoint = `${modalEndpoint}/categories`;
        const taxonomy = this.props.id;

        return ApiWrapper.call(endpoint, 'POST', {
            taxonomy,
            search: input,
        }).then((response) => {
            if ( ! this._isMounted || requestId !== this.loadOptionsRequestId || taxonomy !== this.props.id ) {
                return [];
            }

            if ( response && response.terms ) {
                // Cache loaded terms.
                response.terms.forEach(term => {
                    this.loadedTerms[term.term_id] = term;
                });

                // Convert to options format.
                return response.terms.map(term => ({
                    value: term.term_id,
                    label: he.decode(term.name),
                    term: term,
                }));
            }
            return [];
        }).catch(() => {
            return [];
        });
    }

    shouldComponentUpdate(nextProps, nextState) {
        return this.props.id !== nextProps.id
               || JSON.stringify(this.props.value) !== JSON.stringify(nextProps.value)
               || this.state.loadingSelected !== nextState.loadingSelected
               || JSON.stringify(this.state.selectedOptions) !== JSON.stringify(nextState.selectedOptions);
    }

    render() {
        const customProps = this.props.custom ? this.props.custom : {};
        const SelectElem = this.props.creatable ? AsyncCreatableSelect : AsyncSelect;

        // Get default options from get_categories() (top 50 most frequently used terms).
        const defaultTerms = wprm_admin_modal.categories[ this.props.id ].terms || [];
        const defaultOptions = defaultTerms.map(term => ({
            value: term.term_id,
            label: he.decode(term.name),
            term: term,
        }));

        // Convert selected value to options format.
        let selectedOptions = this.state.selectedOptions;
        
        // Fallback: if we have value but no loaded options yet, create options from value.
        if ( ! this.state.loadingSelected && this.props.value && this.props.value.length > 0 && selectedOptions.length === 0 ) {
            selectedOptions = this.props.value.map(term => ({
                value: term.term_id || term.name,
                label: term.name || term.term_id,
                term: term,
            }));
        }

        const select = (
            <SelectElem
                isMulti
                formatOptionLabel={ ( option, context ) => {
                    const term = option.term;
                    if ( 'menu' !== context.context || ! term || ! wprm_admin_modal.multilingual || 'polylang' !== wprm_admin_modal.multilingual.plugin ) {
                        return option.label;
                    }
                    const languages = wprm_admin_modal.multilingual.languages || {};
                    const language = term.language && languages[ term.language ] ? languages[ term.language ].label : term.language;
                    return <div>
                        <div>{ option.label }</div>
                        <small>{ [ language || __wprm( 'No language set' ), `${ term.count || 0 } ${ __wprm( 'Recipes' ) }`, `ID ${ term.term_id }` ].join( ' · ' ) }</small>
                    </div>;
                } }
                defaultOptions={defaultOptions}
                loadOptions={this.loadOptions.bind(this)}
                value={selectedOptions}
                placeholder={ this.props.creatable ? __wprm( 'Select from list or type to create...' ) : __wprm( 'Select from list...' ) }
                onChange={(value) => {
                    this.setState({
                        selectedOptions: value || [],
                    });

                    let newValue = [];

                    if ( value ) {
                        for ( let option of value ) {
                            if ( option.hasOwnProperty('__isNew__') && option.__isNew__ ) {
                                // New term being created.
                                newValue.push({
                                    term_id: option.label,
                                    name: option.label,
                                });
                            } else {
                                // Existing term - get from cache or option.
                                let term = option.term || this.loadedTerms[option.value];
                                
                                if ( term ) {
                                    newValue.push(term);
                                } else {
                                    // Fallback if term not in cache.
                                    newValue.push({
                                        term_id: option.value,
                                        name: option.label,
                                    });
                                }
                            }
                        }
                    }

                    this.props.onChange(newValue);
                }}
                styles={{
                    placeholder: (provided) => ({
                        ...provided,
                        color: '#444',
                        opacity: '0.333',
                    }),
                    control: (provided) => ({
                        ...provided,
                        backgroundColor: 'white',
                    }),
                    container: (provided) => ({
                        ...provided,
                        width: '100%',
                        maxWidth: this.props.width ? this.props.width : '100%',
                    }),
                }}
                { ...customProps }
            />
        );

        return (
            <div
                onKeyDown={ (event) => {
                    // React Select prevents Enter when it can handle the key itself. If it
                    // cannot (for example while async options are loading), prevent the
                    // surrounding recipe form from activating its default submit button.
                    if ( 'Enter' === event.key && ! event.defaultPrevented ) {
                        event.preventDefault();
                    }
                } }
            >
                { select }
            </div>
        );
    }
}
