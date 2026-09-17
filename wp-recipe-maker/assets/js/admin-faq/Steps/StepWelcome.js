import React, { useState } from 'react';
import { __wprm } from 'Shared/Translations';
import Api from 'Shared/Api';

const StepWelcome = (props) => {
    const [ applyingRecommended, setApplyingRecommended ] = useState( false );

    const useRecommendedSettings = () => {
        if ( applyingRecommended ) {
            return;
        }

        setApplyingRecommended( true );

        const recommendedSettings = wprm_faq.recommended_settings || {};
        const saveRecommendedSettings = Object.keys( recommendedSettings ).length
            ? Api.settings.save( recommendedSettings )
            : Promise.resolve( true );

        saveRecommendedSettings.then( ( result ) => {
            setApplyingRecommended( false );

            if ( result ) {
                props.jumpToStep( 4 );
            }
        } );
    };

    return (
        <div className="wprm-admin-onboarding-step-welcome">
            <p>
                { __wprm( 'Welcome to WP Recipe Maker!' ) }
            </p>
            <p>
                { __wprm( 'These onboarding steps get you up and running quickly by' ) } <strong>{ __wprm( 'choosing the correct options for your situation' ) }</strong> { __wprm( 'and showing you how to get the most out of this plugin.' ) }
            </p>
            <p>
                { __wprm( 'Use the recommended recipe card and Jump to Recipe buttons, or customize each choice yourself.' ) }
            </p>
            <div className="wprm-admin-onboarding-step-welcome-buttons">
                <button
                    className="button button-primary button-compact"
                    onClick={ useRecommendedSettings }
                    disabled={ applyingRecommended }
                >
                    {
                        applyingRecommended
                        ? __wprm( 'Applying recommended settings...' )
                        : __wprm( 'Use recommended settings' )
                    }
                </button>
                <button
                    className="button button-secondary button-compact"
                    onClick={ () => props.jumpToStep( 1 ) }
                >{ __wprm( 'Customize setup' ) }</button>
                <a href={ wprm_admin.manage_url + '&skip_onboarding=1' }>{ __wprm( 'or click here to skip onboarding' ) }</a>
            </div>
        </div>
    );
}
export default StepWelcome;
