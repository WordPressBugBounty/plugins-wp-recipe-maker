import React from 'react';

import { __wprm } from 'Shared/Translations';

const FirstRecipeEmptyState = ( props ) => (
    <div className="wprm-admin-manage-first-recipe">
        <div className="wprm-admin-manage-first-recipe-content">
            <span className="wprm-admin-manage-first-recipe-eyebrow">
                { __wprm( 'Your first recipe' ) }
            </span>
            <h2>{ __wprm( 'Create your first recipe post' ) }</h2>
            <p>
                { __wprm( 'Add your recipe details first. After you save, WP Recipe Maker will help you add it to a new or existing WordPress post.' ) }
            </p>
            <button
                type="button"
                className="button button-primary button-hero"
                onClick={ props.onCreate }
            >
                { __wprm( 'Create your first recipe post' ) }
            </button>
        </div>
        <div className="wprm-admin-manage-first-recipe-example">
            <span className="wprm-admin-manage-first-recipe-example-label">
                { __wprm( 'Example recipe card' ) }
            </span>
            <img
                src={ `${ wprm_admin.wprm_url }assets/images/demo-recipe.jpg` }
                alt={ __wprm( 'Weeknight Vegetable Pizza' ) }
            />
            <div className="wprm-admin-manage-first-recipe-example-body">
                <h3>{ __wprm( 'Weeknight Vegetable Pizza' ) }</h3>
                <p>{ __wprm( 'A crisp, colorful pizza with peppers, olives, and fresh herbs.' ) }</p>
                <div className="wprm-admin-manage-first-recipe-example-meta">
                    <span>{ __wprm( '30 minutes' ) }</span>
                    <span>{ __wprm( '4 servings' ) }</span>
                </div>
            </div>
        </div>
    </div>
);

export default FirstRecipeEmptyState;
