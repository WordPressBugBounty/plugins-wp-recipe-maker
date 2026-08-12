import Helpers from 'Shared/Helpers';

export function getIngredientUnitCacheKey( unit = '' ) {
    return Helpers.stripHtml( unit ).toLowerCase();
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
