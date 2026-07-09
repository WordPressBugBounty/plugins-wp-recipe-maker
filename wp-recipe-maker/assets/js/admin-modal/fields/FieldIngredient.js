import React, { Component } from 'react';
import { Draggable } from 'react-beautiful-dnd';
import { isKeyHotkey } from 'is-hotkey';
import he from 'he';

const isTabHotkey = isKeyHotkey('tab');

import Api from 'Shared/Api';
import Helpers from 'Shared/Helpers';
import Icon from 'Shared/Icon';
import Tooltip from 'Shared/Tooltip';
import { __wprm } from 'Shared/Translations';

import FieldRichText from './FieldRichText';

const connectorSpacingOptions = [
    { value: 'space-both', label: __wprm( 'Space before and after' ) },
    { value: 'space-before', label: __wprm( 'Space before only' ) },
    { value: 'space-after', label: __wprm( 'Space after only' ) },
    { value: 'no-space', label: __wprm( 'No spaces' ) },
];
const connectorScopeWarning = __wprm( 'This connector is saved for the ingredient unit. Setting or changing it will affect all recipes using this unit.' );
const connectorButtonTooltip = __wprm( 'Set or change the connector between this unit and the ingredient name.' );

const getPlainText = (value = '') => {
    return he.decode( `${ value }`.replace( /<[^>]*>/g, '' ) ).trim();
};

const getConnectorMode = () => {
    if ( typeof wprm_admin === 'undefined' || ! wprm_admin.settings ) {
        return 'hide';
    }

    return wprm_admin.settings.recipe_modal_ingredient_unit_connectors || 'auto';
};

const isConnectorAutoEnabled = () => {
    return !! (
        typeof wprm_admin !== 'undefined'
        && wprm_admin.settings
        && wprm_admin.settings.recipe_modal_ingredient_unit_connectors_auto_enabled
    );
};

const hasConnectorApi = () => {
    return !! (
        Api
        && Api.modal
        && 'function' === typeof Api.modal.getIngredientUnitConnector
    );
};

const getConnectorElisionNotice = (connector = '') => {
    const normalized = getPlainText( connector ).toLowerCase();
    const settings = Helpers.getConnectorElisionSettings();
    const connectors = settings.connectors && 'object' === typeof settings.connectors ? settings.connectors : {};

    if ( ! normalized || ! Object.keys( connectors ).length ) {
        return '';
    }

    if ( connectors[ normalized ] ) {
        return `${ __wprm( 'WPRM can automatically show' ) } "${ connectors[ normalized ] }" ${ __wprm( 'before matching ingredient names. Keep the base connector here so other ingredients can still show' ) } "${ normalized }".`;
    }

    const baseConnector = Object.keys( connectors ).find( ( base ) => {
        return String( connectors[ base ] ).toLowerCase() === normalized;
    });

    if ( baseConnector ) {
        return `${ __wprm( 'Use' ) } "${ baseConnector }" ${ __wprm( 'as the connector. WPRM will automatically show' ) } "${ connectors[ baseConnector ] }" ${ __wprm( 'before matching ingredient names where appropriate.' ) }`;
    }

    return '';
};
 
const handle = (provided) => (
    <div
        className="wprm-admin-modal-field-ingredient-handle"
        {...provided.dragHandleProps}
        tabIndex="-1"
    ><Icon type="drag" /></div>
);

const group = (props, provided) => (
    <div
        className="wprm-admin-modal-field-ingredient-group"
        ref={provided.innerRef}
        {...provided.draggableProps}
    >
        { handle(provided) }
        <div className="wprm-admin-modal-field-ingredient-group-name-container">
            <FieldRichText
                singleLine
                className="wprm-admin-modal-field-ingredient-group-name"
                toolbar="no-styling"
                value={ props.name }
                placeholder={ 'howto' === props.recipeType ? __wprm( 'Material Group Header' ) : __wprm( 'Ingredient Group Header' ) }
                onChange={(value, changeOptions = {}) => props.onChangeName(value, changeOptions)}
                onKeyDown={(event) => {
                    if ( isTabHotkey(event) ) {
                        props.onTab(event);
                    }
                }}
            />
        </div>
        <div className="wprm-admin-modal-field-ingredient-after-container">
            <div className="wprm-admin-modal-field-ingredient-after-container-icons">
                <Icon
                    type="trash"
                    title={ __wprm( 'Remove' ) }
                    onClick={ props.onDelete }
                />
                <Icon
                    type="plus-text"
                    title={ __wprm( 'Insert Group After' ) }
                    onClick={ props.onAddGroup }
                />
                <Icon
                    type="plus"
                    title={ __wprm( 'Insert Ingredient After' ) }
                    onClick={ props.onAdd }
                />
            </div>
        </div>
    </div>
);

const ingredient = (props, provided) => {
    let amount = props.amount;
    let unit = props.unit;
    const unitName = getPlainText( unit );

    const hasSplits = props.splits && props.splits.length > 0;
    const connectorData = props.unitConnectorData && props.unitConnectorData.connector ? props.unitConnectorData : false;
    const displayConnectorData = connectorData ? Helpers.resolveConnectorDataForName( connectorData, props.name ) : false;
    const connector = displayConnectorData && displayConnectorData.connector ? displayConnectorData.connector : '';
    const showConnector = props.showConnectorControl;
    const connectorDisabled = ! unitName || props.unitConnectorLoading;
    const connectorTooltip = unitName ? connectorButtonTooltip : __wprm( 'Add a unit before setting a connector.' );

    return (
        <div
            className="wprm-admin-modal-field-ingredient"
            ref={provided.innerRef}
            {...provided.draggableProps}
        >
            { handle(provided) }
            <div className="wprm-admin-modal-field-ingredient-text-container">
                <FieldRichText
                    singleLine
                    toolbar={ wprm_admin.addons.premium ? 'all' : 'no-link' }
                    className="wprm-admin-modal-field-ingredient-amount"
                    value={ amount }
                    placeholder="1"
                    onChange={(amount, changeOptions = {}) => {
                        props.onChangeIngredient({amount}, changeOptions);
                    }}
                />
                <FieldRichText
                    singleLine
                    toolbar="ingredient-unit"
                    className="wprm-admin-modal-field-ingredient-unit"
                    value={ unit }
                    placeholder={ 'howto' === props.recipeType ? __wprm( 'piece' ) : __wprm( 'tbsp' ) }
                    onChange={(unit, changeOptions = {}) => {
                        props.onChangeIngredient({unit}, changeOptions);
                    }}
                />
                <span className="wprm-admin-modal-field-ingredient-name-container">
                    {
                        showConnector
                        &&
                        <span className="wprm-admin-modal-field-ingredient-connector-container">
                            <Tooltip content={ connectorTooltip }>
                                <span className="wprm-admin-modal-field-ingredient-connector-tooltip">
                                    <button
                                        type="button"
                                        className={ `wprm-admin-modal-field-ingredient-connector${ connector ? ' wprm-admin-modal-field-ingredient-connector-has-value' : '' }` }
                                        aria-label={ connector ? __wprm( 'Change Connector' ) : __wprm( 'Set Connector' ) }
                                        disabled={ connectorDisabled }
                                        onClick={ props.onConnectorClick }
                                    >
                                        { props.unitConnectorLoading ? '...' : connector ? connector : '+' }
                                    </button>
                                </span>
                            </Tooltip>
                        </span>
                    }
                    <FieldRichText
                        singleLine
                        toolbar="ingredient"
                        className="wprm-admin-modal-field-ingredient-name"
                        value={ props.name }
                        placeholder={ 'howto' === props.recipeType ? __wprm( 'paper' ) : __wprm( 'olive oil' ) }
                        onChange={(name, changeOptions = {}) => {
                            props.onChangeIngredient({
                                name,
                                globalLink: false, // Changing names will lead to a different global link.
                            }, changeOptions);
                    }}
                    />
                </span>
                <FieldRichText
                    singleLine
                    toolbar={ wprm_admin.addons.premium ? 'all' : 'no-link' }
                    className="wprm-admin-modal-field-ingredient-notes"
                    value={ props.notes }
                    placeholder={ 'howto' === props.recipeType ? __wprm( 'any color' ) : __wprm( 'extra virgin' ) }
                    onChange={(notes, changeOptions = {}) => props.onChangeIngredient({notes}, changeOptions)}
                    onKeyDown={(event) => {
                        if ( isTabHotkey(event) ) {
                            props.onTab(event);
                        }
                    }}
                />
            </div>
            <div className="wprm-admin-modal-field-ingredient-after-container">
                <div className="wprm-admin-modal-field-ingredient-after-container-icons">
                    <Icon
                        type={ hasSplits ? 'split-thick' : 'split' }
                        title={ __wprm( 'Split Ingredient' ) }
                        onClick={ props.onSplit }
                        className={ hasSplits ? 'wprm-admin-icon-split-active' : '' }
                        color={ hasSplits ? '#2271b1' : undefined }
                    />
                    <Icon
                        type="trash"
                        title={ __wprm( 'Remove' ) }
                        onClick={ props.onDelete }
                    />
                    <Icon
                        type="plus-text"
                        title={ __wprm( 'Insert Group After' ) }
                        onClick={ props.onAddGroup }
                    />
                    <Icon
                        type="plus"
                        title={ __wprm( 'Insert Ingredient After' ) }
                        onClick={ props.onAdd }
                    />
                </div>
            </div>
        </div>
    );
};

export default class FieldIngredient extends Component {
    constructor(props) {
        super(props);

        this.state = {
            connectorData: false,
            connectorLoading: false,
            connectorUnit: '',
        };

        this.connectorRequestId = 0;
    }

    shouldComponentUpdate(nextProps, nextState) {
        return JSON.stringify(this.props) !== JSON.stringify(nextProps)
            || JSON.stringify(this.state) !== JSON.stringify(nextState);
    }

    componentDidMount() {
        this.maybeLoadConnectorData();
    }

    componentDidUpdate(prevProps) {
        if ( getPlainText( prevProps.unit ) !== getPlainText( this.props.unit ) ) {
            this.maybeLoadConnectorData();
        }
    }

    getConnectorCache() {
        window.wprm_admin_modal_ingredient_unit_connectors = window.wprm_admin_modal_ingredient_unit_connectors || {};

        return window.wprm_admin_modal_ingredient_unit_connectors;
    }

    getConnectorCacheKey(unit) {
        return unit.toLowerCase();
    }

    getCurrentUnit() {
        return getPlainText( this.props.unit );
    }

    shouldLoadConnectorData() {
        const mode = getConnectorMode();

        return 'group' !== this.props.type && 'hide' !== mode && hasConnectorApi() && !! this.getCurrentUnit();
    }

    shouldShowConnectorControl() {
        const mode = getConnectorMode();

        if ( 'group' === this.props.type || 'hide' === mode || ! hasConnectorApi() ) {
            return false;
        }

        if ( 'show' === mode || isConnectorAutoEnabled() ) {
            return true;
        }

        return !! ( this.getCurrentUnit() && this.state.connectorData && this.state.connectorData.connector );
    }

    maybeLoadConnectorData() {
        const unit = this.getCurrentUnit();

        if ( ! this.shouldLoadConnectorData() ) {
            this.setState({
                connectorData: false,
                connectorLoading: false,
                connectorUnit: unit,
            });
            return;
        }

        if ( this.state.connectorUnit === unit && this.state.connectorData ) {
            return;
        }

        const cache = this.getConnectorCache();
        const cacheKey = this.getConnectorCacheKey( unit );

        if ( cache.hasOwnProperty( cacheKey ) ) {
            this.setState({
                connectorData: cache[ cacheKey ],
                connectorLoading: false,
                connectorUnit: unit,
            });
            return;
        }

        const requestId = ++this.connectorRequestId;

        this.setState({
            connectorLoading: true,
            connectorUnit: unit,
        });

        Api.modal.getIngredientUnitConnector( unit ).then((data) => {
            if ( requestId !== this.connectorRequestId ) {
                return;
            }

            const connectorData = data && data.found ? data : false;

            cache[ cacheKey ] = connectorData;

            this.setState({
                connectorData,
                connectorLoading: false,
                connectorUnit: unit,
            });
        }).catch(() => {
            if ( requestId !== this.connectorRequestId ) {
                return;
            }

            this.setState({
                connectorLoading: false,
                connectorUnit: unit,
            });
        });
    }

    openConnectorModal() {
        const unit = this.getCurrentUnit();

        if ( ! unit ) {
            alert( __wprm( 'Add a unit before setting a connector.' ) );
            return;
        }

        if ( ! hasConnectorApi() ) {
            return;
        }

        Api.modal.getIngredientUnitConnector( unit, true ).then((data) => {
            if ( ! data || ! data.term_id ) {
                alert( __wprm( 'We could not find or create this ingredient unit.' ) );
                return;
            }

            const connectorData = {
                connector: data.connector || '',
                connector_spacing: data.connector_spacing || 'space-both',
                connector_pluralizes_ingredient: !! data.connector_pluralizes_ingredient,
            };

            this.setState({
                connectorData: {
                    ...data,
                    ...connectorData,
                },
                connectorLoading: false,
                connectorUnit: unit,
            });

            this.props.openSecondaryModal( 'input-fields', {
                header: `${ __wprm( 'Change Connector' ) }: ${ data.name || unit }`,
                warning: connectorScopeWarning,
                fields: [
                    {
                        label: __wprm( 'Connector' ),
                        value: connectorData.connector,
                        notice: ( fields ) => getConnectorElisionNotice( fields[0].value ),
                    },
                    {
                        label: __wprm( 'Connector Spacing' ),
                        type: 'dropdown',
                        options: connectorSpacingOptions,
                        value: connectorData.connector_spacing,
                    },
                    {
                        label: __wprm( 'Pluralize ingredient after connector' ),
                        type: 'checkbox',
                        value: connectorData.connector_pluralizes_ingredient,
                    },
                ],
                insertCallback: ( args ) => {
                    const updatedConnectorData = {
                        connector: args.fields[0].value ? args.fields[0].value.trim() : '',
                        connector_spacing: args.fields[1].value || 'space-both',
                        connector_pluralizes_ingredient: !! args.fields[2].value,
                    };

                    Api.manage.updateTaxonomyMeta( 'ingredient_unit', data.term_id, updatedConnectorData ).then(() => {
                        const cache = this.getConnectorCache();
                        const cacheKey = this.getConnectorCacheKey( unit );
                        const updatedData = {
                            ...data,
                            ...updatedConnectorData,
                            found: true,
                        };

                        cache[ cacheKey ] = updatedData;

                        this.setState({
                            connectorData: updatedData,
                            connectorLoading: false,
                            connectorUnit: unit,
                        });
                    });
                },
            } );
        });
    }

    render() {
        return (
            <Draggable
                draggableId={ `ingredient-${this.props.uid}` }
                index={ this.props.index }
            >
                {(provided, snapshot) => {
                    if ( 'group' === this.props.type ) {
                        return group(this.props, provided);
                    } else {
                        return ingredient({
                            ...this.props,
                            unitConnectorData: this.state.connectorData,
                            unitConnectorLoading: this.state.connectorLoading,
                            showConnectorControl: this.shouldShowConnectorControl(),
                            onConnectorClick: this.openConnectorModal.bind(this),
                        }, provided);
                    }
                }}
            </Draggable>
        );
    }
}
