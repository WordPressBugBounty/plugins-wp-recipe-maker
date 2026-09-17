import React, { Component, Fragment } from 'react';
import he from 'he';

import Api from 'Shared/Api';
import Loader from 'Shared/Loader';
import { __wprm } from 'Shared/Translations';

export default class ToolbarSuggest extends Component {
    constructor(props) {
		super(props);
		
		// Cache suggestions.
		window.wprm_admin_modal_suggestions = window.wprm_admin_modal_suggestions || {};

        this.state = {
			search: '',
			suggestions: [],
			loading: false,
		}

		// Debounce timer for API calls.
		this.debounceTimer = null;
	}

	componentDidMount() {
		this.updateSuggestions( this.props.value );
	}

	componentDidUpdate(prevProps) {
		// Only update if the search value actually changed (not just on every render).
		const trimmedValue = this.props.value ? this.props.value.trim() : '';
		if ( trimmedValue !== this.state.search || prevProps.language !== this.props.language ) {
			// Clear any pending debounce timer.
			if ( this.debounceTimer ) {
				clearTimeout( this.debounceTimer );
				this.debounceTimer = null;
			}

			// Debounce API calls to avoid making requests on every keystroke.
			// Empty searches will be cached after the first call, so no performance impact.
			this.debounceTimer = setTimeout(() => {
				this.updateSuggestions( trimmedValue );
				this.debounceTimer = null;
			}, 300); // 300ms debounce delay.
		}
	}

	componentWillUnmount() {
		// Clean up debounce timer on unmount.
		if ( this.debounceTimer ) {
			clearTimeout( this.debounceTimer );
			this.debounceTimer = null;
		}
	}
	
	updateSuggestions( search ) {
        const language = this.props.language || '';
        const cacheKey = `${this.props.type}:${language}`;
        window.wprm_admin_modal_suggestions[ cacheKey ] = window.wprm_admin_modal_suggestions[ cacheKey ] || {};
		// Check cache first - empty searches will be cached after first call.
		if ( window.wprm_admin_modal_suggestions[ cacheKey ].hasOwnProperty( search ) ) {
			this.setState({
				suggestions: window.wprm_admin_modal_suggestions[ cacheKey ][ search ],
				search,
			});
		} else {
			this.setState({
				loading: true,
				search,
			});
	
			Api.modal.getSuggestions({
				type: this.props.type,
                language,
				search
			}).then(data => {
                if ( language !== (this.props.language || '') ) { return; }
				if ( data ) {
					window.wprm_admin_modal_suggestions[ cacheKey ][ search ] = data.suggestions;

					this.setState({
						suggestions: data.suggestions,
						loading: false,
					});
				} else {
					this.setState({
						loading: false,
					});
				}
			}).catch(() => {
				// Handle errors gracefully.
				this.setState({
					loading: false,
				});
			});
		}
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
