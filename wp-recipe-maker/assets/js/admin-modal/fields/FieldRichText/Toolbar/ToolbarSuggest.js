import React, { Component, Fragment } from 'react';
import he from 'he';

import Api from 'Shared/Api';
import Loader from 'Shared/Loader';
import { __wprm } from 'Shared/Translations';

export default class ToolbarSuggest extends Component {
    constructor(props) {
        super(props);

        window.wprm_admin_modal_suggestions = window.wprm_admin_modal_suggestions || Object.create( null );
        this.state = {
            search: '',
            suggestions: [],
            loading: false,
        };
        this.suggestionsRequester = Api.modal.createSuggestionsRequester();
        this.debounceTimer = null;
        this.requestId = 0;
        this.unmounted = false;
    }

    componentDidMount() {
        this.unmounted = false;
        this.updateSuggestions();
    }

    componentDidUpdate(prevProps) {
        const search = ( this.props.value || '' ).trim();
        const previousSearch = ( prevProps.value || '' ).trim();
        if ( search !== previousSearch || prevProps.type !== this.props.type || ( prevProps.language || '' ) !== ( this.props.language || '' ) ) {
            // Invalidate immediately, including responses arriving during debounce.
            this.cancelSuggestions();
            this.setState({ search, suggestions: [], loading: true });
            this.debounceTimer = setTimeout(() => {
                this.debounceTimer = null;
                this.updateSuggestions();
            }, 300);
        }
    }

    componentWillUnmount() {
        this.unmounted = true;
        this.cancelSuggestions();
    }

    cancelSuggestions() {
        this.requestId++;
        if ( null !== this.debounceTimer ) {
            clearTimeout( this.debounceTimer );
            this.debounceTimer = null;
        }
        this.suggestionsRequester.cancel();
    }

    updateSuggestions() {
        this.cancelSuggestions();
        const requestId = this.requestId;
        const search = ( this.props.value || '' ).trim();
        const type = this.props.type;
        const language = this.props.language || '';
        const cacheKey = JSON.stringify([ type, language ]);
        const cache = window.wprm_admin_modal_suggestions[ cacheKey ] = window.wprm_admin_modal_suggestions[ cacheKey ] || Object.create( null );

        if ( Object.prototype.hasOwnProperty.call( cache, search ) ) {
            this.setState({ suggestions: cache[ search ], search, loading: false });
            return;
        }

        this.setState({ suggestions: [], loading: true, search });
        const isCurrentRequest = () => ! this.unmounted
            && requestId === this.requestId
            && search === ( this.props.value || '' ).trim()
            && type === this.props.type
            && language === ( this.props.language || '' );

        this.suggestionsRequester.getSuggestions({ type, language, search }).then(data => {
            if ( ! isCurrentRequest() ) { return; }
            if ( data && Array.isArray( data.suggestions ) ) {
                cache[ search ] = data.suggestions;
                this.setState({ suggestions: data.suggestions, loading: false });
            } else {
                this.setState({ loading: false });
            }
        }).catch(() => {
            if ( isCurrentRequest() ) {
                this.setState({ loading: false });
            }
        });
    }
  
    render() {
        return (
            <div className="wprm-admin-modal-toolbar-suggest">
				{
					! this.state.loading
					&& 0 === this.state.suggestions.length
					?
					<strong>{ __wprm( 'No suggestions found.' ) }</strong>
					:
					<Fragment>
						<strong>{ __wprm( 'Suggestions:' ) }</strong>
						{
							this.state.loading
							?
							<Loader/>
							:
							<Fragment>
								{
									this.state.suggestions.map((suggestion, index) => (
										<span
											className="wprm-admin-modal-toolbar-suggestion"
											onMouseDown={ (event) => {
												event.preventDefault();
												this.props.onSelect( suggestion );
											} }
											key={ index }
										>
											<span className="wprm-admin-modal-toolbar-suggestion-text">{ he.decode( suggestion.name ) } ({ suggestion.count})</span>
										</span>
									))
								}
							</Fragment>
						}
					</Fragment>
				}
			</div>
        );
    }
}
