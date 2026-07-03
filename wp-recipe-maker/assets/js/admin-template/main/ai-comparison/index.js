import React, { useMemo } from 'react';
import { __wprm } from 'Shared/Translations';

import PreviewTemplate from '../preview-template';
import { getLineDiff } from './diff';

const getSideLabel = ( side ) => 'before' === side ? __wprm( 'Before' ) : __wprm( 'After' );

const ReadOnlyCodeDiff = ( props ) => {
    const diff = useMemo(
        () => getLineDiff( props.before || '', props.after || '' ),
        [ props.before, props.after ]
    );
    const lines = 'before' === props.side ? diff.before : diff.after;

    return (
        <div className="wprm-template-ai-code-diff" role="region" aria-label={ props.title }>
            {
                lines.map( ( item, index ) => (
                    <div
                        className={ item.changed ? 'wprm-template-ai-code-line wprm-template-ai-code-line-changed' : 'wprm-template-ai-code-line' }
                        key={ index }
                    >
                        <span className="wprm-template-ai-code-line-number">{ index + 1 }</span>
                        <code>{ '' === item.line ? ' ' : item.line }</code>
                    </div>
                ) )
            }
        </div>
    );
};

const TemplateAIComparison = ( props ) => {
    const proposal = props.proposal;

    if ( ! proposal ) {
        return null;
    }

    const side = 'before' === props.side ? 'before' : 'after';
    const sideLabel = getSideLabel( side );
    const previewTemplate = 'before' === side ? proposal.source.template : proposal.proposed.template;

    return (
        <div className="wprm-template-ai-comparison">
            <div className="wprm-template-ai-comparison-header">
                <h2>{ __wprm( 'AI Proposal Comparison' ) }</h2>
                <span>{ sideLabel }</span>
            </div>
            <div className="wprm-template-ai-comparison-section wprm-template-ai-comparison-preview">
                <PreviewTemplate
                    key={ `${ proposal.id }-${ side }-preview` }
                    mode="ai-comparison"
                    template={ previewTemplate }
                    onChangeHTML={ () => {} }
                    onChangeCSS={ () => {} }
                    onChangeMode={ () => {} }
                    editingBlock={ false }
                    onChangeEditingBlock={ () => {} }
                />
            </div>
            <div className="wprm-main-container wprm-template-ai-comparison-section">
                <h2 className="wprm-main-container-name">
                    { __wprm( 'HTML' ) } <span>{ sideLabel }</span>
                </h2>
                <ReadOnlyCodeDiff
                    title={ `${ __wprm( 'HTML' ) } ${ sideLabel }` }
                    before={ proposal.source.html }
                    after={ proposal.proposed.html }
                    side={ side }
                />
            </div>
            <div className="wprm-main-container wprm-template-ai-comparison-section">
                <h2 className="wprm-main-container-name">
                    { __wprm( 'CSS' ) } <span>{ sideLabel }</span>
                </h2>
                <ReadOnlyCodeDiff
                    title={ `${ __wprm( 'CSS' ) } ${ sideLabel }` }
                    before={ proposal.source.css }
                    after={ proposal.proposed.css }
                    side={ side }
                />
            </div>
        </div>
    );
};

export default TemplateAIComparison;
