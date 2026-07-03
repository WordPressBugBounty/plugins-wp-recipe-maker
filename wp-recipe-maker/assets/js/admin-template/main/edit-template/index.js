import React, { Fragment } from 'react';

import CSS from './CSS';
import HTML from './HTML';

const EditTemplate = (props) => {
    return (
        <Fragment>
            {
                'html' === props.mode
                &&
                <HTML
                    key={ `html-${ props.template.slug }-${ props.editorRefreshKey }` }
                    template={ props.template }
                    onChangeValue={ props.onChangeHTML }
                />
            }
            {
                'css' === props.mode
                &&
                <CSS
                    key={ `css-${ props.template.slug }-${ props.editorRefreshKey }` }
                    template={ props.template }
                    onChangeValue={ props.onChangeCSS }
                />
            }
        </Fragment>
    );
}

export default EditTemplate;
