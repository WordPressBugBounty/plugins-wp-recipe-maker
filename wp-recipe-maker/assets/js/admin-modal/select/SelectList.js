import React, { Component } from 'react';
import AsyncSelect from 'react-select/async';

import { __wprm } from 'Shared/Translations';
import AjaxWrapper from 'Shared/AjaxWrapper';

export default class SelectList extends Component {
    getExcludeIds() {
        const excludeIds = this.props.excludeIds ? this.props.excludeIds : [];

        return excludeIds.map((id) => parseInt( id ) ).filter((id) => ! isNaN( id ) && 0 < id );
    }

    filterOptions( options ) {
        const excludeIds = this.getExcludeIds();

        if ( ! excludeIds.length ) {
            return options;
        }

        return options.filter((option) => -1 === excludeIds.indexOf( parseInt( option.id ) ) );
    }

    getOptions(input) {
        if (!input) {
			return Promise.resolve({ options: [] });
        }

		return AjaxWrapper.call('wprm_search_lists', {
            search: input,
        }).then((data) => {
            // Return empty array if no data or error occurred.
            return data && data.lists_with_id ? this.filterOptions( data.lists_with_id ) : [];
        });
    }

    render() {
        const defaultOptions = this.filterOptions( ( this.props.options ? this.props.options : [] ).concat( wprm_admin.latest_lists ) );

        return (
            <AsyncSelect
                placeholder={ __wprm( 'Select or start typing to search for a list' ) }
                value={this.props.value}
                onChange={this.props.onValueChange}
                getOptionValue={({id}) => id}
                getOptionLabel={({text}) => text}
                defaultOptions={defaultOptions}
                loadOptions={this.getOptions.bind(this)}
                noOptionsMessage={() => __wprm( 'No lists found' ) }
                clearable={false}
                menuPlacement={ this.props.hasOwnProperty( 'menuPlacement' ) ? this.props.menuPlacement : 'auto' }
            />
        );
    }
}
