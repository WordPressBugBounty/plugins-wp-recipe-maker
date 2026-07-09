import React, { Fragment } from 'react';
import { NavLink } from 'react-router-dom';
import he from 'he';
 
import Media from 'Modal/general/Media';
import TextFilter from '../general/TextFilter';
import bulkEditCheckbox from '../general/bulkEditCheckbox';
import Api from 'Shared/Api';
import Helpers from 'Shared/Helpers';
import Icon from 'Shared/Icon';
import Tooltip from 'Shared/Tooltip';
import { __wprm } from 'Shared/Translations';

import '../../../css/admin/manage/taxonomies.scss';

export default {
    getColumns( datatable ) {
        const connectorSpacingOptions = [
            { value: 'space-both', label: __wprm( 'Space before and after' ) },
            { value: 'space-before', label: __wprm( 'Space before only' ) },
            { value: 'space-after', label: __wprm( 'Space after only' ) },
            { value: 'no-space', label: __wprm( 'No spaces' ) },
        ];
        const connectorScopeWarning = __wprm( 'This connector is saved for the ingredient unit. Setting or changing it will affect all recipes using this unit.' );
        const connectorSpacingLabel = ( value ) => {
            const option = connectorSpacingOptions.find( ( option ) => option.value === value );
            return option ? option.label : connectorSpacingOptions[0].label;
        };
        const getConnectorElisionNotice = ( connector = '' ) => {
            const normalized = he.decode( `${ connector }`.replace( /<[^>]*>/g, '' ) ).trim().toLowerCase();
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
        const openConnectorModal = ( row ) => {
            WPRM_Modal.open( 'input-fields', {
                header: __wprm( 'Change Connector' ),
                warning: connectorScopeWarning,
                fields: [
                    {
                        label: __wprm( 'Connector' ),
                        value: row.original.connector || '',
                        notice: ( fields ) => getConnectorElisionNotice( fields[0].value ),
                    },
                    {
                        label: __wprm( 'Connector Spacing' ),
                        type: 'dropdown',
                        options: connectorSpacingOptions,
                        value: row.original.connector_spacing || 'space-both',
                    },
                    {
                        label: __wprm( 'Pluralize ingredient after connector' ),
                        type: 'checkbox',
                        value: !! row.original.connector_pluralizes_ingredient,
                    },
                ],
                insertCallback: ( args ) => {
                    Api.manage.updateTaxonomyMeta(datatable.props.options.id, row.original.term_id, {
                        connector: args.fields[0].value ? args.fields[0].value.trim() : '',
                        connector_spacing: args.fields[1].value || 'space-both',
                        connector_pluralizes_ingredient: !! args.fields[2].value,
                    }).then(() => datatable.refreshData());
                },
            } );
        };
        let columns = [
            bulkEditCheckbox( datatable, 'term_id' ),
            {
                Header: __wprm( 'Sort:' ),
                id: 'actions',
                headerClassName: 'wprm-admin-table-help-text',
                sortable: false,
                width: 100,
                Filter: () => (
                    <div>
                        { __wprm( 'Filter:' ) }
                    </div>
                ),
                Cell: row => (
                    <div className="wprm-admin-manage-actions">
                        <Fragment>
                            <Icon
                                type="pencil"
                                title={ `${ __wprm( 'Rename' ) } ${ datatable.props.options.label.singular }` }
                                onClick={() => {
                                    let newName = prompt( `${ __wprm( 'What do you want to be the new name for' ) } "${row.original.name}"? ${ __wprm( 'This will update the unit for all recipes using it. Take note that terms are case insensitive (t and T will be seen as the same unit and both get replaced).' ) }`, row.original.name );
                                    if( newName && newName.trim() ) {
                                        Api.manage.renameTerm(datatable.props.options.id, row.original.term_id, newName).then(() => datatable.refreshData());
                                    }
                                }}
                            />
                            <Icon
                                type="merge"
                                title={ `${ __wprm( 'Merge into another' ) } ${ datatable.props.options.label.singular }` }
                                onClick={() => {
                                    let newId = prompt( `${ __wprm( 'What is the ID of the term you want the merge' ) } "${row.original.name}" ${ __wprm( 'into' ) }?` );
                                    if( newId && newId != row.original.term_id && newId.trim() ) {
                                        Api.manage.getTerm(datatable.props.options.id, newId).then(newTerm => {
                                            if ( newTerm ) {
                                                if ( confirm( `${ __wprm( 'Are you sure you want to merge' ) } "${row.original.name}" ${ __wprm( 'into' ) } "${newTerm.name}"?` ) ) {
                                                    Api.manage.mergeTerm(datatable.props.options.id, row.original.term_id, newId).then(() => datatable.refreshData());
                                                }
                                            } else {
                                                alert( __wprm( 'We could not find a term with that ID.' ) );
                                            }
                                        });
                                    }
                                }}
                            />
                            <Icon
                                type="trash"
                                title={ `${ __wprm( 'Delete' ) } ${ datatable.props.options.label.singular }` }
                                onClick={() => {
                                    if( confirm( `${ __wprm( 'Are you sure you want to delete' ) } "${row.original.name}"?` ) ) {
                                        Api.manage.deleteTerm(datatable.props.options.id, row.original.term_id).then(() => datatable.refreshData());
                                    }
                                }}
                            />
                        </Fragment>
                    </div>
                ),
            },{
                Header: __wprm( 'ID' ),
                id: 'id',
                accessor: 'term_id',
                width: 65,
                Filter: (props) => (<TextFilter {...props}/>),
            },{
                Header: __wprm( 'Unit' ),
                id: 'name',
                accessor: 'name',
                Filter: (props) => (<TextFilter {...props}/>),
                Cell: row => row.value ? he.decode(row.value) : null,
            },{
                Header: __wprm( 'Plural' ),
                id: 'plural',
                accessor: 'plural',
                width: 200,
                Filter: (props) => (<TextFilter {...props}/>),
                Cell: row => {
                    return (
                        <div className="wprm-manage-ingredient-units-group-container">
                            <Icon
                                type="pencil"
                                title={ __wprm( 'Change Plural' ) }
                                onClick={() => {
                                    const newPlural = prompt( `${ __wprm( 'What do you want the plural to be for' ) } "${row.original.name}"?`, row.value );
                                    if( false !== newPlural ) {
                                        Api.manage.updateTaxonomyMeta(datatable.props.options.id, row.original.term_id, { plural: newPlural }).then(() => datatable.refreshData());
                                    }
                                }}
                            />
                            {
                                row.value
                                ?
                                <span>{ row.value }</span>
                                :
                                null
                            }
                        </div>
                    )
                },
            },{
                Header: __wprm( 'Connector' ),
                id: 'connector',
                accessor: 'connector',
                width: 240,
                Filter: (props) => (<TextFilter {...props}/>),
                Cell: row => {
                    const connector = row.original.connector || '';
                    const values = connector ? [
                        connector,
                        connectorSpacingLabel( row.original.connector_spacing || 'space-both' ),
                    ] : [];

                    if ( connector && row.original.connector_pluralizes_ingredient ) {
                        values.push( __wprm( 'pluralizes ingredient' ) );
                    }

                    return (
                        <div className="wprm-manage-ingredient-units-group-container">
                            <Icon
                                type="pencil"
                                title={ __wprm( 'Change Connector' ) }
                                onClick={() => {
                                    openConnectorModal( row );
                                }}
                            />
                            {
                                values.length
                                ?
                                <span>{ values.join( ' / ' ) }</span>
                                :
                                null
                            }
                        </div>
                    )
                },
            },{
                Header: __wprm( 'Recipes' ),
                id: 'count',
                accessor: 'count',
                filterable: false,
                width: 65,
                Cell: row => {
                    return (
                        <NavLink to={ `/recipe/${ datatable.props.options.id }=${row.original.term_id}` }>{ row.value }</NavLink>
                    )
                }
            }
        ];

        return columns;
    }
};
