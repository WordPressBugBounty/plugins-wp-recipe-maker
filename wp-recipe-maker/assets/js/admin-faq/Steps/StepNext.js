import React from 'react';
import { __wprm } from 'Shared/Translations';
import Faq from '../Faq';

const StepNext = (props) => {
    const createRecipeUrl = `${ wprm_admin.manage_url }&skip_onboarding=1&action=create`;

    return (
        <div className="wprm-admin-onboarding-step-next">
            <div className="wprm-admin-onboarding-first-recipe">
                <div>
                    <span>{ __wprm( 'Your setup is ready' ) }</span>
                    <h2>{ __wprm( 'Create your first recipe post' ) }</h2>
                    <p>
                        { __wprm( 'Add your recipe details first. After you save, WP Recipe Maker will help you add it to a new or existing WordPress post.' ) }
                    </p>
                </div>
                <a
                    href={ createRecipeUrl }
                    className="button button-primary button-hero"
                >{ __wprm( 'Create your first recipe post' ) }</a>
            </div>
            <p className="wprm-admin-onboarding-resources-note">
                { __wprm( 'You can come back to the email course, resources, and support below at any time from' ) } <em>{ __wprm( 'WP Recipe Maker > FAQ & Support' ) }</em>.
            </p>
            <Faq context="onboarding" />
            <div className="footer-buttons">
                <a
                    href={ wprm_admin.manage_url + '&skip_onboarding=1' }
                    className="button button-secondary button-compact"
                >{ __wprm( 'Continue to the Manage page' ) }</a>
            </div>
        </div>
    );
}
export default StepNext;
