// Optional count of nutritional portions in the complete base recipe.
export const parseNutritionServings = ( value ) => {
    if ( 'number' !== typeof value && 'string' !== typeof value ) {
        return false;
    }
    const text = String( value ).trim().replace( ',', '.' );
    const count = /^[+]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test( text ) ? Number( text ) : NaN;
    return Number.isFinite( count ) && 0 < count ? count : false;
};

export const getNutritionCalculationServings = ( recipeServings, nutritionServings ) => {
    const override = parseNutritionServings( nutritionServings );
    // Preserve the existing calculator fallback when there is no override.
    const servings = parseFloat( recipeServings );
    return override || ( Number.isFinite( servings ) && 0 < servings ? servings : 1 );
};

export const getNutritionPortions = ( requestedServings, baseServings, nutritionServings ) => {
    const override = parseNutritionServings( nutritionServings );
    const base = parseFloat( baseServings );
    return override ? override * requestedServings / ( Number.isFinite( base ) && 0 < base ? base : 1 ) : requestedServings;
};
