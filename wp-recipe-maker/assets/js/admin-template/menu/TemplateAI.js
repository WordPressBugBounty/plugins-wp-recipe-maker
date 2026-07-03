import React, { useEffect, useState } from 'react';
import { __wprm } from 'Shared/Translations';

import Api from 'Shared/Api';
import Helpers from '../general/Helpers';
import AdminIcon from 'Shared/Icon';
import Loader from 'Shared/Loader';
import Tooltip from 'Shared/Tooltip';

const getTemplateCss = ( template ) => {
    if ( ! template ) {
        return '';
    }

    if ( template.style && 'undefined' !== typeof template.style.css ) {
        return template.style.css;
    }

    return template.css || '';
};

const prepareTemplateForRequest = ( template ) => {
    return {
        name: template.name || '',
        slug: template.slug || '',
        type: template.type || 'recipe',
        html: template.html || '',
        css: getTemplateCss( template ),
        compiled_css: Helpers.parseCSS( template ),
        fonts: template.fonts || [],
    };
};

const getProposalSupportResponse = ( proposal ) => {
    const fallbackResponse = {
        summary: proposal.summary || '',
        warnings: proposal.warnings || [],
        html: proposal.proposed && 'string' === typeof proposal.proposed.html ? proposal.proposed.html : '',
        css: proposal.proposed && 'string' === typeof proposal.proposed.css ? proposal.proposed.css : '',
    };

    try {
        return JSON.stringify( proposal.rawResponse || fallbackResponse, null, 2 );
    } catch ( e ) {
        return JSON.stringify( fallbackResponse, null, 2 );
    }
};

const TemplateAI = ( props ) => {
    const [ prompt, setPrompt ] = useState( '' );
    const [ loading, setLoading ] = useState( false );
    const [ error, setError ] = useState( '' );
    const [ showWarnings, setShowWarnings ] = useState( false );

    const hasEliteAccess = !! ( props.aiTemplateEditor && props.aiTemplateEditor.enabled && props.aiTemplateEditor.endpoint );
    const canRun = hasEliteAccess && props.template && prompt.trim() && ! loading;
    const proposal = props.proposal;
    const comparisonSide = 'before' === props.comparisonSide ? 'before' : 'after';

    useEffect( () => {
        setShowWarnings( false );
    }, [ proposal ? proposal.id : false ] );

    const openAiDocumentation = () => {
        if ( confirm( __wprm( 'Want to learn more about the AI features?' ) ) ) {
            window.open( 'https://help.bootstrapped.ventures/docs/wp-recipe-maker/ai-assistant/', '_blank' );
        }
    };

    const requestSuggestion = () => {
        if ( ! canRun ) {
            return;
        }

        const sourceTemplate = props.onStartTemplateAIRequest ? props.onStartTemplateAIRequest() : props.template;
        if ( ! sourceTemplate ) {
            return;
        }

        setLoading( true );
        setError( '' );

        Api.template.suggestTemplateChanges( {
            operation: 'edit',
            prompt: prompt.trim(),
            template: prepareTemplateForRequest( sourceTemplate ),
        } ).then( ( response ) => {
            if ( response && response.rejected_reason ) {
                setError( response.rejected_reason );
                return;
            }

            if ( response && response.html && response.css ) {
                props.onSetTemplateAIProposal( response, sourceTemplate, prompt.trim() );
                return;
            }

            setError( __wprm( 'The AI response did not include a valid template proposal.' ) );
        } ).catch( ( response ) => {
            const message = response && response.message ? response.message : __wprm( 'Something went wrong. Please try again.' );
            setError( message );
        } ).finally( () => {
            setLoading( false );
        } );
    };

    const sendExampleToSupport = () => {
        if ( ! proposal ) {
            return;
        }

        const confirmMessage = __wprm( 'The AI Assistant is still in beta and being actively improved.' )
            + '\r\n\r\n'
            + __wprm( 'We would love to get this example from you so we can improve the system. This will open an email to our support team with your prompt and the full AI response included.' )
            + '\r\n\r\n'
            + __wprm( 'Do you want to send this example to support@bootstrapped.ventures?' );

        if ( ! confirm( confirmMessage ) ) {
            return;
        }

        const templateName = props.template && props.template.name ? props.template.name : '';
        const templateSlug = props.template && props.template.slug ? props.template.slug : '';
        const requestPrompt = proposal.prompt || prompt.trim();
        const response = getProposalSupportResponse( proposal );

        const email = 'support@bootstrapped.ventures';
        const subject = 'WP Recipe Maker AI Assistant Template Example';
        const body = [
            'I was not happy with the AI Assistant result below.',
            '',
            `Page: ${ window.location.href }`,
            `Template: ${ templateName }${ templateSlug ? ` (${ templateSlug })` : '' }`,
            '',
            'Prompt:',
            requestPrompt,
            '',
            'AI response:',
            response,
        ].join( '\r\n' );

        window.open( `mailto:${ encodeURIComponent( email ) }?subject=${ encodeURIComponent( subject ) }&body=${ encodeURIComponent( body ) }` );
    };

    return (
        <div className="wprm-template-properties wprm-template-ai-panel">
            <textarea
                className="wprm-template-ai-prompt"
                rows="5"
                value={ prompt }
                placeholder={ __wprm( 'Describe the template changes you want.' ) }
                onChange={ ( e ) => setPrompt( e.target.value ) }
                disabled={ loading }
            />
            <div className="wprm-template-ai-actions">
                {
                    hasEliteAccess
                    ?
                    <>
                        <button
                            type="button"
                            className="button button-primary button-compact wprm-button-ai"
                            disabled={ ! canRun }
                            onClick={ requestSuggestion }
                        ><AdminIcon type="sparks" color="currentColor" />{ __wprm( 'Suggest Changes' ) }</button>
                    </>
                    :
                    <Tooltip content={ __wprm( 'AI features are only available in the Elite Bundle during beta. Click to learn more.' ) }>
                        <button
                            type="button"
                            className="button button-primary button-compact wprm-button-ai wprm-button-required"
                            onClick={ openAiDocumentation }
                        ><AdminIcon type="sparks" color="currentColor" />{ __wprm( 'Suggest Changes' ) }</button>
                    </Tooltip>
                }
            </div>
            {
                loading
                &&
                <div className="wprm-template-ai-loading">
                    <Loader/>
                    <span>{ __wprm( 'Generating proposal...' ) }</span>
                </div>
            }
            {
                error
                &&
                <div className="wprm-template-ai-message wprm-template-ai-error">{ error }</div>
            }
            {
                proposal
                &&
                <div className="wprm-template-ai-result">
                    {
                        proposal.summary
                        &&
                        <div className="wprm-template-ai-message">{ proposal.summary }</div>
                    }
                    {
                        proposal.warnings && 0 < proposal.warnings.length
                        &&
                        <>
                            <button
                                type="button"
                                className="wprm-template-ai-notes-toggle"
                                aria-expanded={ showWarnings }
                                onClick={ () => setShowWarnings( ! showWarnings ) }
                            >{ showWarnings ? __wprm( 'Hide AI notes' ) : __wprm( 'Show AI notes' ) }</button>
                            {
                                showWarnings
                                &&
                                <div className="wprm-template-ai-warnings">
                                    {
                                        proposal.warnings.map( ( warning, index ) => (
                                            <div className="wprm-template-ai-warning" key={ index }>{ warning }</div>
                                        ) )
                                    }
                                </div>
                            }
                        </>
                    }
                    <div className="wprm-template-ai-compare-toggle" role="group" aria-label={ __wprm( 'Compare AI proposal' ) }>
                        <button
                            type="button"
                            className={ 'before' === comparisonSide ? 'button button-primary button-compact active' : 'button button-secondary button-compact' }
                            aria-pressed={ 'before' === comparisonSide }
                            onClick={ () => props.onChangeTemplateAIComparisonSide( 'before' ) }
                        >{ __wprm( 'Before' ) }</button>
                        <button
                            type="button"
                            className={ 'after' === comparisonSide ? 'button button-primary button-compact active' : 'button button-secondary button-compact' }
                            aria-pressed={ 'after' === comparisonSide }
                            onClick={ () => props.onChangeTemplateAIComparisonSide( 'after' ) }
                        >{ __wprm( 'After' ) }</button>
                    </div>
                    <div className="wprm-template-ai-actions">
                        <button
                            type="button"
                            className="button button-primary button-compact"
                            onClick={ () => {
                                props.onApplyTemplateAIResult( {
                                    html: proposal.proposed.html,
                                    css: proposal.proposed.css,
                                } );
                            } }
                        >{ __wprm( 'Apply' ) }</button>
                        <button
                            type="button"
                            className="button button-secondary button-compact"
                            onClick={ () => props.onDiscardTemplateAIProposal() }
                        >{ __wprm( 'Discard' ) }</button>
                    </div>
                    <button
                        type="button"
                        className="wprm-template-ai-feedback-link"
                        onClick={ sendExampleToSupport }
                    >{ __wprm( 'Not happy with the result? Send us the example!' ) }</button>
                </div>
            }
        </div>
    );
};

export default TemplateAI;
