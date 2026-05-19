import React, { Component, Fragment } from 'react';
import { DragDropContext, Droppable } from 'react-beautiful-dnd';

import '../../../../css/admin/modal/recipe/fields/list-item.scss';

import { __wprm } from 'Shared/Translations';
import FieldListItem from '../../fields/FieldListItem';

export default class ListItems extends Component {
    constructor(props) {
        super(props);

        this.state = {
            posts: {},
            unavailableItemIds: {},
            hasShownUnavailableAlert: false,
        };

        this.container = React.createRef();
    }

    shouldComponentUpdate(nextProps, nextState) {
        return JSON.stringify( this.props.items ) !== JSON.stringify( nextProps.items ) || JSON.stringify( this.state.posts ) !== JSON.stringify( nextState.posts ) || JSON.stringify( this.state.unavailableItemIds ) !== JSON.stringify( nextState.unavailableItemIds ) || this.state.hasShownUnavailableAlert !== nextState.hasShownUnavailableAlert;
    }

    componentDidUpdate(prevProps) {
        if ( JSON.stringify( this.props.items ) !== JSON.stringify( prevProps.items ) ) {
            const currentIds = {};

            this.props.items.forEach((item) => {
                if ( 'roundup' === item.type && ( 'internal' === item.data.type || 'post' === item.data.type ) ) {
                    const itemId = parseInt( item.data.id );

                    if ( itemId > 0 ) {
                        currentIds[ itemId ] = true;
                    }
                }
            });

            const unavailableItemIds = {};

            Object.keys( this.state.unavailableItemIds ).forEach((id) => {
                if ( currentIds[ id ] ) {
                    unavailableItemIds[ id ] = true;
                }
            });

            if ( JSON.stringify( unavailableItemIds ) !== JSON.stringify( this.state.unavailableItemIds ) ) {
                this.setState({
                    unavailableItemIds,
                });
            }
        }

        const unavailableCount = this.getUnavailableEntries().length;
        if ( 0 < unavailableCount && ! this.state.hasShownUnavailableAlert ) {
            alert( __wprm( 'Some recipes/posts could not be found anymore and might have been deleted.' ) );

            this.setState({
                hasShownUnavailableAlert: true,
            });
        }
    }

    getUnavailableEntries() {
        const unavailableEntries = {};

        this.props.items.forEach((item) => {
            if ( 'roundup' !== item.type || ( 'internal' !== item.data.type && 'post' !== item.data.type ) ) {
                return;
            }

            const itemId = parseInt( item.data.id );
            if ( 0 >= itemId || ! this.state.unavailableItemIds[ itemId ] ) {
                return;
            }

            if ( ! unavailableEntries.hasOwnProperty( itemId ) ) {
                unavailableEntries[ itemId ] = {
                    id: itemId,
                    type: item.data.type,
                    name: item.data.name && item.data.name.trim() ? item.data.name.trim() : '',
                };
            }
        });

        return Object.values( unavailableEntries ).sort((a, b) => a.id - b.id );
    }

    removeUnavailableItems() {
        const unavailableEntries = this.getUnavailableEntries();
        if ( ! unavailableEntries.length ) {
            return;
        }

        const unavailableIds = {};
        unavailableEntries.forEach((entry) => {
            unavailableIds[ entry.id ] = true;
        });

        const newFields = this.props.items.filter((item) => {
            if ( 'roundup' !== item.type || ( 'internal' !== item.data.type && 'post' !== item.data.type ) ) {
                return true;
            }

            const itemId = parseInt( item.data.id );

            return ! unavailableIds[ itemId ];
        });

        this.props.onListChange({
            items: newFields,
        });

        this.setState({
            unavailableItemIds: {},
        });
    }

    onDragEnd(result) {
        if ( result.destination ) {
            let newFields = JSON.parse( JSON.stringify( this.props.items ) );
            const sourceIndex = result.source.index;
            const destinationIndex = result.destination.index;

            const field = newFields.splice(sourceIndex, 1)[0];
            newFields.splice(destinationIndex, 0, field);

            this.props.onListChange({
                items: newFields,
            });
        }
    }

    addField( type = 'roundup', afterIndex = false ) {
        let newFields = JSON.parse( JSON.stringify( this.props.items ) );
        let newField = {
            type,
            data: {},
        };

        // Default data.
        if ( 'roundup' === type ) {
            newField.data = {
                type: 'internal',
                id: 0,
                link: '',
                nofollow: '',
                newtab: '',
                image: 0,
                image_url: '',
                credit: '',
                name: '',
                summary: '',
                button: '',
                template: '',
            };
        } else if ( 'text' === type ) {
            newField.data = {
                text: '',
            }
        }

        // Give unique UID.
        let maxUid = Math.max.apply( Math, newFields.map( function(field) { return field.uid; } ) );
        maxUid = maxUid < 0 ? -1 : maxUid;
        newField.uid = maxUid + 1;

        let lastAddedIndex;
        if ( false === afterIndex ) {
            newFields.push(newField);
            lastAddedIndex = newFields.length - 1;
        } else {
            newFields.splice(afterIndex + 1, 0, newField);
            lastAddedIndex = afterIndex + 1;
        }

        this.props.onListChange({
            items: newFields,
        }, () => {
            if ( 'roundup' === type ) {
                this.props.onEditItem( lastAddedIndex );
            }
        });
    }
  
    render() {
        const unavailableEntries = this.getUnavailableEntries();

        return (
            <div
                className="wprm-admin-modal-field-items-container"
                ref={ this.container }
            >
                <DragDropContext
                    onDragEnd={this.onDragEnd.bind(this)}
                >
                    <Droppable
                        droppableId="wprm-items"
                    >
                        {(provided, snapshot) => (
                            <div
                                className={`${ snapshot.isDraggingOver ? ' wprm-admin-modal-field-items-container-draggingover' : ''}`}
                                ref={provided.innerRef}
                                {...provided.droppableProps}
                            >
                                {
                                    this.props.hasOwnProperty( 'items' ) && this.props.items && this.props.items.length > 0
                                    &&
                                    <Fragment>
                                        {
                                            0 < unavailableEntries.length
                                            &&
                                            <div className="wprm-admin-modal-field-items-unavailable notice notice-warning inline">
                                                <p>
                                                    { `${ unavailableEntries.length } ${ __wprm( 'recipes/posts could not be found anymore and might have been deleted.' ) }` }
                                                </p>
                                                <ul>
                                                    {
                                                        unavailableEntries.map((entry) => {
                                                            const typeLabel = 'internal' === entry.type ? __wprm( 'Recipe' ) : __wprm( 'Post' );
                                                            return (
                                                                <li key={ `unavailable-${entry.id}` }>
                                                                    { `${ typeLabel } #${entry.id}${ entry.name ? ` - ${entry.name}` : '' }` }
                                                                </li>
                                                            );
                                                        })
                                                    }
                                                </ul>
                                                <button
                                                    className="button button-secondary button-small"
                                                    onClick={ (e) => {
                                                        e.preventDefault();
                                                        this.removeUnavailableItems();
                                                    } }
                                                >
                                                    { __wprm( 'Remove All Missing Posts/Recipes' ) }
                                                </button>
                                            </div>
                                        }
                                        <div className="wprm-admin-modal-field-items-header-container">
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( '#' ) }</div>
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( 'Image' ) }</div>
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( 'Name' ) }</div>
                                        </div>
                                        {
                                            this.props.items.map((item, index) => {
                                                let validItem = false;
                                                let itemPost = false;

                                                if ( 'roundup' === item.type ) {
                                                    if ( ( 'internal' === item.data.type || 'post' === item.data.type ) && 0 < item.data.id ) {
                                                        validItem = true;
                                                        if ( this.state.posts.hasOwnProperty( item.data.id ) ) {
                                                            itemPost = this.state.posts[ item.data.id ];
                                                        }
                                                    }
                                                    if ( 'external' === item.data.type && item.data.link ) {
                                                        validItem = true;
                                                    }
                                                }

                                                if ( 'text' === item.type ) {
                                                    validItem = true;
                                                }

                                                if ( ! validItem ) {
                                                    return null;
                                                }

                                                return (
                                                    <FieldListItem
                                                        item={ item }
                                                        post={ itemPost }
                                                        onLoadPost={ (post) => {
                                                            this.setState((prevState) => {
                                                                let posts = JSON.parse( JSON.stringify( prevState.posts ) );
                                                                posts[ post.id ] = post;

                                                                let unavailableItemIds = JSON.parse( JSON.stringify( prevState.unavailableItemIds ) );
                                                                if ( unavailableItemIds.hasOwnProperty( post.id ) ) {
                                                                    delete unavailableItemIds[ post.id ];
                                                                }

                                                                return {
                                                                    posts,
                                                                    unavailableItemIds,
                                                                };
                                                            });
                                                        } }
                                                        onLoadPostError={ (postId) => {
                                                            if ( ! postId || 0 >= postId ) {
                                                                return;
                                                            }

                                                            this.setState((prevState) => {
                                                                let unavailableItemIds = JSON.parse( JSON.stringify( prevState.unavailableItemIds ) );
                                                                unavailableItemIds[ postId ] = true;

                                                                return {
                                                                    unavailableItemIds,
                                                                };
                                                            });
                                                        } }
                                                        index={ index }
                                                        key={ `item-${item.uid}` }
                                                        onChange={ ( data ) => {
                                                            let newFields = JSON.parse( JSON.stringify( this.props.items ) );
                                                            newFields[ index ].data = {
                                                                ...newFields[ index ].data,
                                                                ...data,
                                                            }

                                                            this.props.onListChange({
                                                                items: newFields,
                                                            });
                                                        } }
                                                        onEdit={ () => { this.props.onEditItem( index ) } }
                                                        onAdd={ () => {
                                                            this.addField( 'roundup', index );
                                                        }}
                                                        onDelete={() => {
                                                            let newFields = JSON.parse( JSON.stringify( this.props.items ) );
                                                            newFields.splice(index, 1);

                                                            this.props.onListChange({
                                                                items: newFields,
                                                            });
                                                        }}
                                                    />
                                                )
                                            })
                                        }
                                    </Fragment>
                                }
                                {provided.placeholder}
                            </div>
                        )}
                    </Droppable>
                </DragDropContext>
                <div
                    className="wprm-admin-modal-field-items-actions"
                >
                    <button
                        className="button button-secondary button-compact"
                        onClick={(e) => {
                            e.preventDefault();
                            this.addField( 'roundup' );
                        } }
                    >{ __wprm( 'Add Roundup Item' ) }</button>
                    <button
                        className="button button-secondary button-compact"
                        onClick={(e) => {
                            e.preventDefault();
                            this.addField( 'text' );
                        } }
                    >{ __wprm( 'Add Text Field' ) }</button>
                </div>
            </div>
        );
    }
}
