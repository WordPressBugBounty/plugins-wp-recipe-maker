// Shared vendors.
import ReactDOM from 'react-dom';
import React from 'react';
import ApiWrapper from './shared/ApiWrapper';

// Global variables.
import { createHooks } from '@wordpress/hooks';
let hooks = createHooks();

const getModalEndpoint = () => {
	if (
		'undefined' === typeof wprm_admin
		|| ! wprm_admin.endpoints
		|| ! wprm_admin.endpoints.modal
	) {
		return '';
	}

	return wprm_admin.endpoints.modal;
};

hooks.addFilter( 'api', 'wp-recipe-maker/ingredient-unit-connector-api', ( api ) => {
	if ( ! api.modal ) {
		api.modal = {};
	}

	if ( 'function' !== typeof api.modal.getIngredientUnitConnector ) {
		api.modal.getIngredientUnitConnector = ( unit, create = false ) => {
			const modalEndpoint = getModalEndpoint();

			if ( ! modalEndpoint ) {
				return Promise.resolve( false );
			}

			return ApiWrapper.call( `${ modalEndpoint }/ingredient-unit/connector`, 'POST', {
				unit,
				create,
			} );
		};
	}

	return api;
} );

// Apply this in the shared bundle so older Premium bundles use the same metadata route.
hooks.addFilter( 'api', 'wp-recipe-maker/ingredient-metadata-api', ( api ) => {
	const updateTaxonomyMeta = api.manage.updateTaxonomyMeta;
	api.manage.updateTaxonomyMeta = function( type, id, meta ) {
		if ( 'ingredient' === type ) {
			return ApiWrapper.call( `${ wprm_admin.endpoints.manage }/ingredient/${ id }/meta`, 'POST', { ingredient: meta } );
		}
		return updateTaxonomyMeta.call( this, type, id, meta );
	};
	return api;
} );

export { hooks };
