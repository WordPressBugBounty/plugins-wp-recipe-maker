// Settings to link to for SEO issues that are fixed on the settings page.
const seoSettingTargets = {
    settings_metadata: 'metadata_only_show_for_first_recipe',
    settings_ratings: 'features_comment_ratings',
};

const getDefaultSeoTarget = ( seo ) => {
    if ( 'other' === seo.type ) {
        return 'general';
    }

    const issues = Array.isArray( seo.issues ) ? seo.issues : [];
    const issueWithTarget = issues.find( ( issue ) => issue.target );

    return issueWithTarget ? issueWithTarget.target : false;
};

/**
 * Open the place where an SEO issue can be fixed.
 *
 * @param {Object}   recipe    Recipe with at least an id. Name and seo are optional.
 * @param {Function} onChange  Called after the recipe might have changed.
 * @param {string}   target    Target to open, or false to use the first issue's target.
 */
export const openSeoTarget = ( recipe, onChange, target = false ) => {
    if ( ! target ) {
        target = getDefaultSeoTarget( recipe.seo ? recipe.seo : {} );
    }

    if ( 'add_to_post' === target ) {
        WPRM_Modal.open( 'add-recipe-to-post', {
            recipeId: recipe.id,
            recipeName: recipe.name ? recipe.name : '',
            notInPost: true,
            closeCallback: onChange,
        } );
    } else if ( seoSettingTargets.hasOwnProperty( target ) ) {
        window.location.href = `admin.php?page=wprm_settings#${ seoSettingTargets[ target ] }`;
    } else {
        WPRM_Modal.open( 'recipe', {
            recipeId: recipe.id,
            scrollToGroup: target ? target : undefined,
            saveCallback: onChange,
        } );
    }
};

/**
 * Open an SEO target requested through the URL, for links from outside the Manage page like WPRM Kitchen.
 * Supports ?action=edit&recipe_id=123&group=times and ?action=add_to_post&recipe_id=123.
 *
 * @param {URLSearchParams} urlParams URL parameters of the current page.
 * @param {Function}        onChange  Called after the recipe might have changed.
 * @return {boolean} Whether a target was opened.
 */
export const openSeoTargetFromUrl = ( urlParams, onChange ) => {
    const action = urlParams.get( 'action' );
    const recipeId = parseInt( urlParams.get( 'recipe_id' ) );

    if ( ! recipeId || 0 >= recipeId ) {
        return false;
    }

    if ( 'add_to_post' === action ) {
        openSeoTarget( { id: recipeId }, onChange, 'add_to_post' );
        return true;
    }

    if ( 'edit' === action ) {
        const group = urlParams.get( 'group' );
        openSeoTarget( { id: recipeId }, onChange, group && /^[a-z_]+$/.test( group ) && ! seoSettingTargets.hasOwnProperty( group ) ? group : 'general' );
        return true;
    }

    return false;
};
