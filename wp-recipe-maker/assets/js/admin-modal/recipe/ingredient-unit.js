import Helpers from 'Shared/Helpers';

export function getIngredientUnitCacheKey( unit = '' ) {
    return Helpers.stripHtml( unit ).toLowerCase();
}

export function getIngredientUnitConnectorCache() {
    window.wprm_admin_modal_ingredient_unit_connectors = window.wprm_admin_modal_ingredient_unit_connectors || {};

    return window.wprm_admin_modal_ingredient_unit_connectors;
}

function getIngredientUnitConnectorRequests() {
    window.wprm_admin_modal_ingredient_unit_connector_requests = window.wprm_admin_modal_ingredient_unit_connector_requests || {};

    return window.wprm_admin_modal_ingredient_unit_connector_requests;
}

function getIngredientUnitConnectorCacheVersions() {
    window.wprm_admin_modal_ingredient_unit_connector_cache_versions = window.wprm_admin_modal_ingredient_unit_connector_cache_versions || {};

    return window.wprm_admin_modal_ingredient_unit_connector_cache_versions;
}

export function setIngredientUnitConnectorCache( unit, data ) {
    const cacheKey = getIngredientUnitCacheKey( unit );
    const cache = getIngredientUnitConnectorCache();
    const versions = getIngredientUnitConnectorCacheVersions();

    versions[ cacheKey ] = ( versions[ cacheKey ] || 0 ) + 1;
    cache[ cacheKey ] = data;

    return data;
}

export function loadIngredientUnitConnector( unit, loader ) {
    const cacheKey = getIngredientUnitCacheKey( unit );
    const cache = getIngredientUnitConnectorCache();

    if ( cache.hasOwnProperty( cacheKey ) ) {
        return Promise.resolve( cache[ cacheKey ] );
    }

    const requests = getIngredientUnitConnectorRequests();

    if ( requests.hasOwnProperty( cacheKey ) ) {
        return requests[ cacheKey ];
    }

    const versions = getIngredientUnitConnectorCacheVersions();
    const cacheVersion = versions[ cacheKey ] || 0;
    const request = Promise.resolve()
        .then(() => loader( unit ))
        .then((data) => {
            const connectorData = data && data.found ? data : false;

            // ApiWrapper resolves HTTP errors to false. Only cache valid API
            // responses, including a successful lookup with found: false.
            if ( data && 'boolean' === typeof data.found && cacheVersion === ( versions[ cacheKey ] || 0 ) ) {
                cache[ cacheKey ] = connectorData;
            }

            return cache.hasOwnProperty( cacheKey ) ? cache[ cacheKey ] : connectorData;
        })
        .finally(() => {
            if ( requests[ cacheKey ] === request ) {
                delete requests[ cacheKey ];
            }
        });

    requests[ cacheKey ] = request;

    return request;
}

export function getIngredientUnitForAmount( ingredient = {}, amount = 0, system = false ) {
    const values = false !== system
        && ingredient.converted
        && ingredient.converted[ system ]
        ? ingredient.converted[ system ]
        : ingredient;
    const defaultUnit = values && values.unit ? values.unit : '';
    const cacheKey = getIngredientUnitCacheKey( defaultUnit );
    const cached = 'undefined' !== typeof window
        && window.wprm_admin_modal_ingredient_unit_connectors
        && window.wprm_admin_modal_ingredient_unit_connectors[ cacheKey ]
        ? window.wprm_admin_modal_ingredient_unit_connectors[ cacheKey ]
        : false;
    const singular = cached && ( cached.singular || cached.name )
        ? cached.singular || cached.name
        : values && values.unit_singular ? values.unit_singular : '';
    const plural = cached && cached.plural
        ? cached.plural
        : values && values.unit_plural ? values.unit_plural : '';

    if ( ! singular || ! plural || ! amount || isNaN( amount ) || amount <= 0 ) {
        return defaultUnit;
    }

    return amount <= 1 ? singular : plural;
}
