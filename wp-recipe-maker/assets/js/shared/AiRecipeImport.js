import React, { useEffect, useState } from 'react';

import '../../css/admin/modal/ai-text-import.scss';

import Api from './Api';
import Loader from './Loader';
import { __wprm } from './Translations';
import { convertTermNamesToObjects } from './CategoryTerms';

import FieldContainer from '../admin-modal/fields/FieldContainer';
import FieldTextarea from '../admin-modal/fields/FieldTextarea';

const formatTime = ( minutes ) => {
    if ( ! minutes ) {
        return '';
    }

    const mins = parseInt( minutes );

    if ( mins < 60 ) {
        return `${ mins } min`;
    }

    const hours = Math.floor( mins / 60 );
    const remainingMins = mins % 60;

    return remainingMins > 0 ? `${ hours } hr ${ remainingMins } min` : `${ hours } hr`;
};

const imageUrlExtensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];

const normalizeImageUrl = ( value ) => {
    if ( 'string' !== typeof value ) {
        return false;
    }

    let url = value.trim().replace( /&amp;/gi, '&' );

    while ( url && /[\s"'`<(\[]/.test( url.charAt( 0 ) ) ) {
        url = url.substring( 1 );
    }

    while ( url && /[\s"'`>.,;:!?)\]}]/.test( url.charAt( url.length - 1 ) ) ) {
        url = url.substring( 0, url.length - 1 );
    }

    try {
        const parsedUrl = new URL( url );

        if ( 'http:' !== parsedUrl.protocol && 'https:' !== parsedUrl.protocol ) {
            return false;
        }

        const extensionMatch = parsedUrl.pathname.toLowerCase().match( /\.([a-z0-9]+)$/ );

        if ( ! extensionMatch || ! imageUrlExtensions.includes( extensionMatch[1] ) ) {
            return false;
        }

        return parsedUrl.href;
    } catch ( e ) {
        return false;
    }
};

const extractImageUrls = ( text ) => {
    if ( 'string' !== typeof text || ! text ) {
        return [];
    }

    const urls = [];
    const seen = {};
    const addUrl = ( value ) => {
        const url = normalizeImageUrl( value );

        if ( url && ! seen[ url ] ) {
            seen[ url ] = true;
            urls.push( url );
        }
    };

    let match;

    const imgTagRegex = /<img\b[^>]*>/gi;
    while ( ( match = imgTagRegex.exec( text ) ) ) {
        const srcMatch = match[0].match( /\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i );

        if ( srcMatch ) {
            addUrl( srcMatch[1] || srcMatch[2] || srcMatch[3] );
        }
    }

    const markdownImageRegex = /!\[[^\]]*]\(\s*(<?[^)\s>]+>?)/gi;
    while ( ( match = markdownImageRegex.exec( text ) ) ) {
        addUrl( match[1].replace( /^<|>$/g, '' ) );
    }

    const bareUrlRegex = /https?:\/\/[^\s<>"']+/gi;
    while ( ( match = bareUrlRegex.exec( text ) ) ) {
        addUrl( match[0] );
    }

    return urls;
};

const getTagName = ( tag ) => {
    if ( 'string' === typeof tag ) {
        return tag.trim();
    }

    if ( tag && 'object' === typeof tag ) {
        if ( 'string' === typeof tag.name ) {
            return tag.name.trim();
        }

        if ( 'string' === typeof tag.term_id ) {
            return tag.term_id.trim();
        }
    }

    return '';
};

const normalizeRecipeTags = ( recipe ) => {
    if ( ! recipe || ! recipe.tags || 'object' !== typeof recipe.tags ) {
        return recipe;
    }

    const normalizedTags = Object.keys( recipe.tags ).reduce( ( tags, key ) => {
        const values = Array.isArray( recipe.tags[ key ] ) ? recipe.tags[ key ] : [];
        const normalizedValues = [];
        const namesToConvert = [];

        values.forEach( ( value ) => {
            if ( value && 'object' === typeof value && value.name ) {
                normalizedValues.push( value );
                return;
            }

            const tagName = getTagName( value );

            if ( tagName ) {
                namesToConvert.push( tagName );
            }
        } );

        if ( namesToConvert.length ) {
            const convertedValues = convertTermNamesToObjects( key, namesToConvert );

            if ( convertedValues.length ) {
                normalizedValues.push( ...convertedValues );
            } else {
                normalizedValues.push( ...namesToConvert.map( ( name ) => ( {
                    term_id: name,
                    name,
                } ) ) );
            }
        }

        tags[ key ] = normalizedValues;
        return tags;
    }, {} );

    return {
        ...recipe,
        tags: normalizedTags,
    };
};

const getUidValue = ( value ) => {
    if ( 'number' === typeof value && Number.isFinite( value ) ) {
        return value;
    }

    if ( 'string' === typeof value && '' !== value.trim() && Number.isFinite( Number( value ) ) ) {
        return parseInt( value, 10 );
    }

    return false;
};

const normalizeFlatListUids = ( items ) => {
    if ( ! Array.isArray( items ) ) {
        return items;
    }

    let maxUid = -1;
    const normalizedItems = items.map( ( item ) => {
        const normalizedItem = item && 'object' === typeof item ? { ...item } : {};
        const uid = getUidValue( normalizedItem.uid );

        if ( false !== uid && uid > maxUid ) {
            maxUid = uid;
        }

        return normalizedItem;
    } );
    const usedUids = {};

    return normalizedItems.map( ( item ) => {
        let uid = getUidValue( item.uid );

        while ( false === uid || usedUids.hasOwnProperty( uid ) ) {
            maxUid++;
            uid = maxUid;
        }

        item.uid = uid;
        usedUids[ uid ] = true;

        return item;
    } );
};

const normalizeIngredientsForModal = ( ingredients ) => {
    if ( ! Array.isArray( ingredients ) ) {
        return ingredients;
    }

    return normalizeFlatListUids( ingredients ).map( ( ingredient ) => {
        if ( 'group' === ingredient.type ) {
            return {
                ...ingredient,
                type: 'group',
                name: ingredient.name || '',
            };
        }

        return {
            ...ingredient,
            type: 'ingredient',
            amount: ingredient.amount || '',
            unit: ingredient.unit || '',
            name: ingredient.name || '',
            notes: ingredient.notes || '',
        };
    } );
};

const normalizeInstructionsForModal = ( instructions ) => {
    if ( ! Array.isArray( instructions ) ) {
        return instructions;
    }

    return normalizeFlatListUids( instructions ).map( ( instruction ) => {
        if ( 'group' === instruction.type ) {
            return {
                ...instruction,
                type: 'group',
                name: instruction.name || '',
            };
        }

        if ( 'tip' === instruction.type ) {
            return {
                ...instruction,
                type: 'tip',
                name: instruction.name || '',
                text: instruction.text || '',
                tip_icon: instruction.tip_icon || '',
                tip_style: instruction.tip_style || '',
                tip_accent: instruction.tip_accent || '',
                tip_text_color: instruction.tip_text_color || '',
            };
        }

        return {
            ...instruction,
            type: 'instruction',
            name: instruction.name || '',
            text: instruction.text || '',
            image: instruction.image || 0,
            image_url: instruction.image_url || '',
            ingredients: Array.isArray( instruction.ingredients ) ? instruction.ingredients : [],
        };
    } );
};

const normalizeEquipmentForModal = ( equipment ) => {
    if ( ! Array.isArray( equipment ) ) {
        return equipment;
    }

    return normalizeFlatListUids( equipment ).map( ( item ) => ( {
        ...item,
        amount: item.amount || '',
        name: item.name || '',
    } ) );
};

const normalizeRecipeForModal = ( recipe ) => {
    if ( ! recipe || 'object' !== typeof recipe ) {
        return recipe;
    }

    return {
        ...recipe,
        ...( Array.isArray( recipe.ingredients_flat ) && { ingredients_flat: normalizeIngredientsForModal( recipe.ingredients_flat ) } ),
        ...( Array.isArray( recipe.instructions_flat ) && { instructions_flat: normalizeInstructionsForModal( recipe.instructions_flat ) } ),
        ...( Array.isArray( recipe.equipment ) && { equipment: normalizeEquipmentForModal( recipe.equipment ) } ),
    };
};

const getTagItems = ( recipe ) => {
    if ( ! recipe || ! recipe.tags || 'object' !== typeof recipe.tags ) {
        return [];
    }

    const labels = {
        course: __wprm( 'Courses' ),
        cuisine: __wprm( 'Cuisines' ),
        keyword: __wprm( 'Keywords' ),
        difficulty: __wprm( 'Difficulty' ),
    };

    return Object.keys( labels ).map( ( key ) => {
        const value = Array.isArray( recipe.tags[ key ] )
            ? recipe.tags[ key ].map( getTagName ).filter( Boolean )
            : [];

        if ( ! value.length ) {
            return false;
        }

        return {
            key,
            label: labels[ key ],
            value: value.join( ', ' ),
        };
    }).filter( Boolean );
};

const IngredientPreview = ( props ) => {
    const ingredients = props.ingredients || [];

    if ( ! ingredients.length ) {
        return null;
    }

    return (
        <div className="wprm-ai-preview-section">
            <h4>{ __wprm( 'Ingredients' ) }</h4>
            <ul className="wprm-ai-preview-ingredients">
                { ingredients.map( ( ingredient, index ) => {
                    if ( 'group' === ingredient.type ) {
                        return (
                            <li key={ index }>
                                <strong>{ ingredient.name }</strong>
                            </li>
                        );
                    }

                    return (
                        <li key={ index }>
                            { ( ingredient.amount || ingredient.unit ) && (
                                <span className="wprm-ai-preview-ingredient-amount">
                                    { [ ingredient.amount, ingredient.unit ].filter( Boolean ).join( ' ' ) }
                                </span>
                            ) }
                            <span className="wprm-ai-preview-ingredient-name">{ ingredient.name }</span>
                            { ingredient.notes && (
                                <span className="wprm-ai-preview-ingredient-notes">({ ingredient.notes })</span>
                            ) }
                        </li>
                    );
                } ) }
            </ul>
        </div>
    );
};

const InstructionPreview = ( props ) => {
    const instructions = props.instructions || [];

    if ( ! instructions.length ) {
        return null;
    }

    return (
        <div className="wprm-ai-preview-section">
            <h4>{ __wprm( 'Instructions' ) }</h4>
            <ol className="wprm-ai-preview-instructions">
                { instructions.map( ( instruction, index ) => {
                    if ( 'group' === instruction.type ) {
                        return (
                            <li key={ index }>
                                <strong>{ instruction.name }</strong>
                            </li>
                        );
                    }

                    if ( 'tip' === instruction.type ) {
                        return (
                            <li key={ index }>
                                <strong>{ __wprm( 'Tip' ) }:</strong> { instruction.text }
                            </li>
                        );
                    }

                    return <li key={ index }>{ instruction.text }</li>;
                } ) }
            </ol>
        </div>
    );
};

const EquipmentPreview = ( props ) => {
    const equipment = props.equipment || [];

    if ( ! equipment.length ) {
        return null;
    }

    return (
        <div className="wprm-ai-preview-section">
            <h4>{ __wprm( 'Equipment' ) }</h4>
            <ul className="wprm-ai-preview-ingredients">
                { equipment.map( ( item, index ) => (
                    <li key={ index }>
                        { item.amount && <span className="wprm-ai-preview-ingredient-amount">{ item.amount }</span> }
                        <span className="wprm-ai-preview-ingredient-name">{ item.name }</span>
                        { item.notes && <span className="wprm-ai-preview-ingredient-notes">({ item.notes })</span> }
                    </li>
                ) ) }
            </ul>
        </div>
    );
};

const RecipeImageUrlPreview = ( props ) => {
    const imageUrls = props.imageUrls || [];
    const selectedImageUrl = props.selectedImageUrl || '';
    const onSelectedImageUrlChange = props.onSelectedImageUrlChange || (() => {});

    if ( ! imageUrls.length ) {
        return null;
    }

    return (
        <div className="wprm-ai-preview-section wprm-ai-preview-image-urls">
            <h4>{ __wprm( 'Image URLs found' ) }</h4>
            <div className="wprm-ai-preview-image-options">
                <label className={ `wprm-ai-preview-image-option wprm-ai-preview-image-option-none${ ! selectedImageUrl ? ' is-selected' : '' }` }>
                    <input
                        type="radio"
                        name="wprm-ai-import-image-url"
                        checked={ ! selectedImageUrl }
                        onChange={ () => onSelectedImageUrlChange( '' ) }
                    />
                    <span>{ __wprm( 'Do not import image' ) }</span>
                </label>
                { imageUrls.map( ( imageUrl ) => (
                    <label
                        key={ imageUrl }
                        className={ `wprm-ai-preview-image-option${ selectedImageUrl === imageUrl ? ' is-selected' : '' }` }
                    >
                        <input
                            type="radio"
                            name="wprm-ai-import-image-url"
                            checked={ selectedImageUrl === imageUrl }
                            onChange={ () => onSelectedImageUrlChange( imageUrl ) }
                        />
                        <span className="wprm-ai-preview-image-option-thumb">
                            <img src={ imageUrl } alt="" loading="lazy" />
                        </span>
                        <span className="wprm-ai-preview-image-option-url" title={ imageUrl }>
                            { imageUrl }
                        </span>
                    </label>
                ) ) }
            </div>
        </div>
    );
};

export const AIRecipeImportPreview = ( props ) => {
    const recipe = props.recipe;

    if ( ! recipe ) {
        return null;
    }

    const ingredients = Array.isArray( recipe.ingredients_flat ) ? recipe.ingredients_flat : [];
    const instructions = Array.isArray( recipe.instructions_flat ) ? recipe.instructions_flat : [];
    const equipment = Array.isArray( recipe.equipment ) ? recipe.equipment.filter( ( item ) => item && item.name ) : [];
    const summary = recipe.summary ? recipe.summary : '';
    const notes = recipe.notes ? recipe.notes : '';
    const imageUrls = Array.isArray( props.imageUrls ) ? props.imageUrls : [];
    const servings = recipe.servings ? `${ recipe.servings }${ recipe.servings_unit ? ` ${ recipe.servings_unit }` : '' }` : '';
    const tagItems = getTagItems( recipe );
    const timeItems = [
        recipe.prep_time ? {
            label: __wprm( 'Prep Time' ),
            value: formatTime( recipe.prep_time ),
        } : false,
        recipe.cook_time ? {
            label: __wprm( 'Cook Time' ),
            value: formatTime( recipe.cook_time ),
        } : false,
        recipe.total_time ? {
            label: __wprm( 'Total Time' ),
            value: formatTime( recipe.total_time ),
        } : false,
        recipe.custom_time ? {
            label: recipe.custom_time_label || __wprm( 'Custom Time' ),
            value: formatTime( recipe.custom_time ),
        } : false,
    ].filter( Boolean );

    return (
        <div className="wprm-admin-modal-ai-text-import-preview">
            <div className="wprm-ai-preview-header">
                <h3>{ recipe.name }</h3>
                { summary && <p className="wprm-ai-preview-summary">{ summary }</p> }
            </div>
            { ( timeItems.length > 0 || servings ) && (
                <div className="wprm-ai-preview-meta-grid">
                    { timeItems.map( ( time, index ) => (
                        <div key={ index } className="wprm-ai-preview-meta-item">
                            <span className="wprm-ai-preview-meta-label">{ time.label }</span>
                            <span className="wprm-ai-preview-meta-value">{ time.value }</span>
                        </div>
                    ) ) }
                    { servings && (
                        <div className="wprm-ai-preview-meta-item">
                            <span className="wprm-ai-preview-meta-label">{ __wprm( 'Servings' ) }</span>
                            <span className="wprm-ai-preview-meta-value">{ servings }</span>
                        </div>
                    ) }
                </div>
            ) }
            <RecipeImageUrlPreview
                imageUrls={ imageUrls }
                selectedImageUrl={ props.selectedImageUrl }
                onSelectedImageUrlChange={ props.onSelectedImageUrlChange }
            />
            { tagItems.length > 0 && (
                <div className="wprm-ai-preview-section">
                    <h4>{ __wprm( 'Tags' ) }</h4>
                    <ul className="wprm-ai-preview-ingredients">
                        { tagItems.map( ( tag ) => (
                            <li key={ tag.key }>
                                <strong>{ tag.label }:</strong> { tag.value }
                            </li>
                        ) ) }
                    </ul>
                </div>
            ) }
            <EquipmentPreview equipment={ equipment } />
            <IngredientPreview ingredients={ ingredients } />
            <InstructionPreview instructions={ instructions } />
            { notes && (
                <div className="wprm-ai-preview-section">
                    <h4>{ __wprm( 'Notes' ) }</h4>
                    <p className="wprm-ai-preview-summary">{ notes }</p>
                </div>
            ) }
        </div>
    );
};

export const AIRecipeImportContent = ( props ) => {
    const hasImportedRecipe = !! props.importedRecipe;

    return (
        <div className={ `wprm-admin-modal-ai-text-import-container${ hasImportedRecipe ? ' wprm-has-preview' : '' }` }>
            <FieldContainer label={ __wprm( 'Recipe Text' ) }>
                <FieldTextarea
                    value={ props.text }
                    placeholder={ __wprm( 'Paste or type recipe and click the import button' ) }
                    onChange={ props.onTextChange }
                />
            </FieldContainer>
            { props.importing && (
                <div className="wprm-admin-modal-ai-text-import-loading">
                    <Loader />
                    <p>{ __wprm( 'Importing recipe with AI...' ) }</p>
                </div>
            ) }
            { props.error && (
                <div className="wprm-admin-modal-ai-text-import-error">
                    <p><strong>{ __wprm( 'Error' ) }</strong></p>
                    <p>{ props.error }</p>
                </div>
            ) }
            { hasImportedRecipe && (
                <AIRecipeImportPreview
                    recipe={ props.importedRecipe }
                    imageUrls={ props.imageUrls }
                    selectedImageUrl={ props.selectedImageUrl }
                    onSelectedImageUrlChange={ props.onSelectedImageUrlChange }
                />
            ) }
            { ! props.importing && ! hasImportedRecipe && ! props.error && (
                <p className="wprm-admin-modal-ai-text-import-help">
                    { __wprm( 'Paste recipe text and let AI extract the recipe fields for you. You can review the imported values before applying them.' ) }
                </p>
            ) }
        </div>
    );
};

export const useAIRecipeImport = ( options = {} ) => {
    const initialText = options.initialText || '';
    const autoImport = !! options.autoImport;

    const [ text, setText ] = useState( initialText );
    const [ importing, setImporting ] = useState( false );
    const [ error, setError ] = useState( '' );
    const [ importedRecipe, setImportedRecipe ] = useState( false );
    const [ imageUrls, setImageUrls ] = useState( extractImageUrls( initialText ) );
    const [ selectedImageUrl, setSelectedImageUrl ] = useState( '' );

    const onTextChange = ( value ) => {
        setText( value );
        setError( '' );
        setImportedRecipe( false );
        setImageUrls( extractImageUrls( value ) );
        setSelectedImageUrl( '' );
    };

    const editImportedRecipe = () => {
        setError( '' );
        setImportedRecipe( false );
        setSelectedImageUrl( '' );
    };

    const importRecipe = ( textToImport = text ) => {
        const trimmedText = textToImport.trim();

        if ( ! trimmedText ) {
            setError( __wprm( 'Text is required' ) );
            setImportedRecipe( false );

            return Promise.resolve( false );
        }

        setImporting( true );
        setError( '' );
        setImportedRecipe( false );
        setImageUrls( extractImageUrls( trimmedText ) );
        setSelectedImageUrl( '' );

        return Api.import.aiImportRecipe( trimmedText ).then( ( response ) => {
            if ( response && response.success && response.recipe ) {
                const importedRecipe = normalizeRecipeForModal( normalizeRecipeTags( response.recipe ) );

                setImporting( false );
                setError( '' );
                setImportedRecipe( importedRecipe );

                return importedRecipe;
            }

            setImporting( false );
            setError( response?.error || __wprm( 'Failed to import recipe with AI. Please try again.' ) );
            setImportedRecipe( false );

            return false;
        } ).catch( ( importError ) => {
            console.error( 'Error importing recipe with AI:', importError );

            setImporting( false );
            setError( __wprm( 'An error occurred while importing with AI. Please try again.' ) );
            setImportedRecipe( false );

            return false;
        } );
    };

    useEffect( () => {
        if ( autoImport && initialText ) {
            importRecipe( initialText );
        }
        // Only run once on mount for optional auto-import behavior.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [] );

    return {
        text,
        importing,
        error,
        importedRecipe,
        imageUrls,
        selectedImageUrl,
        onTextChange,
        onSelectedImageUrlChange: setSelectedImageUrl,
        editImportedRecipe,
        importRecipe,
        setError,
    };
};
