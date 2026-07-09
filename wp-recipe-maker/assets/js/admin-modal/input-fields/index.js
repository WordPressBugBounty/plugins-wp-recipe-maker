import React, { Component, Fragment } from 'react';

import '../../../css/admin/modal/input-fields.scss';

import { __wprm } from 'Shared/Translations';
import Icon from 'Shared/Icon';
import Header from '../general/Header';
import Footer from '../general/Footer';

import FieldText from '../fields/FieldText';
import FieldTextarea from '../fields/FieldTextarea';
import FieldAsyncCreatableSingle from '../fields/FieldAsyncCreatableSingle';
import FieldCheckbox from '../fields/FieldCheckbox';
import FieldDropdown from '../fields/FieldDropdown';

export default class InputFields extends Component {
    constructor(props) {
        super(props);

        this.state = {
            fields: props.args.fields,
        };
    }

    render() {
        return (
            <Fragment>
                <Header
                    onCloseModal={ this.props.maybeCloseModal }
                >
                    { this.props.args.header }
                </Header>
                <div className="wprm-admin-modal-input-fields-container">
                    {
                        this.props.args.warning
                        &&
                        <div className="wprm-admin-modal-input-fields-warning">
                            <Icon type="warning" color="#996800" />
                            <span>{ this.props.args.warning }</span>
                        </div>
                    }
                    {
                        this.state.fields.map( (field, index) => {
                            let FieldComponent = FieldText;
                            const type = field.hasOwnProperty( 'type' ) ? field.type : 'text';

                            switch ( type ) {
                                case 'textarea':
                                    FieldComponent = FieldTextarea;
                                    break;
                                case 'async-creatable-single':
                                    FieldComponent = FieldAsyncCreatableSingle;
                                    break;
                                case 'checkbox':
                                    FieldComponent = FieldCheckbox;
                                    break;
                                case 'dropdown':
                                    FieldComponent = FieldDropdown;
                                    break;
                            }

                            const fieldInput = (
                                <FieldComponent
                                    { ...field }
                                    value={ field.value }
                                    onChange={ (value) => {
                                        let newFields = [ ...this.state.fields ];

                                        newFields[ index ].value = value;

                                        this.setState({
                                            fields: newFields,
                                        });
                                    }}
                                />
                            );
                            const fieldNotice = field.notice ? ( 'function' === typeof field.notice ? field.notice( this.state.fields, index ) : field.notice ) : '';

                            return (
                                <Fragment key={ index }>
                                    {
                                        field.hasOwnProperty( 'label' ) && 'checkbox' === type
                                        ?
                                        <label className="wprm-admin-modal-input-fields-checkbox-label">
                                            { fieldInput }
                                            <span>{ field.label }</span>
                                        </label>
                                        :
                                        field.hasOwnProperty( 'label' )
                                        && <div className="wprm-admin-modal-input-fields-field-label">{ field.label }</div>
                                    }
                                    { ( 'checkbox' !== type || ! field.hasOwnProperty( 'label' ) ) && fieldInput }
                                    {
                                        fieldNotice
                                        &&
                                        <div className="wprm-admin-modal-input-fields-field-notice">{ fieldNotice }</div>
                                    }
                                </Fragment>
                            )
                        })
                    }
                </div>
                <Footer
                    savingChanges={ false }
                >
                    <button
                        className="button button-primary button-compact"
                        onClick={ () => {
                            if ( 'function' === typeof this.props.args.insertCallback ) {
                                this.props.args.insertCallback( this.state );
                            }
                            this.props.maybeCloseModal();
                        } }
                    >
                        { __wprm( 'Change' ) }
                    </button>
                </Footer>
            </Fragment>
        );
    }
}
