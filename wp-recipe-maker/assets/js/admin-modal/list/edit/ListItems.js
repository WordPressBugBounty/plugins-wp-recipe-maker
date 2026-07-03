import React, { Component, Fragment } from 'react';
import { DragDropContext, Droppable } from 'react-beautiful-dnd';

import '../../../../css/admin/modal/recipe/fields/list-item.scss';

import Api from 'Shared/Api';
import { __wprm } from 'Shared/Translations';
import FieldListItem from '../../fields/FieldListItem';

export default class ListItems extends Component {
    constructor(props) {
        super(props);

        this.state = {
            posts: {},
            unavailableItemIds: {},
            hasShownUnavailableAlert: false,
            importingItems: false,
        };

        this.container = React.createRef();
    }

    shouldComponentUpdate(nextProps, nextState) {
        return JSON.stringify( this.props.items ) !== JSON.stringify( nextProps.items ) || JSON.stringify( this.state.posts ) !== JSON.stringify( nextState.posts ) || JSON.stringify( this.state.unavailableItemIds ) !== JSON.stringify( nextState.unavailableItemIds ) || this.state.hasShownUnavailableAlert !== nextState.hasShownUnavailableAlert || this.state.importingItems !== nextState.importingItems;
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

        const lastAddedIndex = false === afterIndex ? newFields.length : afterIndex + 1;
        newFields.splice(lastAddedIndex, 0, newField);

        this.props.onListChange({
            items: newFields,
        }, () => {
            if ( 'roundup' === type ) {
                this.props.onEditItem( lastAddedIndex );
            }
        });
    }

    getMaxUid( items ) {
        const uids = items.map((item) => parseInt( item.uid ) ).filter((uid) => ! isNaN( uid ) );

        return uids.length ? Math.max.apply( Math, uids ) : -1;
    }

    openImportListModal() {
        if ( 'function' !== typeof this.props.openSecondaryModal ) {
            return;
        }

        const listId = this.props.listId ? parseInt( this.props.listId ) : false;

        this.props.openSecondaryModal( 'select', {
            title: __wprm( 'Add all Items from Other List' ),
            button: __wprm( 'Add Items' ),
            type: 'list',
            excludeIds: listId ? [ listId ] : [],
            insertCallback: ( fields ) => {
                this.importItemsFromList( fields.list );
            },
        } );
    }

    importItemsFromList( list ) {
        const importListId = list && list.id ? parseInt( list.id ) : false;
        const currentListId = this.props.listId ? parseInt( this.props.listId ) : false;

        if ( ! importListId || this.state.importingItems ) {
            return;
        }

        if ( currentListId && currentListId === importListId ) {
            alert( __wprm( 'This list cannot be added to itself.' ) );
            return;
        }

        this.setState({
            importingItems: true,
        }, () => {
            Api.list.get( importListId ).then((data) => {
                if ( ! data || ! data.list || ! Array.isArray( data.list.items ) ) {
                    alert( __wprm( 'The selected list could not be loaded.' ) );
                    return;
                }

                const importItems = data.list.items;

                if ( ! importItems.length ) {
                    alert( __wprm( 'The selected list does not have any items to add.' ) );
                    return;
                }

                let newFields = JSON.parse( JSON.stringify( this.props.items ? this.props.items : [] ) );
                let maxUid = this.getMaxUid( newFields );
                const newItems = JSON.parse( JSON.stringify( importItems ) ).map((item) => {
                    maxUid++;
                    return {
                        ...item,
                        uid: maxUid,
                    };
                });

                this.props.onListChange({
                    items: newFields.concat( newItems ),
                });
            }).catch(() => {
                alert( __wprm( 'The selected list could not be loaded.' ) );
            }).then(() => {
                this.setState({
                    importingItems: false,
                });
            });
        });
    }

    refreshPostSummary( postId ) {
        postId = parseInt( postId );

        if ( ! postId || 0 >= postId ) {
            return;
        }

        Api.utilities.getPostSummary( postId ).then((data) => {
            if ( data && data.post && data.post.id ) {
                const post = JSON.parse( JSON.stringify( data.post ) );

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
            }
        }).catch(() => {});
    }

    editRecipe( item ) {
        if ( 'function' !== typeof this.props.openSecondaryModal || 'roundup' !== item.type || ! item.data || 'internal' !== item.data.type ) {
            return;
        }

        const recipeId = parseInt( item.data.id );
        if ( ! recipeId || 0 >= recipeId ) {
            return;
        }

        this.props.openSecondaryModal( 'recipe', {
            recipeId,
            saveCallback: ( recipe ) => {
                this.refreshPostSummary( recipe && recipe.id ? recipe.id : recipeId );
            },
        } );
    }

    isValidItem( item ) {
        if ( 'roundup' === item.type ) {
            if ( ( 'internal' === item.data.type || 'post' === item.data.type ) && 0 < item.data.id ) {
                return true;
            }
            if ( 'external' === item.data.type && item.data.link ) {
                return true;
            }
        }

        return 'text' === item.type;
    }

    renderAddButtons( afterIndex = false, extraClassName = '', showImportButton = false ) {
        return (
            <div
                className={ `wprm-admin-modal-field-items-actions${ extraClassName ? ` ${ extraClassName }` : '' }` }
            >
                <button
                    className="button button-secondary button-compact"
                    onClick={(e) => {
                        e.preventDefault();
                        this.addField( 'roundup', afterIndex );
                    } }
                >{ __wprm( 'Add Roundup Item' ) }</button>
                <button
                    className="button button-secondary button-compact"
                    onClick={(e) => {
                        e.preventDefault();
                        this.addField( 'text', afterIndex );
                    } }
                >{ __wprm( 'Add Text Field' ) }</button>
                {
                    showImportButton
                    &&
                    <button
                        className="button button-secondary button-compact wprm-admin-modal-field-items-import-button"
                        disabled={ this.state.importingItems || 'function' !== typeof this.props.openSecondaryModal }
                        onClick={(e) => {
                            e.preventDefault();
                            this.openImportListModal();
                        } }
                    >{ this.state.importingItems ? __wprm( 'Adding Items...' ) : __wprm( 'Add all Items from Other List' ) }</button>
                }
            </div>
        );
    }
  
    render() {
        const unavailableEntries = this.getUnavailableEntries();
        const visibleItemCount = this.props.items ? this.props.items.filter((item) => this.isValidItem( item ) ).length : 0;
        const showTopAddButtons = 5 <= visibleItemCount;

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
                                        {
                                            showTopAddButtons
                                            &&
                                            this.renderAddButtons( -1, 'wprm-admin-modal-field-items-actions-top' )
                                        }
                                        <div className="wprm-admin-modal-field-items-header-container">
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( '#' ) }</div>
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( 'Type' ) }</div>
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( 'Image' ) }</div>
                                            <div className="wprm-admin-modal-field-items-header">{ __wprm( 'Name' ) }</div>
                                        </div>
                                        {
                                            this.props.items.map((item, index) => {
                                                let itemPost = false;

                                                if ( this.isValidItem( item ) ) {
                                                    if ( ( 'internal' === item.data.type || 'post' === item.data.type ) && 0 < item.data.id ) {
                                                        if ( this.state.posts.hasOwnProperty( item.data.id ) ) {
                                                            itemPost = this.state.posts[ item.data.id ];
                                                        }
                                                    }
                                                }

                                                if ( ! this.isValidItem( item ) ) {
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
                                                        onEditRecipe={ () => { this.editRecipe( item ) } }
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
                { this.renderAddButtons( false, '', true ) }
            </div>
        );
    }
}
