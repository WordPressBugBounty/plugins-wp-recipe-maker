import React, { Component } from 'react';
import he from 'he';
import AsyncSelect from 'react-select/async';

import { __wprm } from 'Shared/Translations';
import ApiWrapper from 'Shared/ApiWrapper';

export default class TaxonomyFilter extends Component {
    constructor(props) {
        super(props);

        this.loadedTerms = {};
        this._isMounted = false;
        this.selectedRequestId = 0;
        this.menuPortalTarget = 'undefined' !== typeof document ? document.body : null;

        this.state = {
            selectedOption: null,
        };
    }

    componentDidMount() {
        this._isMounted = true;
        this.cacheDefaultTerms();
        this.updateSelectedOption();
    }

    componentDidUpdate(prevProps) {
        if ( this.props.taxonomyKey !== prevProps.taxonomyKey ) {
            this.loadedTerms = {};
            this.cacheDefaultTerms();
        }

        if ( this.props.taxonomyKey !== prevProps.taxonomyKey || this.getFilterValue() !== this.getFilterValue( prevProps ) ) {
            this.updateSelectedOption();
        }
    }

    componentWillUnmount() {
        this._isMounted = false;
        this.selectedRequestId += 1;
    }

    cacheDefaultTerms() {
        const defaultTerms = this.props.taxonomy.terms || [];

        defaultTerms.forEach( ( term ) => {
            this.loadedTerms[ term.term_id ] = term;
        });
    }

    getFilterValue(props = this.props) {
        return props.filter ? `${ props.filter.value }` : 'all';
    }

    getGeneralOptions() {
        return [
            {
                value: 'all',
                label: `${ __wprm( 'All' ) } ${ this.props.taxonomy.label }`,
            },
            {
                value: 'none',
                label: `${ __wprm( 'No' ) } ${ this.props.taxonomy.label }`,
            },
            {
                value: 'any',
                label: `${ __wprm( 'Any' ) } ${ this.props.taxonomy.label }`,
            },
        ];
    }

    getTermOptions(terms = this.props.taxonomy.terms || []) {
        return terms.map( ( term ) => ({
            value: `${ term.term_id }`,
            label: he.decode( term.name ) + ( term.count ? ` (${ term.count })` : '' ),
            term,
        }) );
    }

    getGroupedOptions(terms = this.props.taxonomy.terms || []) {
        return [
            {
                label: __wprm( 'General' ),
                options: this.getGeneralOptions(),
            },
            {
                label: __wprm( 'Terms' ),
                options: this.getTermOptions( terms ),
            },
        ];
    }

    isGeneralValue(value) {
        return [ 'all', 'none', 'any' ].includes( value );
    }

    isNumericValue(value) {
        return /^\d+$/.test( `${ value }` );
    }

    getSelectedOptionFromCache(value) {
        if ( this.isGeneralValue( value ) ) {
            return this.getGeneralOptions().find( ( option ) => option.value === value ) || null;
        }

        if ( ! this.isNumericValue( value ) ) {
            return null;
        }

        const term = this.loadedTerms[ parseInt( value, 10 ) ];

        if ( ! term ) {
            return null;
        }

        return this.getTermOptions( [ term ] )[0];
    }

    updateSelectedOption() {
        const value = this.getFilterValue();

        if ( this.isGeneralValue( value ) ) {
            if ( this._isMounted ) {
                this.setState({
                    selectedOption: this.getSelectedOptionFromCache( value ),
                });
            }
            return;
        }

        const cachedOption = this.getSelectedOptionFromCache( value );

        if ( cachedOption ) {
            if ( this._isMounted ) {
                this.setState({
                    selectedOption: cachedOption,
                });
            }
            return;
        }

        if ( ! this.isNumericValue( value ) ) {
            if ( this._isMounted ) {
                this.setState({
                    selectedOption: null,
                });
            }
            return;
        }

        const requestId = ++this.selectedRequestId;
        const modalEndpoint = wprm_admin.endpoints.modal;
        const endpoint = `${ modalEndpoint }/categories`;

        if ( this._isMounted ) {
            this.setState({
                selectedOption: null,
            });
        }

        ApiWrapper.call( endpoint, 'POST', {
            taxonomy: this.props.taxonomyKey,
            term_ids: [ parseInt( value, 10 ) ],
        } ).then( ( response ) => {
            if ( ! this._isMounted || requestId !== this.selectedRequestId ) {
                return;
            }

            if ( response && response.terms && response.terms.length ) {
                response.terms.forEach( ( term ) => {
                    this.loadedTerms[ term.term_id ] = term;
                });

                this.setState({
                    selectedOption: this.getSelectedOptionFromCache( value ),
                });
            } else {
                this.setState({
                    selectedOption: null,
                });
            }
        } );
    }

    loadOptions(inputValue) {
        const modalEndpoint = wprm_admin.endpoints.modal;
        const endpoint = `${ modalEndpoint }/categories`;
        const search = inputValue ? inputValue.trim() : '';

        if ( ! search ) {
            return Promise.resolve( this.getGroupedOptions() );
        }

        return ApiWrapper.call( endpoint, 'POST', {
            taxonomy: this.props.taxonomyKey,
            search,
        } ).then( ( response ) => {
            const terms = response && response.terms ? response.terms : [];

            terms.forEach( ( term ) => {
                this.loadedTerms[ term.term_id ] = term;
            });

            return this.getGroupedOptions( terms );
        } ).catch( () => {
            return this.getGroupedOptions();
        });
    }

    render() {
        return (
            <AsyncSelect
                cacheOptions
                defaultOptions={ this.getGroupedOptions() }
                isClearable={ false }
                loadOptions={ this.loadOptions.bind( this ) }
                menuPortalTarget={ this.menuPortalTarget }
                menuPosition="fixed"
                noOptionsMessage={ () => __wprm( 'No terms found' ) }
                onChange={ ( option ) => {
                    const selectedOption = option || this.getGeneralOptions()[0];

                    this.setState({
                        selectedOption,
                    });

                    this.props.onChange( `${ selectedOption.value }` );
                } }
                placeholder={ __wprm( 'Select or search...' ) }
                styles={ {
                    control: (provided) => ({
                        ...provided,
                        backgroundColor: 'white',
                        minHeight: 30,
                    }),
                    container: (provided) => ({
                        ...provided,
                        width: '100%',
                        fontSize: '1em',
                    }),
                    indicatorsContainer: (provided) => ({
                        ...provided,
                        minHeight: 28,
                    }),
                    menu: (provided) => ({
                        ...provided,
                        zIndex: 100001,
                    }),
                    menuPortal: (provided) => ({
                        ...provided,
                        zIndex: 100001,
                    }),
                    valueContainer: (provided) => ({
                        ...provided,
                        paddingTop: 0,
                        paddingBottom: 0,
                    }),
                } }
                value={ this.state.selectedOption }
            />
        );
    }
}
