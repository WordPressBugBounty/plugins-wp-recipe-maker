import React, { Component, Fragment } from 'react';
import he from 'he';

import '../../../../css/admin/modal/bulk-add.scss';

import Header from '../../general/Header';
import Footer from '../../general/Footer';
import { __wprm } from 'Shared/Translations';

import FieldContainer from '../../fields/FieldContainer';

import Api from 'Shared/Api';
import SelectGroups from '../text-import/SelectGroups';

export default class BulkAdd extends Component {
    constructor(props) {
        super(props);

        this.textInput = React.createRef();

        this.state = {
            text: '',
            value: false,
            parsedValue: false,
            isParsing: false,
            parseError: false,
        };

        this.cleanUpText = this.cleanUpText.bind(this);
        this.parseIngredients = this.parseIngredients.bind(this);
        this.useValues = this.useValues.bind(this);
    }

    componentDidMount() {
        this.textInput.current.focus();
    }

    cleanUpText( text ) {
        text = text.replace( /(<([^>]+)>)/ig, '' );
        text = he.decode( text );

        return text;
    }

    getSeperateFields( content ) {
        if ( false === content ) {
            return false;
        }

        // Splitting on punctuation as well?
        if ( 'instructions' === this.props.field && 'punctuation' === wprm_admin_modal.settings.import_instructions_split ) {
            content = content.replace(/([!\.\?]+)/gm, '$1\n');
        }

        // Split into seperate lines.
        let fields = [];
        let lines = content.split(/[\r\n]+/);

        // Loop over all lines in selection.
        for ( let line of lines ) {
            // Trim and remove bullet points.
            line = line.trim();
            line = line.replace(/^(\d+\)\s+|\d+\.\s+|[a-z]+\)\s+|•\s+|[A-Z]+\.\s+|[IVX]+\.\s+)/, '');

            if ( line ) {
                fields.push({
                    group: false,
                    text: line,
                });
            }
        }

        // Return false if there weren't any non-empty lines.
        if ( ! fields.length ) {
            return false;
        }

        return fields;
    }

    getBulkAddCallback() {
        return this.props.onBulkAdd || this.props.args?.onBulkAdd;
    }

    getIngredientValues() {
        let ingredients_flat = [];
        let ingredientsToParse = {};

        this.state.value.map((ingredient, index) => {
            if ( ingredient.group ) {
                ingredients_flat.push({
                    uid: index,
                    type: 'group',
                    name: ingredient.text,
                });
            } else {
                ingredients_flat.push({
                    uid: index,
                    type: 'ingredient',
                    amount: '',
                    unit: '',
                    name: '',
                    notes: '',
                });

                ingredientsToParse[ index ] = ingredient.text;
            }
        });

        return {
            ingredients_flat,
            ingredientsToParse,
        };
    }

    parseIngredients() {
        const {
            ingredients_flat,
            ingredientsToParse,
        } = this.getIngredientValues();

        if ( ! Object.keys( ingredientsToParse ).length ) {
            this.setState({
                parsedValue: ingredients_flat,
                parseError: false,
            });
            return;
        }

        this.setState({
            isParsing: true,
            parseError: false,
        }, () => {
            Api.import.parseIngredients(ingredientsToParse).then((data) => {
                if ( data ) {
                    for ( let index in data.parsed ) {
                        ingredients_flat[ index ] = {
                            ...ingredients_flat[ index ],
                            ...data.parsed[ index ],
                        };
                    }

                    this.setState({
                        parsedValue: ingredients_flat,
                        isParsing: false,
                    });
                } else {
                    this.setState({
                        isParsing: false,
                        parseError: true,
                    });
                }
            }).catch(() => {
                this.setState({
                    isParsing: false,
                    parseError: true,
                });
            });
        });
    }

    useValues() {
        // Instructions.
        if ( 'instructions' === this.props.field ) {
            let instructions_flat = [];

            this.state.value.map( ( instruction, index ) => {
                if ( instruction.group ) {
                    instructions_flat.push({
                        uid: index,
                        type: 'group',
                        name: instruction.text,
                    });
                } else {
                    instructions_flat.push({
                        uid: index,
                        type: 'instruction',
                        text: instruction.text,
                        image: 0,
                        image_url: '',
                    });
                }
            });

            const onBulkAdd = this.getBulkAddCallback();
            if (onBulkAdd) {
                onBulkAdd( instructions_flat );
            }
            this.props.maybeCloseModal();
            return;
        }

        // Ingredients.
        if ( 'ingredients' === this.props.field ) {
            if ( false === this.state.parsedValue ) {
                this.parseIngredients();
                return;
            }

            const onBulkAdd = this.getBulkAddCallback();
            if (onBulkAdd) {
                onBulkAdd( this.state.parsedValue );
            }
            this.props.maybeCloseModal();
        }
    }

    renderParsedIngredients() {
        if ( false === this.state.parsedValue ) {
            return null;
        }

        return (
            <Fragment>
                <h2>3. { __wprm( 'Check parsed ingredients' ) }</h2>
                <p className="wprm-admin-modal-bulk-add-help">
                    { __wprm( 'Edit any amount, unit, name, or note before adding these ingredients to your recipe.' ) }
                </p>
                <div className="wprm-admin-modal-bulk-add-ingredient-preview">
                    <div className="wprm-admin-modal-bulk-add-ingredient-preview-header" aria-hidden="true">
                        <span>{ __wprm( 'Amount' ) }</span>
                        <span>{ __wprm( 'Unit' ) }</span>
                        <span>{ __wprm( 'Name' ) }</span>
                        <span>{ __wprm( 'Notes' ) }</span>
                    </div>
                    {
                        this.state.parsedValue.map((field, index) => {
                            if ( 'group' === field.type ) {
                                return (
                                    <label className="wprm-admin-modal-bulk-add-ingredient-preview-group" key={ index }>
                                        <span>{ __wprm( 'Group heading' ) }</span>
                                        <input
                                            type="text"
                                            value={ field.name }
                                            onChange={(e) => {
                                                let parsedValue = JSON.parse( JSON.stringify( this.state.parsedValue ) );
                                                parsedValue[ index ].name = e.target.value;
                                                this.setState({ parsedValue });
                                            }}
                                        />
                                    </label>
                                );
                            }

                            return (
                                <div className="wprm-admin-modal-bulk-add-ingredient-preview-row" key={ index }>
                                    {
                                        [
                                            [ 'amount', __wprm( 'Amount' ) ],
                                            [ 'unit', __wprm( 'Unit' ) ],
                                            [ 'name', __wprm( 'Name' ) ],
                                            [ 'notes', __wprm( 'Notes' ) ],
                                        ].map(([key, label]) => (
                                            <label key={ key }>
                                                <span>{ label }</span>
                                                <input
                                                    type="text"
                                                    value={ field[ key ] || '' }
                                                    onChange={(e) => {
                                                        let parsedValue = JSON.parse( JSON.stringify( this.state.parsedValue ) );
                                                        parsedValue[ index ][ key ] = e.target.value;
                                                        this.setState({ parsedValue });
                                                    }}
                                                />
                                            </label>
                                        ))
                                    }
                                </div>
                            );
                        })
                    }
                </div>
            </Fragment>
        );
    }

    render() {
        const changesMade = false !== this.state.value;
        const isIngredients = 'ingredients' === this.props.field;
        const example = isIngredients ? '1/2 red pepper, thinly sliced' : 'Bake for 12 minutes until golden.';
        const actionLabel = isIngredients
            ? ( false === this.state.parsedValue ? __wprm( 'Preview ingredients' ) : __wprm( 'Add ingredients' ) )
            : __wprm( 'Add instructions' );

        return (
            <Fragment>
                <Header
                    onCloseModal={ this.props.maybeCloseModal }
                >
                    {
                        isIngredients ? __wprm( 'Paste ingredients' ) : __wprm( 'Paste instructions' )
                    }
                </Header>
                <div
                    className={ `wprm-admin-modal-bulk-add-container wprm-admin-modal-bulk-add-${ this.props.field }-container` }
                >
                    <h2>1. { isIngredients ? __wprm( 'Paste your ingredients, one per line.' ) : __wprm( 'Paste your instructions, one step per line.' ) }</h2>
                    <div className="wprm-admin-modal-bulk-add-input">
                        <textarea
                            ref={this.textInput}
                            value={this.state.text}
                            placeholder={ example }
                            aria-label={ isIngredients ? __wprm( 'Ingredients to paste' ) : __wprm( 'Instructions to paste' ) }
                            onChange={(e) => {
                                const text = this.cleanUpText( e.target.value );
                                const value = text ? this.getSeperateFields( text ) : false;

                                this.setState({
                                    text,
                                    value,
                                    parsedValue: false,
                                    parseError: false,
                                });
                            }}
                        />
                    </div>
                    <p className="wprm-admin-modal-bulk-add-example">
                        <strong>{ __wprm( 'Example' ) }:</strong> <code>{ example }</code>
                    </p>
                    <h2>2. { __wprm( 'Review lines and group headings' ) }</h2>
                    <div className="wprm-admin-modal-bulk-add-input-finetune">
                        {
                            ! this.state.text
                            ?
                            <p>{ isIngredients ? __wprm( 'Paste ingredients above to review them.' ) : __wprm( 'Paste instructions above to review them.' ) }</p>
                            :
                            <FieldContainer label={ isIngredients ? __wprm( 'Ingredients' ) : __wprm( 'Instructions' ) }>
                                <SelectGroups
                                    field={ this.props.field }
                                    value={ this.state.value }
                                    onChange={ (value) => {
                                        this.setState({
                                            value,
                                            parsedValue: false,
                                            parseError: false,
                                        });
                                    }}
                                />
                            </FieldContainer>
                        }
                    </div>
                    { isIngredients && this.renderParsedIngredients() }
                    {
                        this.state.parseError
                        &&
                        <p className="wprm-admin-modal-bulk-add-error" role="alert">
                            { __wprm( 'The ingredients could not be parsed. Please try again.' ) }
                        </p>
                    }
                </div>
                <Footer
                    savingChanges={ this.state.isParsing }
                >
                    <button
                        type="button"
                        className="button button-secondary button-compact"
                        onClick={ this.props.maybeCloseModal }
                    >
                        { __wprm( 'Cancel' ) }
                    </button>
                    <button
                        type="button"
                        className="button button-primary button-compact"
                        onClick={ this.useValues }
                        disabled={ ! changesMade }
                    >
                        { actionLabel }
                    </button>
                </Footer>
            </Fragment>
        );
    }
}
