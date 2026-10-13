import React, { Fragment, useRef } from 'react';
import Tippy from '@tippyjs/react';
import 'tippy.js/dist/tippy.css';

import { __wprm } from 'Shared/Translations';

// Issues that need their own explanation, instead of being listed as a missing field.
const getSpecialIssue = ( issue ) => {
    const data = issue.data ? issue.data : {};

    switch ( issue.id ) {
        case 'not_in_post':
            return {
                title: __wprm( 'Not used in any post' ),
                detail: __wprm( 'Google only sees recipe metadata on the post a recipe is in. Already in a post? Use "Find Parent Posts" on the Tools page.' ),
                action: __wprm( 'Add to post' ),
            };
        case 'not_first_recipe':
            return {
                title: __wprm( 'Not the first recipe in its post' ),
                detail: __wprm( 'Your settings only output metadata for the first recipe in a post.' ),
                action: __wprm( 'Change setting' ),
            };
        case 'video_unavailable':
            return {
                title: __wprm( 'Video left out of the metadata' ),
                detail: __wprm( 'Its details could not be retrieved. Check the video or use "Refresh Video Metadata" on the Tools page.' ),
                action: __wprm( 'Check video' ),
            };
        case 'rating':
            return {
                title: __wprm( 'No ratings yet' ),
                detail: __wprm( 'Ratings come from your visitors and can show up as stars in search results.' ),
            };
        case 'ratings_disabled':
            return {
                title: __wprm( 'Star ratings are turned off' ),
                detail: __wprm( 'Turn on comment ratings or user ratings so visitors can rate this recipe.' ),
                action: __wprm( 'Change setting' ),
            };
        case 'image_size':
            return {
                title: __wprm( 'Image is only %width%px wide' ).replace( '%width%', data.width ),
                detail: __wprm( 'Google recommends at least %width%px wide for large previews in Search and Discover.' ).replace( '%width%', data.recommended ),
                action: __wprm( 'Fix' ),
            };
    }

    return false;
};

const getFieldLabel = ( id, recipeType ) => {
    const howto = 'howto' === recipeType;

    switch ( id ) {
        case 'name': return __wprm( 'Name' );
        case 'summary': return howto ? __wprm( 'Description' ) : __wprm( 'Summary' );
        case 'servings': return __wprm( 'Servings' );
        case 'times': return howto ? __wprm( 'Total Time' ) : __wprm( 'Times' );
        case 'ingredients': return howto ? __wprm( 'Materials' ) : __wprm( 'Ingredients' );
        case 'instructions': return __wprm( 'Instructions' );
        case 'course': return __wprm( 'Course' );
        case 'cuisine': return __wprm( 'Cuisine' );
        case 'keyword': return __wprm( 'Keyword' );
        case 'calories': return __wprm( 'Calories' );
        case 'image': return __wprm( 'Image' );
        case 'cost': return __wprm( 'Estimated Cost' );
        case 'equipment': return __wprm( 'Equipment' );
        case 'video_name': return __wprm( 'Video name' );
        case 'video_image': return __wprm( 'Video thumbnail' );
        case 'video_upload_date': return __wprm( 'Video upload date' );
        case 'video_description': return __wprm( 'Video description' );
    }

    return id;
};

// Combine missing fields with the same level and target into a single row. Issues are stored most severe first.
const getRows = ( issues, recipeType ) => {
    const rows = [];

    issues.forEach( ( issue ) => {
        const special = getSpecialIssue( issue );

        if ( special ) {
            rows.push( {
                key: issue.id,
                level: issue.level,
                target: issue.target,
                ...special,
            } );
            return;
        }

        const key = `${ issue.level }-${ issue.target }`;
        let row = rows.find( ( existing ) => existing.key === key );

        if ( ! row ) {
            row = {
                key,
                level: issue.level,
                target: issue.target,
                labels: [],
                detail: 'required' === issue.level ? __wprm( 'Required for recipe rich results' ) : __wprm( 'Recommended by Google' ),
                action: __wprm( 'Fix' ),
            };
            rows.push( row );
        }

        row.labels.push( getFieldLabel( issue.id, recipeType ) );
        row.title = row.labels.join( ', ' );
    } );

    return rows;
};

const getHeadline = ( type, issues ) => {
    switch ( type ) {
        case 'bad':
            if ( issues.some( ( issue ) => 'not_in_post' === issue.id || 'not_first_recipe' === issue.id ) ) {
                return __wprm( 'Google can\'t see this recipe\'s metadata' );
            }
            return __wprm( 'Not eligible for recipe rich results' );
        case 'warning':
            return __wprm( 'Eligible for rich results, but incomplete' );
        case 'rating':
            return __wprm( 'Complete, apart from ratings' );
        case 'good':
            return __wprm( 'All set for recipe rich results' );
    }

    return false;
};

const SeoIndicator = (props) => {
    const tippyInstance = useRef( null );

    if ( ! props.seo ) {
        return null;
    }

    const type = props.seo.type.trim();
    const canOpen = 'function' === typeof props.onOpenTarget;
    const issues = Array.isArray( props.seo.issues ) ? props.seo.issues : false;
    const notes = Array.isArray( props.seo.notes ) ? props.seo.notes : [];
    const headline = issues ? getHeadline( type, issues ) : false;

    const openTarget = ( target ) => {
        if ( tippyInstance.current ) {
            tippyInstance.current.hide();
        }
        props.onOpenTarget( target );
    };

    let content;
    if ( headline ) {
        const rows = getRows( issues, props.recipeType );

        content = (
            <div className="wprm-admin-manage-seo-popover">
                <div className={ `wprm-admin-manage-seo-popover-header wprm-admin-manage-seo-popover-header-${ type }` }>{ headline }</div>
                {
                    0 < rows.length
                    &&
                    <ul className="wprm-admin-manage-seo-popover-issues">
                        {
                            rows.map( ( row ) => (
                                <li className={ `wprm-admin-manage-seo-popover-issue wprm-admin-manage-seo-popover-issue-${ row.level }` } key={ row.key }>
                                    <span className="wprm-admin-manage-seo-popover-issue-dot" />
                                    <div className="wprm-admin-manage-seo-popover-issue-text">
                                        <div className="wprm-admin-manage-seo-popover-issue-title">{ row.title }</div>
                                        { row.detail && <div className="wprm-admin-manage-seo-popover-issue-detail">{ row.detail }</div> }
                                    </div>
                                    {
                                        canOpen && row.target && row.action
                                        &&
                                        <button
                                            type="button"
                                            className="button button-small"
                                            onClick={ () => openTarget( row.target ) }
                                        >{ row.action }</button>
                                    }
                                </li>
                            ) )
                        }
                    </ul>
                }
                {
                    0 < notes.length
                    &&
                    <div className="wprm-admin-manage-seo-popover-notes">
                        { notes.map( ( note, index ) => <p key={ index } dangerouslySetInnerHTML={ { __html: note } } /> ) }
                    </div>
                }
                {
                    props.testUrl
                    &&
                    <div className="wprm-admin-manage-seo-popover-test">
                        <span>{ __wprm( 'Test the live page:' ) }</span>
                        <a
                            href={ `https://search.google.com/test/rich-results?url=${ encodeURIComponent( props.testUrl ) }` }
                            target="_blank"
                            rel="noopener noreferrer"
                        >{ __wprm( 'Google Rich Results Test' ) }</a>
                        <a
                            href={ `https://validator.schema.org/#url=${ encodeURIComponent( props.testUrl ) }` }
                            target="_blank"
                            rel="noopener noreferrer"
                        >{ __wprm( 'Schema.org Validator' ) }</a>
                    </div>
                }
            </div>
        );
    } else {
        // Results from before the issue list, or recipes that don't get checked.
        content = (
            <div className="wprm-admin-manage-seo-popover">
                <div className="wprm-admin-manage-seo-popover-message" dangerouslySetInnerHTML={ { __html: props.seo.message } } />
            </div>
        );
    }

    return (
        <Tippy
            content={ content }
            interactive={ true }
            interactiveBorder={ 10 }
            appendTo={ () => document.body }
            placement="right"
            maxWidth={ 380 }
            delay={ [ 100, 100 ] }
            theme="wprm-seo"
            zIndex={ 100004 }
            onCreate={ ( instance ) => { tippyInstance.current = instance; } }
        >
            <div
                className={ `wprm-admin-manage-seo-indicator wprm-admin-manage-seo-indicator-${ type }${ canOpen ? ' wprm-admin-manage-seo-indicator-clickable' : '' }` }
                onClick={ canOpen ? () => openTarget( false ) : undefined }
            >
                {
                    'other' === type
                    ?
                    'n/a'
                    :
                    <Fragment>
                        {
                            'missing' === type
                            ?
                            __wprm( 'missing' )
                            :
                            <Fragment>
                                <div className="wprm-admin-manage-seo-indicator-block"></div>
                                <div className="wprm-admin-manage-seo-indicator-block"></div>
                                <div className="wprm-admin-manage-seo-indicator-block"></div>
                                <div className="wprm-admin-manage-seo-indicator-block"></div>
                            </Fragment>
                        }
                    </Fragment>
                }
            </div>
        </Tippy>
    );
}
export default SeoIndicator;
