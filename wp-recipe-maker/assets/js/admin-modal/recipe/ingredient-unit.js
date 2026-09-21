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
            const hasUnitForms = data && data.singular && data.plural;
            const connectorData = data && ( data.found || hasUnitForms ) ? data : false;

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

export function getIngredientNameForAmount( ingredient = {}, amount = 0, system = false ) {
    const defaultName = ingredient && ingredient.name ? ingredient.name : '';

    if ( ! defaultName || ! amount || isNaN( amount ) || amount <= 0 ) {
        return defaultName;
    }

    const values = false !== system
        && ingredient.converted
        && ingredient.converted[ system ]
        ? ingredient.converted[ system ]
        : ingredient;
    const unit = values && values.unit ? values.unit : '';
    const cacheKey = getIngredientUnitCacheKey( unit );
    const hasCached = unit
        && 'undefined' !== typeof window
        && window.wprm_admin_modal_ingredient_unit_connectors
        && window.wprm_admin_modal_ingredient_unit_connectors.hasOwnProperty( cacheKey );
    const cached = hasCached ? window.wprm_admin_modal_ingredient_unit_connectors[ cacheKey ] : false;
    const connector = hasCached
        ? cached && cached.connector ? cached.connector : ''
        : values && values.unit_connector ? values.unit_connector : '';
    const connectorPluralizesIngredient = hasCached
        ? !! ( cached && cached.connector_pluralizes_ingredient )
        : values && values.unit_connector ? !! values.unit_connector_pluralizes_ingredient : true;

    if ( connector && ! connectorPluralizesIngredient ) {
        return defaultName;
    }

    const forms = [];
    if ( ingredient.name_singular && ingredient.name_plural ) {
        forms.push( {
            singular: ingredient.name_singular,
            plural: ingredient.name_plural,
        } );
    }

    if ( ingredient.unit_system_names && 'object' === typeof ingredient.unit_system_names ) {
        const systems = false !== system
            ? [ `${ system }` ]
            : Object.keys( ingredient.unit_system_names );

        for ( const nameSystem of systems ) {
            const systemNames = ingredient.unit_system_names[ nameSystem ];
            if ( systemNames && systemNames.singular && systemNames.plural ) {
                forms.push( {
                    singular: systemNames.singular,
                    plural: systemNames.plural,
                } );
            }
        }
    }

    const normalizedName = getIngredientUnitCacheKey( defaultName );
    const matchingForms = forms.find((nameForms) => (
        normalizedName === getIngredientUnitCacheKey( nameForms.singular )
        || normalizedName === getIngredientUnitCacheKey( nameForms.plural )
    ));

    if ( ! matchingForms ) {
        return defaultName;
    }

    return amount <= 1 ? matchingForms.singular : matchingForms.plural;
}
